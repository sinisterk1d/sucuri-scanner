<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Exercises what reportEvent() actually commits to the local audit trail.
 *
 * Everything the CSV export ships is read back out of this file, so the
 * assertions below are on the stored bytes rather than on a return value.
 */
final class EventRedactionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var string */
    private $queuePath;

    /** @var string */
    private $queueBackup;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->queuePath = SUCURI_DATA_STORAGE . '/sucuri-auditqueue.php';
        $this->queueBackup = (string) file_get_contents($this->queuePath);

        Functions\when('__')->returnArg();
        Functions\when('get_home_path')->justReturn(__DIR__);

        /**
         * createStorageFolder() calls this one unguarded. It has to be defined
         * through Brain\Monkey rather than at file scope: PHPUnit loads every
         * test file before Patchwork installs itself, and a plain declaration
         * here makes the function un-redefinable for the rest of the suite.
         */
        Functions\when('sucuriscan_lastlogins_datastore_exists')->justReturn(true);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_site_url')->justReturn('https://example.com');

        /* faithful copy of the core helper; it is not part of the test stubs */
        Functions\when('wp_strip_all_tags')->alias('sucuriscan_test_strip_all_tags');
    }

    protected function tearDown(): void
    {
        /* the queue is a shared fixture; every append has to be rolled back */
        file_put_contents($this->queuePath, $this->queueBackup);

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param string $message Message handed to the reporting API.
     * @return string The audit entry as it was written to the queue file.
     */
    private function report(string $message): string
    {
        $before = filesize($this->queuePath);

        $this->assertTrue(SucuriScanEvent::reportWarningEvent($message));

        clearstatcache(true, $this->queuePath);
        $handle = fopen($this->queuePath, 'r');
        fseek($handle, (int) $before);
        $appended = trim((string) stream_get_contents($handle));
        fclose($handle);

        $this->assertNotSame('', $appended, 'nothing was appended to the audit queue');

        return (string) json_decode(substr($appended, strpos($appended, ':') + 1), true);
    }

    public function testCredentialsHiddenBehindMarkupAreStillRedacted()
    {
        /**
         * Markup can split a key name so the pattern never sees "password".
         * Two layers stop it reaching storage, and this asserts the outcome
         * rather than either layer: reportEvent() strips tags before it
         * redacts, and sendLogToQueue() redacts once more after. Removing
         * either one alone still passes -- the assertion is the invariant, not
         * a pin on the ordering.
         */
        $stored = $this->report('Settings saved; <b>pass</b>word: hunter2');

        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertStringContainsString('password: [redacted]', $stored);
    }

    public function testMarkupRevealedByRedactionIsNotStored()
    {
        /**
         * This is the case that pins the second strip in reportEvent().
         * Redaction matches against an entity-decoded copy and returns that
         * copy on a hit, so an escaped payload turns back into live markup on
         * its way to storage; stripping only before the redaction leaves
         * "<script>alert(1)</script>" in the trail and in every CSV export of
         * it.
         */
        $escaped = htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8');
        $stored = $this->report($escaped . '; password: hunter2');

        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('password: [redacted]', $stored);
    }

    public function testMessagesWithoutCredentialsKeepTheirEscaping()
    {
        /**
         * Nothing matched, so the value must be stored exactly as the caller
         * escaped it rather than as the decoded copy used for matching.
         */
        $escaped = htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8');
        $stored = $this->report($escaped . '; ID: 1');

        $this->assertStringContainsString($escaped, $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function testKeepsEveryOtherFieldChangedInTheSameSave()
    {
        /**
         * hookOptionsChanges() joins every option changed in one settings save
         * with a comma, and WordPress' own Writing page carries mailserver_pass.
         * The mask has to stop at the field boundary: swallowing to the end of
         * the value deleted the record of everything that changed after the
         * credential, which is audit history that cannot be recovered.
         */
        $stored = $this->report(
            "Writing settings changed: (multiple entries): "
            . "mailserver_url: from 'mail.a.com' to 'smtp.a.com',"
            . "mailserver_pass: from 'oldsecret' to 'newsecret',"
            . "default_post_format: from '0' to 'aside'"
        );

        $this->assertStringNotContainsString('oldsecret', $stored);
        $this->assertStringNotContainsString('newsecret', $stored);
        $this->assertStringContainsString('mailserver_pass: [redacted]', $stored);
        $this->assertStringContainsString("mailserver_url: from 'mail.a.com' to 'smtp.a.com'", $stored);
        $this->assertStringContainsString("default_post_format: from '0' to 'aside'", $stored);
    }

    public function testKeepsValuesContainingAnAngleBracket()
    {
        /**
         * strip_tags() deletes from "<" to the end of the string when nothing
         * closes the tag, so masking used to truncate the entry at the bracket
         * and take the credential mask that followed with it -- the record no
         * longer showed that a password had changed at all.
         */
        $stored = $this->report(
            "Global settings changed: (multiple entries): "
            . "blogdescription: from 'a' to 'x<y',"
            . "mailserver_pass: from 'p' to 'q'"
        );

        $this->assertStringNotContainsString("'p'", $stored);
        $this->assertStringContainsString('mailserver_pass: [redacted]', $stored);

        /* the bracket survives as text; it is escaped, never deleted */
        $this->assertStringContainsString('x&lt;y', $stored);
    }

    public function testKeepsAValueContainingAnAngleBracketWithoutAnyCredential()
    {
        /**
         * The same truncation, on the path where nothing is redacted at all.
         * This one predates the redaction: reportEvent() has always run the
         * message through strip_tags().
         */
        $stored = $this->report("Post was updated; ID: 1; name: Tips <3 for you");

        $this->assertStringContainsString('Tips &lt;3 for you', $stored);
    }

    public function testStillStripsRealMarkup()
    {
        $stored = $this->report('Settings saved; <b>bold</b> and <i>italic</i>');

        $this->assertStringNotContainsString('<b>', $stored);
        $this->assertStringNotContainsString('<i>', $stored);
        $this->assertStringContainsString('bold', $stored);
        $this->assertStringContainsString('italic', $stored);
    }

    public function testKeepsTheShapeOfAQuotedCredentialField()
    {
        /**
         * The quoted pattern masks between the quotes and leaves them in place.
         * The unquoted pattern used to re-match the result -- backtracking its
         * leading "\s*" to nothing so a space satisfied the "not a quote"
         * guard -- and strip both the quotes and the space back off.
         */
        $stored = $this->report('Config saved; "password": "s3cr3t"; other: keep');

        $this->assertStringNotContainsString('s3cr3t', $stored);
        $this->assertStringContainsString('"password": "[redacted]"', $stored);
        $this->assertStringContainsString('other: keep', $stored);
    }

    public function testMasksACredentialHiddenBehindItsEncoding()
    {
        /**
         * Called directly rather than through reportEvent(), because this is
         * the one branch where the decoded copy is the only thing that saw the
         * credential: backslash escaping inside a JSON payload hides the quotes
         * the pattern keys on. The result is escaped on the way out, since
         * decoding can turn stored entities back into live markup.
         */
        $redacted = SucuriScanEvent::redactSensitiveData(
            '{"user":"admin","password\\": \\"s3cr3t\\""}'
        );

        $this->assertStringNotContainsString('s3cr3t', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
        $this->assertStringNotContainsString('"', $redacted);
    }

    public function testMasksAValueThatItselfContainsAComma()
    {
        /**
         * Only a comma that introduces another "name:" pair ends the match, so
         * a credential containing a comma is still masked in full.
         */
        $stored = $this->report('Connector saved; api_key: abc,def,ghi');

        $this->assertStringNotContainsString('abc', $stored);
        $this->assertStringNotContainsString('def', $stored);
        $this->assertStringNotContainsString('ghi', $stored);
        $this->assertStringContainsString('api_key: [redacted]', $stored);
    }
}
