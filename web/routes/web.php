<?php

use App\Lib\AuthRedirection;
use App\Lib\EmbeddedAppUrl;
use App\Lib\EnsureBilling;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Shopify\Auth\OAuth;
use Shopify\Clients\HttpHeaders;
use Shopify\Context;
use Shopify\Exception\InvalidWebhookException;
use Shopify\Utils;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| If you are adding routes outside of the /api path, remember to also add a
| proxy rule for them in web/frontend/vite.config.js
|
*/

// Serve Vite build assets directly from frontend/dist/assets
Route::get('/assets/{path}', function ($path) {
    $assetsDirectory = realpath(base_path('frontend/dist/assets'));
    $filePath = $assetsDirectory ? realpath($assetsDirectory . DIRECTORY_SEPARATOR . $path) : false;

    // Only serve existing files that are really inside frontend/dist/assets (blocks "../" path traversal)
    if (
        !$filePath
        || strpos($filePath, $assetsDirectory . DIRECTORY_SEPARATOR) !== 0
        || !is_file($filePath)
    ) {
        abort(404);
    }

    $mimeTypes = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
    ];

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

    // Vite gives built assets content-hashed file names, so browsers can cache them for a long time
    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*');

Route::fallback(function (Request $request) {
    if (Context::$IS_EMBEDDED_APP &&  $request->query("embedded", null) === "1") {
        if (file_exists(base_path('frontend/dist/index.html'))) {
            return file_get_contents(base_path('frontend/dist/index.html'));
        }

        if (file_exists(base_path('frontend/index.html'))) {
            return file_get_contents(base_path('frontend/index.html'));
        }
        
        abort(404, 'Frontend index.html not found.');
    } else {
        $host = $request->query("host");
        $shop = $request->query("shop");
        $embeddedAppUrl = EmbeddedAppUrl::build(
            is_string($host) ? $host : null,
            is_string($shop) ? $shop : null
        );

        if ($embeddedAppUrl === null) {
            abort(400, 'Missing or invalid host and shop query arguments.');
        }

        $path = ltrim($request->path(), '/');

        return redirect($embeddedAppUrl . ($path === '' ? '' : "/$path"));
    }
})->middleware('shopify.installed');

Route::get('/api/auth', function (Request $request) {
    $shopQuery = $request->query('shop');
    $shop = Utils::sanitizeShopDomain(is_string($shopQuery) ? $shopQuery : '');

    // Delete any previously created OAuth sessions that were not completed (don't have an access token)
    Session::where('shop', $shop)->where('access_token', null)->delete();

    return AuthRedirection::redirect($request);
});

Route::get('/api/auth/callback', function (Request $request) {
    $session = OAuth::callback(
        $request->cookie(),
        $request->query(),
        ['App\Lib\CookieHandler', 'saveShopifyCookie'],
    );

    $host = is_string($request->query('host')) ? $request->query('host') : null;
    $shop = $session->getShop();

    $response = Registry::register('/api/webhooks', Topics::APP_UNINSTALLED, $shop, $session->getAccessToken());
    if ($response->isSuccess()) {
        Log::debug("Registered APP_UNINSTALLED webhook for shop $shop");
    } else {
        Log::error(
            "Failed to register APP_UNINSTALLED webhook for shop $shop with response body: " .
                print_r($response->getBody(), true)
        );
    }

    $redirectUrl = EmbeddedAppUrl::build($host, $session->getShop());
    if (Config::get('shopify.billing.required')) {
        list($hasPayment, $confirmationUrl) = EnsureBilling::check($session, Config::get('shopify.billing'));

        if (!$hasPayment) {
            $redirectUrl = $confirmationUrl;
        }
    }

    return redirect($redirectUrl);
});

Route::post('/api/webhooks', function (Request $request) {
    $topic = $request->header(HttpHeaders::X_SHOPIFY_TOPIC, '');

    try {
        $response = Registry::process($request->header(), $request->getContent());
        if (!$response->isSuccess()) {
            Log::error("Failed to process '$topic' webhook: {$response->getErrorMessage()}");
            return response()->json(['message' => "Failed to process '$topic' webhook"], 500);
        }

        return response()->json(['message' => 'OK']);
    } catch (InvalidWebhookException $e) {
        Log::error("Got invalid webhook request for topic '$topic': {$e->getMessage()}");
        return response()->json(['message' => "Got invalid webhook request for topic '$topic'"], 401);
    } catch (\Exception $e) {
        Log::error("Got an exception when handling '$topic' webhook: {$e->getMessage()}");
        return response()->json(['message' => "Got an exception when handling '$topic' webhook"], 500);
    }
});
