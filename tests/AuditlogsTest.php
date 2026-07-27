<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AuditlogsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_home_path')->justReturn(__DIR__);
        Functions\when('__')->returnArg();
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);

        $_SERVER['SERVER_SOFTWARE'] = 'apache';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testTimeAuditLogFiltering()
    {
        $filters = array(
            'time' => 'today'
        );

        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue($filters);

        $this->assertEquals(0, count($auditlogs['output_data']));

        // test custom date
        $filters = array(
            'time' => 'custom',
            'startDate' => '2017-12-26',
            'endDate' => '2017-12-26',
            'plugins' => 'activated'
        );

        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue($filters);

        $this->assertEquals(2, count($auditlogs['output_data']));

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertStringContainsString('Plugin activated', $log['message']);
        }
    }

    public function testPostAuditLogFiltering()
    {
        $filters = array(
            'posts' => 'updated'
        );

        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue($filters);

        $this->assertEquals(1, count($auditlogs['output_data']));

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertStringContainsString('Post was updated', $log['message']);
        }
    }

    public function testUserAuditLogFiltering()
    {
        $filters = array(
            'users' => 'deleted'
        );

        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue($filters);

        $this->assertEquals(1, count($auditlogs['output_data']));

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertStringContainsString('User account deleted', $log['message']);
        }
    }

    public function testSearchesAuditLogFieldsCaseInsensitively()
    {
        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue(array(
            'search' => 'activity log',
        ));

        $this->assertCount(1, $auditlogs['output_data']);
        $this->assertStringContainsString('Activity Log', $auditlogs['output_data'][0]['message']);

        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue(array(
            'search' => '1.2.3.4',
        ));

        $this->assertNotEmpty($auditlogs['output_data']);

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertSame('1.2.3.4', $log['remote_addr']);
        }
    }

    public function testSearchNarrowsCategoryFilters()
    {
        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue(array(
            'plugins' => 'activated',
            'search' => 'activity log',
        ));

        $this->assertCount(1, $auditlogs['output_data']);
        $this->assertStringContainsString('Plugin activated', $auditlogs['output_data'][0]['message']);
    }

    public function testMultipleActivityCategoriesUseOrSemantics()
    {
        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue(array(
            'posts' => 'deleted',
            'plugins' => 'activated',
        ));

        $this->assertCount(3, $auditlogs['output_data']);

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertStringContainsString('Plugin activated', $log['message']);
        }
    }

    public function testFiltersBySeverity()
    {
        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue(array(
            'events' => 'warning',
        ));

        $this->assertNotEmpty($auditlogs['output_data']);

        foreach ($auditlogs['output_data'] as $log) {
            $this->assertSame('warning', $log['event']);
        }
    }

    public function testRedactsLegacyPasswordsBeforeFilteringOrExport()
    {
        $auditlogs = SucuriScanAPI::filterAuditLogs(array(
            'output' => array(
                '2026-07-15 10:30:00 admin@example.com : Error: admin, 192.0.2.1; User authentication failed: admin; password: secret',
            ),
            'total_entries' => 1,
        ));

        $this->assertCount(1, $auditlogs['output_data']);
        $this->assertSame(
            'User authentication failed: admin',
            $auditlogs['output_data'][0]['message']
        );
    }
}
