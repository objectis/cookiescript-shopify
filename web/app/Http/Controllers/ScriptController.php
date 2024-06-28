<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SessionTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shopify\Exception\CookieNotFoundException;
use Shopify\Exception\MissingArgumentException;
use Shopify\Rest\Admin2023_01\ScriptTag;
use stdClass;

class ScriptController extends Controller
{
    use SessionTrait;

    public function addScript(Request $request)
    {
        $url = $request->get('url');

        if (is_null($url)) {
            return response('Invalid URL', 400);
        }

        $script_tag = new ScriptTag($this->loadCurrentSession($request),);
        $script_tag->event = "onload";
        $script_tag->src = $url;
        $script_tag->save(
            true
        );

        return response("OK");
    }

    public function getScripts(Request $request)
    {
        $pattern = '/\.cookie-script\.com\/s\/[a-zA-Z0-9]{32}/';
        $matches = [];

        $scripts = ScriptTag::all(
            $this->loadCurrentSession($request) ?? [],
            [],
            []
        );

        foreach ($scripts as $script) {
            if (preg_match($pattern, $script->src)) {
                $newScriptsObject = new stdClass();
                $newScriptsObject->src = $script->src;
                $newScriptsObject->id = $script->id;

                array_push($matches, $newScriptsObject);
            }
        }

        return $matches;
    }

    public function removeScript(Request $request, $id)
    {
        return ScriptTag::delete(
            $this->loadCurrentSession($request),
            $id,
            [],
            []
        );
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
            $googleConsentModeScript = $this->createGoogleConsentModeScript($request);

            $scriptTagContent = "
                <script data-cs-plugin=\"shopify\">
                    {$googleConsentModeScript}
                </script>
            ";

            $themeResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->get("https://{$shop}/admin/api/2023-04/themes.json");

            if (!$themeResponse->successful()) {
                Log::error('Failed to fetch themes', ['response' => $themeResponse->body()]);
                return response()->json([
                    'error' => 'Failed to fetch themes',
                    'details' => $themeResponse->body()
                ], $themeResponse->status());
            }

            $themes = $themeResponse->json('themes');
            $mainTheme = collect($themes)->firstWhere('role', 'main');

            if (!$mainTheme) {
                return response()->json(['error' => 'Main theme not found'], 404);
            }

            $themeId = $mainTheme['id'];

            $assetResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->put("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                'asset' => [
                    'key' => 'snippets/google_consent_mode_script.liquid',
                    'value' => $scriptTagContent,
                ]
            ]);

            if (!$assetResponse->successful()) {
                Log::error('Failed to create script snippet', ['response' => $assetResponse->body()]);
                return response()->json(['error' => 'Failed to create script snippet',
                    'details' => $assetResponse->body()
                ], $assetResponse->status());
            }

            $includeSnippet = '{% include "google_consent_mode_script" %}';
            $layoutResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->get("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                'asset[key]' => 'layout/theme.liquid',
            ]);

            if (!$layoutResponse->successful()) {
                Log::error('Failed to fetch layout file', ['response' => $layoutResponse->body()]);
                return response()->json(['error' => 'Failed to fetch layout file',
                    'details' => $layoutResponse->body()
                ], $layoutResponse->status());
            }

            $layoutContent = $layoutResponse->json('asset')['value'];

            if (strpos($layoutContent, $includeSnippet) === false) {
                $layoutContent = str_replace('</head>', "{$includeSnippet}\n</head>", $layoutContent);

                $updateLayoutResponse = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                ])->put("https://{$shop}/admin/api/2023-04/themes/{$themeId}/assets.json", [
                    'asset' => [
                        'key' => 'layout/theme.liquid',
                        'value' => $layoutContent,
                    ]
                ]);

                if (!$updateLayoutResponse->successful()) {
                    Log::error('Failed to update layout file', ['response' => $updateLayoutResponse->body()]);
                    return response()->json(['error' => 'Failed to update layout file',
                        'details' => $updateLayoutResponse->body()
                    ], $updateLayoutResponse->status());
                }
            }

            return response()->json(["OK"]);
        } catch (CookieNotFoundException | MissingArgumentException $e) {
            Log::error('Session load error', ['exception' => $e]);
            return response()->json(['error' => 'Failed to load session', 'details' => $e->getMessage()], 500);
        }
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
            region: '{$regional['region']}'
        });
        ";
        }

        return $googleConsentModeScript;
    }

}
