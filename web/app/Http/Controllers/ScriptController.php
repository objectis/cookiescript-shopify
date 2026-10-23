<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SessionTrait;
use App\Lib\CookieScriptUrl;
use App\Lib\OfflineTokenManager;
use App\Lib\ThemeScriptBuilder;
use App\Lib\TopLevelRedirection;
use App\Models\DomainBannerSetting;
use App\Models\ScriptSrc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\Session;

class ScriptController extends Controller
{
    use SessionTrait;

    private const SNIPPET_NAME = 'google_consent_mode_script';

    private const THEME_UPDATE_FAILED = 'Failed to update the store theme. Please try again.';

    /** Set when Shopify rejects the access token itself (not a missing permission) */
    private bool $accessTokenRejected = false;

    public function addScript(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $url = CookieScriptUrl::normalize($request->input('url'));

        if ($url === null) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => [
                    'url' => ['Must be a CookieScript script URL, e.g. //cdn.cookie-script.com/s/<code>.js or '
                        . '//cdn.cookie-script.com/staging/<code>.js'],
                ],
            ], 422);
        }

        $alreadyAdded = ScriptSrc::where('shop_domain', $session->getShop())->where('src', $url)->exists();

        if ($alreadyAdded) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => ['url' => ['This script has already been added.']],
            ], 422);
        }

        $scriptSrc = ScriptSrc::create([
            'shop_domain' => $session->getShop(),
            'src' => $url,
        ]);

        $publishResult = $this->publishScriptsToTheme($session);

        if (!$publishResult['success']) {
            // Keep the database in sync with the theme, so the merchant can simply try again
            $scriptSrc->delete();

            return $this->publishErrorResponse($request, $session, $publishResult);
        }

        return response()->json(['message' => 'Script added successfully', 'script' => $scriptSrc]);
    }

    public function getScripts(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $scripts = ScriptSrc::where('shop_domain', $session->getShop())->get();

        return response()->json($scripts);
    }

    public function removeScript(Request $request, $id)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();
        $scriptSrc = ScriptSrc::where('shop_domain', $shop)->where('id', $id)->first();

        if (!$scriptSrc) {
            return response()->json(['message' => 'Script not found'], 404);
        }

        $scriptSrc->delete();

        $publishResult = $this->publishScriptsToTheme($session);

        if (!$publishResult['success']) {
            // The script is still in the theme, so restore it to let the merchant retry the removal
            $scriptSrc->exists = false;
            $scriptSrc->save();

            return $this->publishErrorResponse($request, $session, $publishResult);
        }

        return response()->json(['message' => 'Script removed successfully']);
    }

    public function addScriptTag(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $publishResult = $this->publishScriptsToTheme($session);

        if (!$publishResult['success']) {
            return $this->publishErrorResponse($request, $session, $publishResult);
        }

        return response()->json(["OK"]);
    }

    public function getDomainBannerMap(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $domainBannerSetting = DomainBannerSetting::where('shop_domain', $session->getShop())->first();

        return response()->json([
            'multi_domain_enabled' => $domainBannerSetting?->multi_domain_enabled ?? false,
            'domain_map'           => $domainBannerSetting?->domain_map ?? [],
        ]);
    }

    public function saveDomainBannerMap(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();
        $multiDomainEnabled = filter_var(
            $request->input('multi_domain_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
        $domainMapInput = $request->input('domain_map') ?? [];

        if (!is_array($domainMapInput)) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => ['The domain map must be a list of domains.'],
            ], 422);
        }

        $normalisedDomainMap = [];
        $validationErrors = [];

        foreach ($domainMapInput as $domainMapEntry) {
            if (!$multiDomainEnabled) {
                // The rows are hidden and not rendered while multi-domain mode is off (the snippet builder
                // validates every row again), so keep them as entered instead of blocking the save.
                if (is_array($domainMapEntry) && is_string($domainMapEntry['domain'] ?? null)) {
                    $hiddenDomain = CookieScriptUrl::normalizeHostname($domainMapEntry['domain'])
                        ?? mb_substr(strtolower(trim($domainMapEntry['domain'])), 0, 255);
                    $hiddenUrl = is_string($domainMapEntry['url'] ?? null) ? trim($domainMapEntry['url']) : '';

                    if ($hiddenDomain !== '' && !array_key_exists($hiddenDomain, $normalisedDomainMap)) {
                        $normalisedDomainMap[$hiddenDomain] = CookieScriptUrl::normalize($hiddenUrl)
                            ?? mb_substr($hiddenUrl, 0, 2048);
                    }
                }
                continue;
            }

            if (!is_array($domainMapEntry)) {
                $validationErrors[] = 'Every domain map row must contain a domain and an installation URL.';
                continue;
            }

            $rawDomain = $domainMapEntry['domain'] ?? '';
            $rawUrl = $domainMapEntry['url'] ?? '';

            if (!is_string($rawDomain) || trim($rawDomain) === '') {
                $validationErrors[] = "Domain is required for every row.";
                continue;
            }

            $domainHost = CookieScriptUrl::normalizeHostname($rawDomain);

            if ($domainHost === null) {
                $validationErrors[] = 'Invalid domain "' . mb_substr(trim($rawDomain), 0, 100)
                    . '": enter a valid hostname (e.g. example.com).';
                continue;
            }

            if (!is_string($rawUrl) || trim($rawUrl) === '') {
                $validationErrors[] = "Installation URL is required for domain \"{$domainHost}\".";
                continue;
            }

            $scriptUrl = CookieScriptUrl::normalize($rawUrl);

            if ($scriptUrl === null) {
                $validationErrors[] = "Invalid installation URL for domain \"{$domainHost}\": "
                    . "must be a CookieScript HTTPS script URL.";
                continue;
            }

            if (array_key_exists($domainHost, $normalisedDomainMap)) {
                $validationErrors[] = "Duplicate domain \"{$domainHost}\" detected.";
                continue;
            }

            $normalisedDomainMap[$domainHost] = $scriptUrl;
        }

        if (!empty($validationErrors)) {
            return response()->json(['error' => 'Validation failed', 'details' => $validationErrors], 422);
        }

        $previousSetting = DomainBannerSetting::where('shop_domain', $shop)->first();
        $previousValues = $previousSetting ? [
            'multi_domain_enabled' => $previousSetting->multi_domain_enabled,
            'domain_map' => $previousSetting->domain_map,
        ] : null;

        $domainBannerSetting = DomainBannerSetting::updateOrCreate(
            ['shop_domain' => $shop],
            [
                'multi_domain_enabled' => $multiDomainEnabled,
                'domain_map' => $normalisedDomainMap,
            ]
        );

        $publishResult = $this->publishScriptsToTheme($session);

        if (!$publishResult['success']) {
            // Restore the previous settings, so a later publish doesn't push changes the merchant saw fail
            if ($previousValues === null) {
                $domainBannerSetting->delete();
            } else {
                $domainBannerSetting->update($previousValues);
            }

            return $this->publishErrorResponse($request, $session, $publishResult);
        }

        return response()->json(['statusText' => 'Domain banner map saved successfully']);
    }

    /**
     * Regenerates the theme snippet from the stored settings and makes sure the main theme layout renders it.
     *
     * @return array{success: bool, status: int, error: string|null}
     */
    private function publishScriptsToTheme(Session $session): array
    {
        $shop = $session->getShop();

        // Expiring offline tokens are migrated/refreshed here, right before the Admin API is used
        try {
            $tokenResult = OfflineTokenManager::accessToken($shop);
        } catch (\Throwable $exception) {
            Log::error('Could not get the offline access token', ['shop' => $shop, 'exception' => $exception]);
            $tokenResult = ['token' => null, 'reauthorize' => false];
        }

        if ($tokenResult['reauthorize']) {
            return [
                'success' => false,
                'status' => 401,
                'error' => 'Please open the app again to re-authorize it.',
                'reauthorize' => true,
            ];
        }

        $accessToken = $tokenResult['token'];

        if (!$accessToken) {
            return ['success' => false, 'status' => 503, 'error' => self::THEME_UPDATE_FAILED];
        }

        $scriptContent = null;

        try {
            $scriptContent = $this->generateScriptContent($shop);
            $themeId = $this->getMainThemeId($shop, $accessToken);

            if ($this->accessTokenRejected) {
                // e.g. a non-expiring token after Shopify's deadline: the merchant has to re-authorize the app
                OfflineTokenManager::invalidate($shop, $accessToken);

                return [
                    'success' => false,
                    'status' => 401,
                    'error' => 'Please open the app again to re-authorize it.',
                    'reauthorize' => true,
                ];
            }

            if (
                $themeId
                && $this->createScriptSnippet($shop, $accessToken, $themeId, $scriptContent)
                && $this->updateLayoutWithGoogleConsent($shop, $accessToken, $themeId)
            ) {
                return ['success' => true, 'status' => 200, 'error' => null];
            }

            if (!$themeId && !$this->isLocalThemePreviewEnabled()) {
                return ['success' => false, 'status' => 404, 'error' => 'Main theme not found'];
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to publish scripts to the theme', ['shop' => $shop, 'exception' => $exception]);
        }

        if ($scriptContent !== null && $this->isLocalThemePreviewEnabled()) {
            return $this->writeLocalThemePreview($shop, $scriptContent);
        }

        return ['success' => false, 'status' => 500, 'error' => self::THEME_UPDATE_FAILED];
    }

    /**
     * Local testing only: Shopify rejects theme file writes from apps without the Online Store protected scope
     * exemption (e.g. a newly created development app). When SHOPIFY_LOCAL_THEME_PREVIEW=true, the generated
     * snippet is saved to storage/app/theme-preview instead, so the rest of the plugin can still be tested.
     * It only works in the local, development (used by the Shopify CLI) and testing environments.
     */
    private function isLocalThemePreviewEnabled(): bool
    {
        return (bool) Config::get('shopify.local_theme_preview', false)
            && app()->environment(['local', 'development', 'testing']);
    }

    private function writeLocalThemePreview(string $shop, string $scriptContent): array
    {
        try {
            $previewDirectory = storage_path('app' . DIRECTORY_SEPARATOR . 'theme-preview');

            if (!is_dir($previewDirectory) && !mkdir($previewDirectory, 0755, true) && !is_dir($previewDirectory)) {
                throw new \RuntimeException("Could not create $previewDirectory");
            }

            // The shop comes from the verified session and only contains [a-z0-9.-]
            $fileName = preg_replace('/[^a-z0-9.\-]/', '_', strtolower($shop)) . '__' . self::SNIPPET_NAME . '.liquid';
            $previewPath = $previewDirectory . DIRECTORY_SEPARATOR . $fileName;

            $header = "{% comment %}\n"
                . "  LOCAL THEME PREVIEW - not uploaded to Shopify.\n"
                . "  This is the content the app would save as snippets/" . self::SNIPPET_NAME . ".liquid\n"
                . "  and render in layout/theme.liquid with {% render '" . self::SNIPPET_NAME . "' %}.\n"
                . "  Shop: {$shop}\n"
                . "  Generated: " . date('Y-m-d H:i:s') . "\n"
                . "{% endcomment %}\n";

            if (file_put_contents($previewPath, $header . $scriptContent) === false) {
                throw new \RuntimeException("Could not write $previewPath");
            }

            Log::info('Theme update skipped: snippet saved to the local theme preview file', [
                'shop' => $shop,
                'file' => $previewPath,
            ]);

            return ['success' => true, 'status' => 200, 'error' => null];
        } catch (\Throwable $exception) {
            Log::error('Failed to write the local theme preview', ['shop' => $shop, 'exception' => $exception]);

            return ['success' => false, 'status' => 500, 'error' => self::THEME_UPDATE_FAILED];
        }
    }

    /**
     * Error response for a failed theme update. When the access token can't be renewed, the App Bridge frontend is
     * told to send the merchant through OAuth again (handled by useAuthenticatedFetch).
     */
    private function publishErrorResponse(Request $request, Session $session, array $publishResult)
    {
        if (!empty($publishResult['reauthorize'])) {
            return TopLevelRedirection::redirect($request, '/api/auth?shop=' . urlencode($session->getShop()));
        }

        return response()->json(['error' => $publishResult['error']], $publishResult['status']);
    }

    private function adminApiUrl(string $shop, string $path): string
    {
        $apiVersion = Config::get('shopify.rest_api_version', '2023-04');

        return "https://{$shop}/admin/api/{$apiVersion}/{$path}";
    }

    private function getMainThemeId($shop, $accessToken)
    {
        $themeResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get($this->adminApiUrl($shop, 'themes.json'));

        if (!$themeResponse->successful()) {
            $this->accessTokenRejected = $themeResponse->status() === 401
                || ($themeResponse->status() === 403
                    && stripos($themeResponse->body(), 'Non-expiring access tokens are no longer accepted') !== false);

            Log::error('Failed to fetch themes', ['shop' => $shop, 'response' => $themeResponse->body()]);
            return null;
        }

        $themes = $themeResponse->json('themes');
        $mainTheme = collect($themes)->firstWhere('role', 'main');

        return $mainTheme ? $mainTheme['id'] : null;
    }

    private function createScriptSnippet($shop, $accessToken, $themeId, $scriptTagContent)
    {
        $assetResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->put($this->adminApiUrl($shop, "themes/{$themeId}/assets.json"), [
                'asset' => [
                    'key' => 'snippets/' . self::SNIPPET_NAME . '.liquid',
                    'value' => $scriptTagContent,
                ]
            ]);

        if (!$assetResponse->successful()) {
            Log::error('Failed to create script snippet', ['shop' => $shop, 'response' => $assetResponse->body()]);
            return false;
        }

        return true;
    }

    private function updateLayoutWithGoogleConsent($shop, $accessToken, $themeId)
    {
        $layoutResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get($this->adminApiUrl($shop, "themes/{$themeId}/assets.json"), [
                'asset[key]' => 'layout/theme.liquid',
            ]);

        if (!$layoutResponse->successful()) {
            Log::error('Failed to fetch layout file', ['shop' => $shop, 'response' => $layoutResponse->body()]);
            return false;
        }

        $layoutContent = $layoutResponse->json('asset.value');

        if (!is_string($layoutContent)) {
            Log::error('Layout file has no content', ['shop' => $shop]);
            return false;
        }

        $snippetAlreadyRendered = preg_match(
            '/\{%-?\s*(render|include)\s+[\'"]' . self::SNIPPET_NAME . '[\'"]/',
            $layoutContent
        ) === 1;

        if ($snippetAlreadyRendered) {
            return true;
        }

        $headClosePosition = $this->findPositionOutsideLiquidBlocks($layoutContent, '#</head>#i');

        if ($headClosePosition === false) {
            Log::error('Could not find </head> in layout/theme.liquid', ['shop' => $shop]);
            return false;
        }

        // Render the snippet before {{ content_for_header }} when it is in the <head>, so the consent defaults and
        // the banner load before Shopify's and other apps' tracking scripts. Otherwise, right before </head>.
        $contentForHeaderPosition = $this->findPositionOutsideLiquidBlocks(
            $layoutContent,
            '/\{\{-?\s*content_for_header\s*-?\}\}/'
        );
        $insertPosition = $contentForHeaderPosition !== false && $contentForHeaderPosition < $headClosePosition
            ? $contentForHeaderPosition
            : $headClosePosition;

        // Insert the snippet only once
        $includeSnippet = "{% render '" . self::SNIPPET_NAME . "' %}\n";
        $layoutContent = substr_replace($layoutContent, $includeSnippet, $insertPosition, 0);

        $updateLayoutResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->put($this->adminApiUrl($shop, "themes/{$themeId}/assets.json"), [
                'asset' => [
                    'key' => 'layout/theme.liquid',
                    'value' => $layoutContent,
                ]
            ]);

        if (!$updateLayoutResponse->successful()) {
            Log::error('Failed to update layout file', ['shop' => $shop, 'response' => $updateLayoutResponse->body()]);
            return false;
        }

        return true;
    }

    /**
     * Position of the first match of $pattern that is not inside a Liquid {% comment %} or {% raw %} block.
     *
     * @return int|false
     */
    private function findPositionOutsideLiquidBlocks(string $layoutContent, string $pattern)
    {
        $ignoredRanges = [];
        $blockPattern = '/\{%-?\s*(comment|raw)\s*-?%\}.*?\{%-?\s*end\1\s*-?%\}/is';

        if (preg_match_all($blockPattern, $layoutContent, $blockMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($blockMatches[0] as [$blockContent, $blockStart]) {
                $ignoredRanges[] = [$blockStart, $blockStart + strlen($blockContent)];
            }
        }

        if (!preg_match_all($pattern, $layoutContent, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [, $position]) {
            $isIgnored = false;

            foreach ($ignoredRanges as [$rangeStart, $rangeEnd]) {
                if ($position >= $rangeStart && $position < $rangeEnd) {
                    $isIgnored = true;
                    break;
                }
            }

            if (!$isIgnored) {
                return $position;
            }
        }

        return false;
    }

    private function getScriptSources($shop)
    {
        return ScriptSrc::where('shop_domain', $shop)
            ->orderBy('id', 'desc')
            ->pluck('src')
            ->toArray();
    }

    private function generateScriptContent(string $shop): string
    {
        $settings = GoogleConsentController::settingsForShop($shop);
        $globalConsent = (array) ($settings['global_consent'] ?? []);

        $domainSetting = DomainBannerSetting::where('shop_domain', $shop)->first();
        $domainMap = null;

        if ($domainSetting && $domainSetting->multi_domain_enabled && !empty($domainSetting->domain_map)) {
            $domainMap = (array) $domainSetting->domain_map;
        }

        return ThemeScriptBuilder::build(
            $globalConsent,
            (array) ($settings['regional_consents'] ?? []),
            !empty($globalConsent['google_consent_enabled']),
            $this->getScriptSources($shop),
            $domainMap
        );
    }
}
