<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once __DIR__ . '/autoload.php';

/**
 * Exception used to capture wp_send_json_error()/wp_send_json_success()
 * payloads in tests (mirrors the way WordPress halts execution after
 * emitting JSON).
 */
class BackupRegenSendJsonStop extends \RuntimeException
{
    /** @var bool */
    public $success;

    /** @var mixed */
    public $payload;

    /** @var int|null */
    public $status;

    public function __construct($success, $payload, $status = null)
    {
        parent::__construct('wp_send_json');

        $this->success = (bool) $success;
        $this->payload = $payload;
        $this->status = $status;
    }
}

/**
 * Tests for the authorization boundary on backup-code regeneration
 * (SucuriScanTwoFactor::ajax_profile_backup_regen).
 *
 * Regeneration is deliberately self-only, even for users holding edit_users:
 * the endpoint returns the plaintext codes to the caller, and a backup code is
 * a full substitute for the TOTP second factor at login. An administrator who
 * could regenerate for someone else would obtain usable credentials for that
 * account while the target keeps a working authenticator and therefore has no
 * signal that anything changed.
 */
final class TwoFactorBackupRegenTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private $userMeta = [];

    /** @var array<string, string> */
    private $fixtureSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Regeneration reports an audit event and these tests set the
        // enforcement mode, both of which write to these fixture files on disk;
        // snapshot them so tearDown() restores them and the checked-in fixtures
        // are not left dirty.
        foreach (['sucuri-auditqueue.php', 'sucuri-settings.php'] as $fixture) {
            $path = BASE_DIR . '/tests/fixtures/' . $fixture;

            if (file_exists($path)) {
                $this->fixtureSnapshots[$path] = file_get_contents($path);
            }
        }

        $this->userMeta = [];

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);

        Functions\when('wp_hash_password')->alias(fn($password) => 'HASH:' . $password);

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        // Needed by SucuriScanEvent (audit reporting) and by the template
        // engine's shared params when a snippet is rendered.
        Functions\when('wp_strip_all_tags')->returnArg(1);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('6.0');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('sucuriscan_lastlogins_datastore_exists')->justReturn(true);
        Functions\when('get_home_path')->justReturn('/');

        Functions\when('get_user_meta')->alias(function ($user_id, $key, $single = false) {
            return isset($this->userMeta[$user_id][$key]) ? $this->userMeta[$user_id][$key] : '';
        });

        Functions\when('update_user_meta')->alias(function ($user_id, $key, $value) {
            $this->userMeta[$user_id][$key] = $value;

            return true;
        });

        Functions\when('delete_user_meta')->alias(function ($user_id, $key) {
            unset($this->userMeta[$user_id][$key]);

            return true;
        });

        Functions\when('wp_send_json_error')->alias(function ($payload, $status = null) {
            throw new BackupRegenSendJsonStop(false, $payload, $status);
        });

        Functions\when('wp_send_json_success')->alias(function ($payload, $status = null) {
            throw new BackupRegenSendJsonStop(true, $payload, $status);
        });

        // Two-Factor must be enforced for the target, otherwise
        // resolve_ajax_target_user() bails before the self-only check.
        SucuriScanOption::updateOption(':twofactor_mode', 'all_users');

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];

        unset($GLOBALS['__test_current_user_can']);

        foreach ($this->fixtureSnapshots as $path => $contents) {
            file_put_contents($path, $contents);
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Give a user an existing TOTP secret so regeneration gets past the
     * "Two-Factor must be enabled" precondition.
     *
     * @param int $user_id
     * @return void
     */
    private function enableTotpFor(int $user_id): void
    {
        $this->userMeta[$user_id][SucuriScanTwoFactor::SECRET_META_KEY] = 'JBSWY3DPEHPK3PXP';
    }

    /**
     * @param string $name Protected/static method name.
     * @return ReflectionMethod
     */
    private function method(string $name): ReflectionMethod
    {
        $ref = new ReflectionClass(SucuriScanTwoFactor::class);
        $m = $ref->getMethod($name);
        $m->setAccessible(true);

        return $m;
    }

    public function testRegenDeniedForAnotherUserEvenWithEditUsers(): void
    {
        // An administrator (edit_users granted) targeting somebody else.
        $GLOBALS['__test_current_user_can'] = true;
        Functions\when('get_current_user_id')->justReturn(1);

        $this->enableTotpFor(99);

        $_POST['user_id'] = '99';
        $_POST['nonce'] = 'nonce';

        try {
            SucuriScanTwoFactor::ajax_profile_backup_regen();
            $this->fail('Expected wp_send_json_error to halt execution');
        } catch (BackupRegenSendJsonStop $e) {
            $this->assertFalse($e->success, 'regenerating for another user must not succeed');
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('your own account', $e->payload['message']);
        }

        // Crucially, no codes may be minted for the target as a side effect.
        $this->assertArrayNotHasKey(
            SucuriScanBackupCodes::META_KEY,
            $this->userMeta[99],
            'no backup codes may be generated for a non-self target'
        );
    }

    public function testRegenAllowedForSelf(): void
    {
        $GLOBALS['__test_current_user_can'] = false; // no edit_users needed for self
        Functions\when('get_current_user_id')->justReturn(7);

        $this->enableTotpFor(7);

        $_POST['user_id'] = '7';
        $_POST['nonce'] = 'nonce';

        try {
            SucuriScanTwoFactor::ajax_profile_backup_regen();
            $this->fail('Expected wp_send_json_success to halt execution');
        } catch (BackupRegenSendJsonStop $e) {
            $this->assertTrue($e->success, 'self regeneration must succeed');
            $this->assertCount(SucuriScanBackupCodes::CODE_COUNT, $e->payload['backupCodes']);
        }

        $this->assertArrayHasKey(SucuriScanBackupCodes::META_KEY, $this->userMeta[7]);
    }

    /**
     * resolve_ajax_target_user() casts get_current_user_id() before comparing
     * it to the (int) target, so self-detection does not silently depend on
     * core's return type. Without the cast the strict comparison would go
     * false and this legitimate self-request would be denied.
     */
    public function testSelfDetectionSurvivesNumericStringCurrentUserId(): void
    {
        $GLOBALS['__test_current_user_can'] = false;
        Functions\when('get_current_user_id')->justReturn('7');

        $this->enableTotpFor(7);

        $_POST['user_id'] = '7';
        $_POST['nonce'] = 'nonce';

        try {
            SucuriScanTwoFactor::ajax_profile_backup_regen();
            $this->fail('Expected wp_send_json_success to halt execution');
        } catch (BackupRegenSendJsonStop $e) {
            $this->assertTrue($e->success, 'a numeric-string user id must still resolve as self');
            $this->assertCount(SucuriScanBackupCodes::CODE_COUNT, $e->payload['backupCodes']);
        }
    }

    public function testRegenRequiresAnExistingSecret(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);

        // No secret stored for user 7.
        $_POST['user_id'] = '7';
        $_POST['nonce'] = 'nonce';

        try {
            SucuriScanTwoFactor::ajax_profile_backup_regen();
            $this->fail('Expected wp_send_json_error to halt execution');
        } catch (BackupRegenSendJsonStop $e) {
            $this->assertFalse($e->success);
            $this->assertSame(400, $e->status);
        }
    }

    public function testStatusSnippetOmitsRegenControlForOtherUsers(): void
    {
        $html = $this->method('profile_status_snippet')->invoke(null, 42, false);

        $this->assertNotSame('', $html, 'the status snippet itself must still render');
        // Assert on the markup, not the bare id: the inline script keeps a
        // '#sucuri-2fa-backup-regen-btn' selector either way (it no-ops when
        // the control is absent).
        $this->assertStringNotContainsString('id="sucuri-2fa-backup-regen-btn"', $html);
        $this->assertStringNotContainsString('data-cy="sucuriscan-2fa-backup-regen-btn"', $html);
        $this->assertStringNotContainsString('backup code(s) remaining', $html);
        // The reset control remains available to administrators.
        $this->assertStringContainsString('id="sucuri-2fa-reset-btn"', $html);
    }

    public function testStatusSnippetIncludesRegenControlForSelf(): void
    {
        $html = $this->method('profile_status_snippet')->invoke(null, 42, true);

        $this->assertStringContainsString('id="sucuri-2fa-backup-regen-btn"', $html);
        $this->assertStringContainsString('data-cy="sucuriscan-2fa-backup-regen-btn"', $html);
        $this->assertStringContainsString('backup code(s) remaining', $html);
    }
}
