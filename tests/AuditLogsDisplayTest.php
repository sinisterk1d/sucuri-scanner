<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Exercises what the audit trail page renders for a stored entry.
 *
 * Hooks store their messages entity-encoded, so the page and the CSV export
 * have to agree on decoding them exactly once.
 */
final class AuditLogsDisplayTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array|null */
    public $sent;

    /** @var string */
    private $queuePath;

    /** @var string */
    private $queueBackup;

    /** @var string */
    private $settingsPath;

    /** @var string */
    private $settingsBackup;

    /** @var array */
    private $postBackup;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $self = $this;
        $this->sent = null;
        $this->postBackup = $_POST;
        /**
         * Rendering the page reports events and caches its response, so both
         * of these tracked fixtures are written to. Without a restore the suite
         * leaves the working tree dirty and the drift gets committed by
         * accident.
         */
        $this->queuePath = SUCURI_DATA_STORAGE . '/sucuri-auditqueue.php';
        $this->queueBackup = (string) file_get_contents($this->queuePath);
        $this->settingsPath = SUCURI_DATA_STORAGE . '/sucuri-settings.php';
        $this->settingsBackup = (string) file_get_contents($this->settingsPath);

        $_POST = array('form_action' => 'get_audit_logs');
        $_GET = array();
        $_SERVER['SERVER_SOFTWARE'] = 'apache';

        Functions\when('__')->returnArg();
        Functions\when('get_home_path')->justReturn(__DIR__);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('sucuriscan_lastlogins_datastore_exists')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_strip_all_tags')->alias('sucuriscan_test_strip_all_tags');
        Functions\when('esc_html')->alias(function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });

        /* capture the payload instead of emitting it and calling die() */
        Functions\when('wp_send_json')->alias(function ($data, $status = null) use ($self) {
            $self->sent = array('data' => $data, 'status' => $status);
        });
    }

    protected function tearDown(): void
    {
        file_put_contents($this->queuePath, $this->queueBackup);
        file_put_contents($this->settingsPath, $this->settingsBackup);

        /* the response cache is created by the render, not part of the fixtures */
        $cache = SUCURI_DATA_STORAGE . '/sucuri-auditlogs.php';

        if (file_exists($cache)) {
            unlink($cache);
        }

        $_POST = $this->postBackup;
        $_GET = array();

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Append one raw record to the local audit queue.
     *
     * @param string $message Audit message to store.
     * @return void
     */
    private function storeAuditTrail(string $message): void
    {
        file_put_contents(
            $this->queuePath,
            sprintf("9999999999_0000:%s\n", json_encode($message)),
            FILE_APPEND
        );
    }

    public function testRendersStoredNamesAsTheTextTheEventDescribed()
    {
        /**
         * "R&D <Tools>" is stored as "R&amp;D &lt;Tools&gt;" by the hook. The
         * template escapes whatever it is handed, so passing the stored value
         * straight through rendered the entities themselves back to the reader
         * as "R&amp;amp;D".
         */
        $this->storeAuditTrail(
            'Warning: admin, 1.2.3.4; Plugin activated: R&amp;D &lt;Tools&gt; (v1.0; rd/rd.php)'
        );

        SucuriScanAuditLogs::ajaxAuditLogs();
        $content = $this->sent['data']['content'];

        /* escaped exactly once: the browser shows "R&D <Tools>" */
        $this->assertStringContainsString('R&amp;D &lt;Tools&gt;', $content);
        $this->assertStringNotContainsString('&amp;amp;', $content);
        $this->assertStringNotContainsString('&amp;lt;', $content);
    }

    public function testRendersDetailEntriesAsPlainTextToo()
    {
        $this->storeAuditTrail(
            'Notice: admin, 1.2.3.4; Post status has been changed; details: ID: 3,Title: Ben &amp; Jerry'
        );

        SucuriScanAuditLogs::ajaxAuditLogs();
        $content = $this->sent['data']['content'];

        $this->assertStringContainsString('Ben &amp; Jerry', $content);
        $this->assertStringNotContainsString('&amp;amp;', $content);
    }

    public function testStillEscapesMarkupThatWasNeverEncoded()
    {
        /**
         * Decoding must not become a way to smuggle live markup onto the page:
         * a record holding a raw tag still has to reach the browser escaped.
         */
        $this->storeAuditTrail(
            'Warning: admin, 1.2.3.4; Plugin activated: <script>alert(1)</script> (v1.0; x/x.php)'
        );

        SucuriScanAuditLogs::ajaxAuditLogs();
        $content = $this->sent['data']['content'];

        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }
}
