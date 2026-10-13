<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Successful logins are stored in site-local time, failed logins as a UTC
 * timestamp; both must come out of the Last Logins page as the same instant.
 */
final class LastLoginsTimezoneTest extends TestCase
{
    /** @var string */
    private $datastore;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Loading the file registers hooks and reads a plugin option.
        Functions\when('wp_cache_get')->justReturn(array('sucuriscan_lastlogin_redirection' => 'disabled'));
        require_once BASE_DIR . '/src/lastlogins.php';

        // Declared here, not at file level, so it never clashes with the
        // richer double in TwoFactorUsersListTest.
        if (!class_exists('WP_User_Query')) {
            class_alias(get_class(new class {
                public function get_results()
                {
                    return array();
                }
            }), 'WP_User_Query');
        }

        // Mirrors core: read the date in the site timezone, return it in UTC.
        Functions\when('get_gmt_from_date')->alias(function ($date, $format) {
            $local = date_create($date, new DateTimeZone('America/New_York'));
            return $local->setTimezone(new DateTimeZone('UTC'))->format($format);
        });

        $this->datastore = sucuriscan_lastlogins_datastore_filepath();
    }

    protected function tearDown(): void
    {
        @unlink($this->datastore);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testLocalLoginTimeBecomesUtcTimestamp()
    {
        // 8:16 am EDT, the customer's successful login.
        file_put_contents($this->datastore, "<?php exit(0); ?>\n" . json_encode(array(
            'user_id' => 1,
            'user_login' => 'admin',
            'user_remoteaddr' => '127.0.0.1',
            'user_hostname' => 'localhost',
            'user_lastlogin' => '2026-09-30 08:16:00',
        )) . "\n");

        $logins = sucuriscan_get_logins(10);
        $entry = end($logins['entries']);

        // 12:16 UTC, the same clock a failed login's time() is on.
        $this->assertSame(gmmktime(12, 16, 0, 9, 30, 2026), $entry['user_lastlogin_timestamp']);
    }
}
