<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SessionTrait;
use App\Models\ScriptSrc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Shopify\Exception\CookieNotFoundException;
use Shopify\Exception\MissingArgumentException;

class ScriptController extends Controller
{
    use SessionTrait;

    public function addScript(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();
        $url = $request->input('url');

        if (is_null($url)) {
            return response()->json(['error' => 'Invalid URL'], 400);
        }

        $validator = Validator::make(['url' => $url], [
            'url' => 'required|url'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $scriptSrc = ScriptSrc::create([
            'shop_domain' => $shop,
            'src' => $url
        ]);

        $this->publishScriptsToTheme($shop, $request);

        return response()->json(['message' => 'Script added successfully', 'script' => $scriptSrc]);
    }

    public function getScripts(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();
        $scripts = ScriptSrc::where('shop_domain', $shop)->get();

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

        $this->publishScriptsToTheme($shop, $request);

        return response()->json(['message' => 'Script removed successfully']);
    }

    public function saveSrcScripts(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();

        $this->generateScriptContent($request, $shop);

        return response()->json(['message' => 'Scripts saved successfully']);
    }

    private function publishScriptsToTheme($shop, $request)
    {
        ScriptSrc::where('shop_domain', $shop)->pluck('src')->toArray();
        $this->saveSrcScripts($request);
        $this->addScriptTag($request);

        return response()->json(['message' => 'OK']);
    }

    private function getMainThemeId($shop, $accessToken)
    {
        $themeResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get("https://{$shop}/admin/api/2023-04/themes.json");

        if (!$themeResponse->successful()) {
            Log::error('Failed to fetch themes', ['response' => $themeResponse->body()]);
            return null;
        }

        $themes = $themeResponse->json('themes');
        $mainTheme = collect($themes)->firstWhere('role', 'main');

        return $mainTheme ? $mainTheme['id'] : null;
    }

    public function addScriptTag(Request $request)
    {
        try {
            $session = $this->loadCurrentSession($request);

            if (!$session) {
                return response()->json(['error' => 'Session not found'], 401);
            }

            $shop = $session->getShop();
            $accessToken = $session->getAccessToken();

            return $this->addGoogleConsentModeScript($shop, $accessToken, $request);
        } catch (CookieNotFoundException | MissingArgumentException $e) {
            Log::error('Session load error', ['exception' => $e]);
            return response()->json(['error' => 'Failed to load session', 'details' => $e->getMessage()], 500);
        }
    }

    private function getSettings(Request $request)
    {
        $googleConsentController = new GoogleConsentController();
        $getGoogleConsentModeSettings = $googleConsentController->getStoredSettings($request);

        return json_decode($getGoogleConsentModeSettings->getContent());
    }

    private function addGoogleConsentModeScript($shop, $accessToken, Request $request)
    {
        $themeId = $this->getMainThemeId($shop, $accessToken);

        if (!$themeId) {
            return response()->json(['error' => 'Main theme not found'], 404);
        }

        $googleConsentModeScript = $this->createGoogleConsentModeScript($request);
        $scriptTagContent = $this->generateScriptContent($request, $shop, $googleConsentModeScript);

        if (
            $this->createScriptSnippet($shop, $accessToken, $themeId, $scriptTagContent)
            && $this->updateLayoutWithGoogleConsent($shop, $accessToken, $themeId)
        ) {
            return response()->json(["OK"]);
        }

        return response()->json(['error' => 'Failed to add script'], 500);
    }

    private function createScriptSnippet($shop, $accessToken, $themeId, $scriptTagContent)
    {
        $assetResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->put("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                'asset' => [
                    'key' => 'snippets/google_consent_mode_script.liquid',
                    'value' => $scriptTagContent,
                ]
            ]);

        if (!$assetResponse->successful()) {
            Log::error('Failed to create script snippet', ['response' => $assetResponse->body()]);
            return false;
        }

        return true;
    }

    private function updateLayoutWithGoogleConsent($shop, $accessToken, $themeId)
    {
        $layoutResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                'asset[key]' => 'layout/theme.liquid',
            ]);

        if (!$layoutResponse->successful()) {
            Log::error('Failed to fetch layout file', ['response' => $layoutResponse->body()]);
            return false;
        }

        $layoutContent = $layoutResponse->json('asset')['value'];
        $includeSnippet = '{% include "google_consent_mode_script" %}';

        if (strpos($layoutContent, $includeSnippet) === false) {
            $layoutContent = str_replace('</head>', "{$includeSnippet}\n</head>", $layoutContent);

            $updateLayoutResponse = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->put("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                    'asset' => [
                        'key' => 'layout/theme.liquid',
                        'value' => $layoutContent,
                    ]
                ]);

            if (!$updateLayoutResponse->successful()) {
                Log::error('Failed to update layout file', ['response' => $updateLayoutResponse->body()]);

                return false;
            }
        }

        return true;
    }

    private function getScriptSources($shop)
    {
        return ScriptSrc::where('shop_domain', $shop)->pluck('src')->toArray();
    }


    private function generateScriptContent($request, $shop, $googleConsentModeScript = null)
    {
        $scripts = $this->getScriptSources($shop);
        $scriptTags = "";
        $settings = $this->getSettings($request);

        if (count($scripts) > 0) {
            foreach ($scripts as $src) {
                $scriptTags .= "<script data-cs-plugin='shopify' src=\"{$src}\"></script>\n";
            }
        }

        if ($settings->global_consent->google_consent_enabled) {
            $scriptTags .= "<script>\n{$googleConsentModeScript}\n</script>";
        }

        return $scriptTags;
    }

    public function createGoogleConsentModeScript(Request $request)
    {
        $googleConsentController = new GoogleConsentController();
        $getGoogleConsentModeSettings = $googleConsentController->getStoredSettings($request);
        $settings = json_decode($getGoogleConsentModeSettings->getContent());
        $globalSettings = [];
        $regionalSettings = [];

        if (isset($settings->global_consent)) {
            $globalSettings = (array) $settings->global_consent;
        }

        if (isset($settings->regional_consents) && is_array($settings->regional_consents)) {
            foreach ($settings->regional_consents as $consent) {
                $regionalSettings[] = (array) $consent;
            }
        }

        $googleConsentModeScript = "
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () {
            dataLayer.push(arguments);
        };

        gtag('set', 'developer_id.dMmY1Mm', true);
        gtag('set', 'ads_data_redaction', true);

        gtag('consent', 'default', {
            ad_storage: '{$globalSettings['ad_storage']}',
            analytics_storage: '{$globalSettings['analytics_storage']}',
            ad_user_data: '{$globalSettings['ad_user_data']}',
            ad_personalization: '{$globalSettings['ad_personalization']}',
            functionality_storage: '{$globalSettings['functionality_storage']}',
            personalization_storage: '{$globalSettings['personalization_storage']}',
            security_storage: '{$globalSettings['security_storage']}',
            wait_for_update: {$globalSettings['wait_for_update']}
        });
        ";

        foreach ($regionalSettings as $regional) {
            $encodedRegionCode = json_encode(array_map('trim', explode(',', $regional['region'])));

            $googleConsentModeScript .= "
        gtag('consent', 'default', {
            ad_storage: '{$regional['ad_storage']}',
            analytics_storage: '{$regional['analytics_storage']}',
            ad_user_data: '{$regional['ad_user_data']}',
            ad_personalization: '{$regional['ad_personalization']}',
            functionality_storage: '{$regional['functionality_storage']}',
            personalization_storage: '{$regional['personalization_storage']}',
            security_storage: '{$regional['security_storage']}',
            wait_for_update: {$regional['wait_for_update']},
            region: JSON.parse('{$encodedRegionCode}'.replace(/&quot;/g, '\"'))
        });
        ";
        }

        return $googleConsentModeScript;
    }
}
