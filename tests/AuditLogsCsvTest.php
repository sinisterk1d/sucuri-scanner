<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AuditLogsCsvTest extends TestCase
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

        $_GET = array();
        $_SERVER['SERVER_SOFTWARE'] = 'apache';
    }

    protected function tearDown(): void
    {
        $_GET = array();

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke one of the private export helpers.
     *
     * @param string $name Method name.
     * @param array  $args Method arguments.
     * @return mixed Return value of the method.
     */
    private function invoke(string $name, array $args = array())
    {
        $method = (new ReflectionClass('SucuriScanAuditLogs'))->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $args);
    }

    /**
     * Build the CSV export from the audit queue fixture.
     *
     * @return string Generated CSV.
     */
    private function csv(): string
    {
        return (string) $this->invoke('getAuditLogsCsv');
    }

    /**
     * Split a CSV into rows of fields.
     *
     * @param string $csv Generated CSV.
     * @return array Parsed rows.
     */
    private function rows(string $csv): array
    {
        $rows = array();

        foreach (preg_split('/\r\n/', trim($csv)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    /**
     * Number of audit trails held in the local queue fixture.
     *
     * @return int Stored audit trail count.
     */
    private function storedAuditTrailCount(): int
    {
        $auditlogs = SucuriScanAPI::getAuditLogsFromQueue();

        return count((array) $auditlogs['output_data']);
    }

    public function testExportsEveryStoredAuditTrail()
    {
        $rows = $this->rows($this->csv());

        /* one header row plus every audit trail in the local queue */
        $this->assertCount($this->storedAuditTrailCount() + 1, $rows);
        $this->assertGreaterThan(1, count($rows));
    }

    public function testNeverLeaksTheDatastorePhpHeader()
    {
        $csv = $this->csv();

        /**
         * The queue is a PHP file that stops its own execution when requested
         * directly. None of that preamble is data, so none of it belongs in
         * the export.
         */
        $this->assertStringNotContainsString('<?php', $csv);
        $this->assertStringNotContainsString('?>', $csv);
        $this->assertStringNotContainsString('exit(0)', $csv);
        $this->assertStringNotContainsString('datastore=', $csv);
        $this->assertStringNotContainsString('created_on=', $csv);
    }

    public function testWritesTheExpectedHeaderRow()
    {
        $rows = $this->rows($this->csv());

        $this->assertSame(
            array('Date', 'Time', 'Severity', 'Username', 'IP Address', 'Message', 'Details'),
            $rows[0]
        );
    }

    public function testEveryRowHasTheSameColumnCountAsTheHeader()
    {
        $rows = $this->rows($this->csv());

        foreach ($rows as $row) {
            $this->assertCount(7, $row);
        }
    }

    public function testExportsPopulatedFieldsForEveryTrail()
    {
        $rows = $this->rows($this->csv());
        array_shift($rows); /* drop the header */

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $row[0]);
            $this->assertMatchesRegularExpression('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/', $row[1]);
            $this->assertNotSame('', $row[2]);
            $this->assertNotSame('', $row[5]);
        }
    }

    public function testIgnoresTheFiltersAppliedOnTheAuditTrailPage()
    {
        $unfiltered = $this->rows($this->csv());

        /* the queue fixture holds logs from 2017, so this filter matches none */
        $_GET['time'] = 'today';
        $_GET['events'] = 'critical';
        $_GET['search'] = 'no audit trail contains this';
        $_GET['plugins'] = 'deleted';

        $this->assertSame($unfiltered, $this->rows($this->csv()));
    }

    public function testExportsMessagesAndDetailsVerbatim()
    {
        $rows = $this->rows($this->invoke('auditLogCsvRow', array(
            array(
                '2026-07-15',
                '10:30:00',
                'warning',
                'admin',
                '192.0.2.1',
                'Plugin updated: Example, version 2',
                implode(";\x20", array('example.php', 'readme.txt')),
            ),
        )));

        $this->assertSame(
            array(
                '2026-07-15',
                '10:30:00',
                'warning',
                'admin',
                '192.0.2.1',
                'Plugin updated: Example, version 2',
                'example.php; readme.txt',
            ),
            $rows[0]
        );
    }

    public function testQuotesCommasQuotesAndNewlines()
    {
        $row = $this->invoke('auditLogCsvRow', array(
            array('a,b', 'say "hi"', "line1\nline2", '', '', '', ''),
        ));

        $this->assertStringStartsWith('"a,b","say ""hi""","line1' . "\n" . 'line2"', $row);
        $this->assertStringEndsWith("\r\n", $row);

        $fields = str_getcsv(rtrim($row, "\r\n"), ',', '"', '');

        $this->assertCount(7, $fields);
        $this->assertSame('a,b', $fields[0]);
        $this->assertSame('say "hi"', $fields[1]);
        $this->assertSame("line1\nline2", $fields[2]);
    }

    public function testExportsTrailingBackslashesWithoutCorruptingColumns()
    {
        $row = $this->invoke('auditLogCsvRow', array(
            array('2026-07-15', '10:30:00', 'notice', 'admin\\', '192.0.2.1', 'Windows path C:\\', ''),
        ));
        $fields = str_getcsv(rtrim($row, "\r\n"), ',', '"', '');

        $this->assertCount(7, $fields);
        $this->assertSame('admin\\', $fields[3]);
        $this->assertSame('Windows path C:\\', $fields[5]);
    }

    public function testPreventsSpreadsheetFormulaInjection()
    {
        $row = $this->invoke('auditLogCsvRow', array(
            array(
                '=HYPERLINK("https://example.com")',
                '+1',
                "\t@SUM(1+1)",
                '-dangerous.csv',
                '|cmd',
                '%payload',
                'harmless',
            ),
        ));
        $fields = str_getcsv(rtrim($row, "\r\n"), ',', '"', '');

        $this->assertSame('\'=HYPERLINK("https://example.com")', $fields[0]);
        $this->assertSame("'+1", $fields[1]);
        $this->assertSame("'\t@SUM(1+1)", $fields[2]);
        $this->assertSame("'-dangerous.csv", $fields[3]);
        $this->assertSame("'|cmd", $fields[4]);
        $this->assertSame("'%payload", $fields[5]);
        $this->assertSame('harmless', $fields[6]);
    }

    public function testRendersTheDownloadLinkOnTheAuditTrailPage()
    {
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });
        Functions\when('get_site_url')->justReturn('https://example.com');

        $html = SucuriScanAuditLogs::pageAuditLogs();

        $this->assertStringNotContainsString('%%SUCURI.AuditLogs.DownloadURL%%', $html);
        $this->assertMatchesRegularExpression(
            '/<a id="download-auditlogs-link" href="[^"]*admin-post\.php\?'
            . 'action=sucuriscan_download_audit_logs&amp;sucuriscan_page_nonce=[a-z0-9]+"/',
            $html
        );
    }

    public function testRejectsARequestWithoutANonce()
    {
        Functions\when('wp_verify_nonce')->justReturn(true);

        $this->assertFalse($this->invoke('canDownloadAuditLogs'));
    }

    public function testRejectsARequestWithAnInvalidNonce()
    {
        $_GET['sucuriscan_page_nonce'] = 'aaaaaaaaaa';

        Functions\when('wp_verify_nonce')->justReturn(false);

        $this->assertFalse($this->invoke('canDownloadAuditLogs'));
    }

    public function testAcceptsARequestWithAValidNonce()
    {
        $_GET['sucuriscan_page_nonce'] = 'aaaaaaaaaa';

        Functions\when('wp_verify_nonce')->justReturn(true);

        $this->assertTrue($this->invoke('canDownloadAuditLogs'));
    }
}
