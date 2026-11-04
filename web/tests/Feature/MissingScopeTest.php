<?php

namespace Tests\Feature;

use App\Lib\ScopeCheck;
use App\Models\ScriptSrc;
use App\Models\Session;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopify\Context;
use Tests\TestCase;

class MissingScopeTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'old-install.myshopify.com';
    private const SCRIPT_URL = '//cdn.cookie-script.com/s/0123456789abcdef0123456789abcdef.js';
    private const APPROVAL_ERROR = '{"errors":"[API] This action requires merchant approval for read_themes scope."}';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function storeOfflineSession(string $scope, string $shop = self::SHOP): Session
    {
        $session = new Session();
        $session->session_id = "offline_$shop";
        $session->shop = $shop;
        $session->is_online = false;
        $session->state = 'state';
        $session->scope = $scope;
        $session->access_token = 'shpat_current';
        // A valid expiring token, so OfflineTokenManager doesn't call Shopify
        $session->refresh_token = 'shprt_current';
        $session->access_token_expires_at = Carbon::now()->addHour()->toDateTimeString();
        $session->save();

        return $session;
    }

    private function bearer(string $shop = self::SHOP): array
    {
        $now = time();
        $token = JWT::encode([
            'iss' => "https://$shop/admin",
            'dest' => "https://$shop",
            'aud' => Context::$API_KEY,
            'sub' => '1',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
            'jti' => uniqid(),
            'sid' => 'sid',
        ], Context::$API_SECRET_KEY, 'HS256');

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    private function openAppUrl(string $shop = self::SHOP): string
    {
        return '/?embedded=1&shop=' . $shop . '&host=' . base64_encode("admin.shopify.com/store/old-install");
    }

    /** Any Shopify request the test didn't expect fails with a 599, so the test can't pass by accident */
    private static function withStrayGuard(array $fakes): array
    {
        return $fakes + ['*' => Http::response('{"errors":"unexpected request in test"}', 599)];
    }

    // ---- Opening the app -------------------------------------------------------------------------------------------

    public function test_opening_the_app_with_missing_theme_scopes_sends_the_merchant_to_oauth(): void
    {
        $this->storeOfflineSession('write_script_tags');

        $response = $this->get($this->openAppUrl());

        $response->assertRedirect();
        $this->assertStringContainsString('/ExitIframe?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        // ExitIframe.jsx decodes redirectUri once more before redirecting to it
        $this->assertSame('https://app-host-name.com/api/auth?shop=' . self::SHOP, urldecode($query['redirectUri']));
    }

    public function test_opening_the_app_again_within_the_cooldown_does_not_loop(): void
    {
        $this->storeOfflineSession('write_script_tags');

        $this->get($this->openAppUrl())->assertRedirect();
        $second = $this->get($this->openAppUrl());

        $second->assertOk();
        $this->assertStringContainsString('<div id="app">', $second->getContent());
    }

    public function test_opening_the_app_with_all_scopes_loads_it(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes');

        $this->get($this->openAppUrl())->assertOk();
    }

    public function test_opening_the_app_without_a_session_still_starts_oauth(): void
    {
        $response = $this->get($this->openAppUrl());

        $response->assertRedirect();
        $this->assertStringContainsString('/ExitIframe?', $response->headers->get('Location'));
    }

    // ---- Adding a script -------------------------------------------------------------------------------------------

    public function test_adding_a_script_without_theme_approval_asks_for_reauthorization(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes'); // stored scope may be wrong/outdated
        Http::fake(self::withStrayGuard(['*/themes.json' => Http::response(self::APPROVAL_ERROR, 403)]));

        $response = $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer());

        $response->assertStatus(401);
        $response->assertHeader('X-Shopify-API-Request-Failure-Reauthorize', '1');
        $response->assertHeader('X-Shopify-API-Request-Failure-Reauthorize-Url', '/api/auth?shop=' . self::SHOP);
        $this->assertSame(0, ScriptSrc::count(), 'the script must be rolled back');
        $this->assertSame('shpat_current', Session::first()->access_token, 'the token must not be cleared');
    }

    public function test_adding_a_script_again_within_the_cooldown_shows_a_clear_error(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes');
        Http::fake(self::withStrayGuard(['*/themes.json' => Http::response(self::APPROVAL_ERROR, 403)]));

        $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer())->assertStatus(401);
        $second = $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer());

        $second->assertStatus(403);
        $second->assertJson(['error' => 'The app is missing permission to edit your theme. Please reinstall the app '
            . 'or contact support.']);
        $this->assertStringNotContainsString('Main theme not found', $second->getContent());
    }

    public function test_adding_a_script_with_all_scopes_updates_the_theme(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes');
        $layout = "<html><head>\n{{ content_for_header }}\n</head><body></body></html>";

        Http::fake(self::withStrayGuard([
            '*/themes.json' => Http::response(['themes' => [
                ['id' => 1, 'role' => 'unpublished'],
                ['id' => 42, 'role' => 'main'],
            ]]),
            '*/themes/42/assets.json*' => function (HttpRequest $request) use ($layout) {
                return $request->method() === 'GET'
                    ? Http::response(['asset' => ['key' => 'layout/theme.liquid', 'value' => $layout]])
                    : Http::response(['asset' => ['key' => $request['asset']['key']]]);
            },
        ]));

        $response = $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer());

        $response->assertOk()->assertJson(['message' => 'Script added successfully']);
        $this->assertSame(1, ScriptSrc::count());

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request['asset']['key'] === 'snippets/google_consent_mode_script.liquid'
            && str_contains($request['asset']['value'], self::SCRIPT_URL));
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request['asset']['key'] === 'layout/theme.liquid'
            && str_contains($request['asset']['value'], "{% render 'google_consent_mode_script' %}\n{{ content_for_header }}"));
    }

    public function test_a_rejected_token_still_triggers_reauthorization_and_clears_it(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes');
        Http::fake(self::withStrayGuard(['*/themes.json' => Http::response('{"errors":"[API] Invalid API key or access token"}', 401)]));

        $response = $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer());

        $response->assertStatus(401)->assertHeader('X-Shopify-API-Request-Failure-Reauthorize', '1');
        $this->assertNull(Session::first()->access_token);
    }

    public function test_other_theme_errors_are_not_treated_as_missing_scopes(): void
    {
        $this->storeOfflineSession('write_script_tags,write_themes');
        Http::fake(self::withStrayGuard(['*/themes.json' => Http::response('{"errors":"Unavailable Shop"}', 402)]));

        $response = $this->postJson('/api/add-script', ['url' => self::SCRIPT_URL], $this->bearer());

        $response->assertStatus(404)->assertJson(['error' => 'Main theme not found']);
        $this->assertSame('shpat_current', Session::first()->access_token);
    }

    // ---- ScopeCheck ------------------------------------------------------------------------------------------------

    public function test_scope_check(): void
    {
        $this->assertFalse(ScopeCheck::hasRequiredScopes('write_script_tags'));
        $this->assertFalse(ScopeCheck::hasRequiredScopes(null));
        $this->assertTrue(ScopeCheck::hasRequiredScopes('write_script_tags,write_themes'));
        $this->assertTrue(ScopeCheck::hasRequiredScopes('read_script_tags,write_script_tags,read_themes,write_themes'));
        $this->assertSame(['write_themes'], ScopeCheck::missingScopes('write_script_tags'));
        $this->assertTrue(ScopeCheck::isMissingScopeError(403, self::APPROVAL_ERROR));
        $this->assertFalse(ScopeCheck::isMissingScopeError(403, '{"errors":"Forbidden"}'));
    }
}
