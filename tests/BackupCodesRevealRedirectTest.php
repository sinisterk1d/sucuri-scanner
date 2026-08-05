<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once __DIR__ . '/autoload.php';

/**
 * Tests for the post-reveal redirect carried by the backup-codes reveal
 * element (SucuriScanTwoFactor::backup_codes_reveal_snippet).
 *
 * The destination originates as the wp-login.php "redirect_to" request
 * parameter, survives a transient, and is finally handed to
 * window.location.assign() by inc/js/backup-codes.js. Because location.assign()
 * executes "javascript:" URLs in the site's own origin, an unvalidated value
 * reaching the element would be stored XSS rather than merely an open redirect.
 * The login flow validates it once on the way in; these tests pin the second
 * check that keeps the guard next to the sink.
 */
final class BackupCodesRevealRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('wp_strip_all_tags')->returnArg(1);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('6.0');
        Functions\when('get_home_path')->justReturn('/');
        Functions\when('sucuriscan_lastlogins_datastore_exists')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stand-in for wp_validate_redirect() mirroring the two core rules this
     * code depends on: only http/https schemes survive, and only the site's own
     * host survives. Anything else collapses to the fallback.
     *
     * @return void
     */
    private function stubValidateRedirect(): void
    {
        Functions\when('wp_validate_redirect')->alias(function ($location, $fallback = '') {
            $location = (string) $location;

            if ($location === '') {
                return $fallback;
            }

            // Core resolves protocol-relative URLs before parsing them.
            if (strpos($location, '//') === 0) {
                $location = 'http:' . $location;
            }

            $parts = parse_url($location);

            if ($parts === false) {
                return $fallback;
            }

            if (isset($parts['scheme'])
                && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            ) {
                return $fallback;
            }

            if (isset($parts['host']) && strtolower($parts['host']) !== 'example.com') {
                return $fallback;
            }

            return $location;
        });
    }

    /**
     * @param string $redirect_to
     * @return string Rendered snippet HTML.
     */
    private function render(string $redirect_to): string
    {
        $ref = new ReflectionClass(SucuriScanTwoFactor::class);
        $method = $ref->getMethod('backup_codes_reveal_snippet');
        $method->setAccessible(true);

        return (string) $method->invoke(null, array('AAAA-BBBB'), $redirect_to);
    }

    /**
     * Pull data-redirect-url back out of the markup the way the browser does:
     * decode the HTML attribute, then JSON.parse it.
     *
     * @param string $html
     * @return mixed
     */
    private function decodeRedirectAttribute(string $html)
    {
        $this->assertSame(
            1,
            preg_match('/data-redirect-url="([^"]*)"/', $html, $matches),
            'the reveal element must carry exactly one data-redirect-url attribute'
        );

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function hostileRedirectProvider(): array
    {
        return array(
            'javascript scheme' => array('javascript:alert(document.cookie)'),
            'mixed case javascript scheme' => array('JaVaScRiPt:alert(1)'),
            'data scheme' => array('data:text/html,<script>alert(1)</script>'),
            'protocol relative offsite' => array('//evil.example.net/phish'),
            'absolute offsite' => array('https://evil.example.net/phish'),
            'userinfo confusion' => array('https://example.com@evil.example.net/'),
        );
    }

    /**
     * @dataProvider hostileRedirectProvider
     *
     * @param string $redirect_to
     * @return void
     */
    public function testHostileRedirectNeverReachesTheRevealElement(string $redirect_to): void
    {
        $this->stubValidateRedirect();

        $html = $this->render($redirect_to);

        $this->assertSame(
            '',
            $this->decodeRedirectAttribute($html),
            'a rejected destination must render as an empty string so the client skips the redirect'
        );

        // Also assert the raw payload is nowhere in the markup, in case a future
        // change renders it into some other attribute.
        $this->assertStringNotContainsString('evil.example.net', $html);
        $this->assertStringNotContainsString('alert(', $html);
    }

    public function testSameOriginRedirectSurvives(): void
    {
        $this->stubValidateRedirect();

        $html = $this->render('https://example.com/account');

        $this->assertSame('https://example.com/account', $this->decodeRedirectAttribute($html));
    }

    public function testRelativeRedirectSurvives(): void
    {
        $this->stubValidateRedirect();

        $html = $this->render('/wp-admin/profile.php');

        $this->assertSame('/wp-admin/profile.php', $this->decodeRedirectAttribute($html));
    }

    /**
     * The assertions above depend on the stub above behaving like core. This one
     * does not: it fails if the render path stops delegating to
     * wp_validate_redirect() at all, whatever that function happens to return.
     */
    public function testRevealSnippetDelegatesToWpValidateRedirect(): void
    {
        Functions\expect('wp_validate_redirect')
            ->once()
            ->with('javascript:alert(1)', '')
            ->andReturn('');

        $html = $this->render('javascript:alert(1)');

        $this->assertSame('', $this->decodeRedirectAttribute($html));
    }

    /**
     * The server-side guard is one of two; this pins the client-side half, which
     * is the one that does not depend on every future caller of stash_reveal()
     * remembering to validate. Asserted against the source because the plugin
     * ships no JavaScript test runner (same approach as TwoFactorTest).
     */
    public function testRevealScriptResolvesRedirectToASameOriginUrl(): void
    {
        $script = file_get_contents(BASE_DIR . '/inc/js/backup-codes.js');

        $this->assertNotFalse($script);

        // The parsed value must pass through the guard, not go straight to the
        // sink. Matched with a whitespace-tolerant pattern so Prettier is free
        // to reflow the call across lines.
        $this->assertMatchesRegularExpression(
            '/sameOriginURL\(\s*JSON\.parse\(\s*revealData\.dataset\.redirectUrl/',
            $script
        );
        $this->assertStringContainsString('target.origin !== window.location.origin', $script);
        $this->assertStringContainsString('target.protocol !== "http:"', $script);

        // location.assign() must have exactly one call site, so the guard cannot
        // be sidestepped by a second one. Comments are stripped first: the
        // rationale above the guard names the sink, and that mention must not
        // count as a call site.
        $code = preg_replace('~/\*.*?\*/~s', '', $script);

        $this->assertSame(
            1,
            substr_count($code, 'window.location.assign('),
            'window.location.assign() must have a single call site'
        );
    }
}
