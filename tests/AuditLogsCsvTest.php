<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AuditLogsCsvTest extends TestCase
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

        Functions\when('get_home_path')->justReturn(__DIR__);
        Functions\when('wp_strip_all_tags')->alias('sucuriscan_test_strip_all_tags');
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

        /* the queue is a shared fixture; every append has to be rolled back */
        file_put_contents($this->queuePath, $this->queueBackup);

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
     * Driven through the same scratch stream downloadAuditLogs() writes into,
     * so the export is exercised as it actually runs.
     *
     * @return string Generated CSV.
     */
    private function csv(): string
    {
        $stream = $this->invoke('openCsvBuffer');

        $this->assertIsResource($stream, 'the CSV scratch stream could not be opened');
        $this->assertTrue(
            (bool) $this->invoke('writeAuditLogsCsv', array($stream)),
            'the audit queue could not be read'
        );

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
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
     * Audit trails held in the local queue fixture, read straight from disk.
     *
     * Deliberately not routed through SucuriScanAPI::getAuditLogsFromQueue():
     * an expectation taken from the same call the export makes could not
     * detect a fault in that call.
     *
     * @return array Stored messages, one entry per record line.
     */
    private function storedAuditTrails(): array
    {
        $records = array();

        foreach (file($this->queuePath) as $line) {
            if (!preg_match('/^([0-9]+_[0-9]+):(.*)$/', trim($line), $parts)) {
                continue; /* header line, not a record */
            }

            $message = json_decode($parts[2], true);

            if (is_string($message)) {
                /**
                 * A list, not a map: the export reads the queue line by line,
                 * so two records sharing a datastore key are two rows. The
                 * audit page collapses them, because the keyed reader it uses
                 * builds a map -- but matching that would mean holding every
                 * key in memory, which is the cost the streaming export exists
                 * to avoid.
                 */
                $records[] = $parts[1] . ' ' . $message;
            }
        }

        return $records;
    }

    public function testExportsEveryStoredAuditTrail()
    {
        $csv = $this->csv();

        foreach ($this->storedAuditTrails() as $record) {
            /* the key was prefixed for the failure message; drop it again */
            $message = substr($record, strpos($record, ' ') + 1);
            $key = strtok($record, ' ');

            /* "Severity: user, ip; " is split into its own columns */
            $body = substr($message, strpos($message, ';') + 2);

            /**
             * A "... has been changed" entry is reshaped on read: the detail
             * list moves into its own column, so compare on the part before it.
             */
            $probe = strtok($body, ';');

            $this->assertStringContainsString(
                $probe,
                $csv,
                sprintf('audit trail %s is missing from the export', $key)
            );
        }
    }

    public function testExportsRowsInTheOrderTheyWereRecorded()
    {
        /**
         * The audit page sorts newest first, but sorting means holding every
         * record at once. The export streams instead, so rows come out in the
         * order the queue holds them -- which on a real site, where every
         * append carries the microtime of the event, is chronological.
         */
        $rows = $this->rows($this->csv());
        array_shift($rows); /* drop the header */

        $stored = $this->storedAuditTrails();

        $this->assertGreaterThan(1, count($stored));
        $this->assertCount(count($stored), $rows);

        foreach ($stored as $index => $record) {
            $message = substr($record, strpos($record, ' ') + 1);
            $body = substr($message, strpos($message, ';') + 2);
            $probe = strtok($body, ';');

            $this->assertStringContainsString(
                $probe,
                $rows[$index][5],
                sprintf('row %d does not hold the %dth stored audit trail', $index, $index)
            );
        }
    }

    public function testExportsOneRowPerStoredAuditTrail()
    {
        $rows = $this->rows($this->csv());

        $this->assertGreaterThan(1, count($rows));
        $this->assertCount(count($this->storedAuditTrails()) + 1, $rows);
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

    public function testExportsNamesAsTheTextTheEventDescribed()
    {
        /**
         * Hooks build messages out of values passed through
         * SucuriScan::escape(), so a plugin named "R&D <Tools>" reaches the
         * queue entity-encoded. A CSV is data, not markup: the reader has to
         * get the characters back, not the entities.
         */
        $this->storeAuditTrail(
            'Warning: admin, 1.2.3.4; Plugin activated: R&amp;D &lt;Tools&gt; (v1.0; rd/rd.php)'
        );

        $csv = $this->csv();

        $this->assertStringContainsString('Plugin activated: R&D <Tools>', $csv);
        $this->assertStringNotContainsString('&amp;', $csv);
        $this->assertStringNotContainsString('&lt;', $csv);
    }

    public function testExportsDetailColumnsAsPlainTextToo()
    {
        $this->storeAuditTrail(
            'Notice: admin, 1.2.3.4; Post status has been changed; details: ID: 3,Title: Ben &amp; Jerry'
        );

        $csv = $this->csv();

        $this->assertStringContainsString('Ben & Jerry', $csv);
        $this->assertStringNotContainsString('&amp;', $csv);
    }

    public function testNeverExportsStoredCredentials()
    {
        /**
         * The export is the one path that takes audit trails off the site, so
         * the redaction applied when logs are read back is asserted here too.
         */
        $this->storeAuditTrail('Warning: admin, 1.2.3.4; Mailer saved; smtp_password: hunter2');

        $csv = $this->csv();

        $this->assertStringNotContainsString('hunter2', $csv);
        $this->assertStringContainsString('smtp_password: [redacted]', $csv);
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
