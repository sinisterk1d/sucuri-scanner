<?php declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if (!defined('SUCURISCAN_ADMIN_NOTICE_PREFIX')) {
    define('SUCURISCAN_ADMIN_NOTICE_PREFIX', 'Sucuri');
}

final class ApiErrorEscapingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->alias(function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });
        Functions\when('wp_rand')->justReturn(123);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testRemoteStringErrorIsEscapedBeforeAdminNoticeRendering()
    {
        ob_start();
        SucuriScanAPI::handleResponse('<img src=x onerror=alert(1)>');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $output);
    }
}
