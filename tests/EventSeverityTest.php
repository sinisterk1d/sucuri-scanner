<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the severity prefix that reportEvent() commits to the audit trail.
 *
 * The stored line is a machine readable record: parseAuditLogs() reads the
 * prefix back and drops any entry whose level it does not recognise, so the
 * assertions below are on the stored bytes rather than on a return value.
 */
final class EventSeverityTest extends TestCase
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

        /* every severity name is translated as if the site ran in Spanish */
        Functions\when('__')->alias(function ($text) {
            $catalog = array(
                'Debug' => 'Depuracion',
                'Notice' => 'Aviso',
                'Info' => 'Informacion',
                'Warning' => 'Advertencia',
                'Error' => 'Fallo',
                'Critical' => 'Critico',
            );

            return isset($catalog[$text]) ? $catalog[$text] : $text;
        });

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
     * Report an event and return the entry as it was written to the queue.
     *
     * @param string $message Message handed to the reporting API.
     * @return string Stored audit entry.
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

    public function testStoresTheSeverityUntranslated()
    {
        $stored = $this->report('Plugin activated: Example');

        $this->assertStringStartsWith('Warning:', $stored);
        $this->assertStringNotContainsString('Advertencia', $stored);
    }

    public function testStoredSeverityIsReadBackByTheLogParser()
    {
        /**
         * A translated prefix makes parseAuditLogs() discard the entry as an
         * unknown event type, so the trail written by a non-English site would
         * be invisible on the audit page and absent from the CSV export.
         */
        $stored = $this->report('Plugin activated: Example');
        $auditlogs = SucuriScanAPI::filterAuditLogs(
            array(
                'output' => array(
                    '2026-07-15 10:30:00 admin@example.com : ' . $stored,
                ),
                'total_entries' => 1,
            )
        );

        $this->assertCount(1, $auditlogs['output_data']);
        $this->assertSame('warning', $auditlogs['output_data'][0]['event']);
    }
}
