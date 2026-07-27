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
}
