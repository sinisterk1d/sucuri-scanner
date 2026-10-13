<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if (!defined('SUCURISCAN_ADMIN_NOTICE_PREFIX')) {
    define('SUCURISCAN_ADMIN_NOTICE_PREFIX', 'Sucuri');
}

/**
 * Plugin dates follow the WordPress timezone unless an override is saved.
 */
final class TimezoneDefaultTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var string */
    private $settingsPath;

    /** @var string */
    private $settingsBackup;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->settingsPath = SUCURI_DATA_STORAGE . '/sucuri-settings.php';
        $this->settingsBackup = (string) file_get_contents($this->settingsPath);

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('wp_rand')->justReturn(123);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('America/New_York'));
    }

    protected function tearDown(): void
    {
        file_put_contents($this->settingsPath, $this->settingsBackup);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedSettings(array $options)
    {
        Functions\when('wp_cache_get')->justReturn(false);
        file_put_contents($this->settingsPath, "<?php exit(0); ?>\n" . json_encode($options) . "\n");
    }

    private function savedTimezone()
    {
        $lines = explode("\n", (string) file_get_contents($this->settingsPath), 2);
        $options = json_decode($lines[1], true);

        return $options['sucuriscan_timezone'];
    }

    /**
     * @dataProvider migrations
     */
    public function testUpgradeMovesOnlyTheOldDefaultToWordPress($from, $saved, $expected)
    {
        $this->seedSettings(array('sucuriscan_plugin_version' => $from, 'sucuriscan_timezone' => $saved));

        ob_start();
        SucuriScanInterface::noticeAfterUpdate();
        ob_end_clean();

        $this->assertSame($expected, $this->savedTimezone());
    }

    public function migrations()
    {
        return array(
            'old default' => array('2.8.1', 'UTC+00.00', 'wordpress'),
            'deliberate override' => array('2.8.1', 'UTC-07.00', 'UTC-07.00'),
            'UTC picked again after the migration' => array('2.8.2', 'UTC+00.00', 'UTC+00.00'),
        );
    }

    /**
     * An hour behind UTC, UTC midnight is the previous local day; the old code
     * got "today" wrong for all but the first hour of the UTC day.
     */
    public function testRelativeFiltersUseTheLocalDate()
    {
        Functions\when('wp_cache_get')->justReturn(array('sucuriscan_timezone' => 'UTC-01.00'));

        $today = SucuriScan::datetime(null, 'Y-m-d');
        $filters = SucuriScanAPI::getFilters();

        $this->assertSame($today, $filters['time']['today']['date']);
        $this->assertSame(gmdate('Y-m-d', strtotime($today . ' -1 day')), $filters['time']['yesterday']['date']);
    }

    public function testCustomRangeMatchesTheLocalDate()
    {
        Functions\when('wp_cache_get')->justReturn(array('sucuriscan_timezone' => 'UTC-12.00'));

        // 2017-12-26 06:00 UTC is still Christmas Day twelve hours behind.
        $res = SucuriScanAPI::filterAuditLogs(
            array('output' => array(SucuriScanAPI::auditLogDatetime(gmmktime(6, 0, 0, 12, 26, 2017)) . ' admin@example.com : Hello')),
            array('time' => 'custom', 'startDate' => '2017-12-25', 'endDate' => '2017-12-25')
        );

        $this->assertCount(1, $res['output_data']);
    }

    /**
     * @dataProvider queueTimestamps
     */
    public function testQueuedEntriesParseBackToTheSameInstant($timestamp)
    {
        Functions\when('wp_cache_get')->justReturn(array('sucuriscan_timezone' => 'UTC+05.50'));

        $res = SucuriScanAPI::filterAuditLogs(array(
            'output' => array(SucuriScanAPI::auditLogDatetime($timestamp) . ' admin@example.com : Hello'),
        ));

        $this->assertSame($timestamp, $res['output_data'][0]['timestamp']);
    }

    public function queueTimestamps()
    {
        return array(
            'winter' => array(gmmktime(12, 16, 0, 1, 15, 2026)),
            'summer' => array(gmmktime(12, 16, 0, 9, 30, 2026)),
        );
    }
}
