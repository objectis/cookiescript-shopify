<?php

namespace App\Http\Middleware;

use App\Lib\AuthRedirection;
use App\Lib\ScopeCheck;
use App\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Shopify\Auth\OAuth;
use Shopify\Utils;

class EnsureShopifyInstalled
{
    /**
     * Checks if the shop in the query arguments is currently installed, and that the merchant approved every scope
     * the app needs. Shops that installed the app before a scope was added (e.g. read_themes/write_themes) are sent
     * through OAuth again, where Shopify asks the merchant to approve the new scopes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (preg_match("/^ExitIframe/i", $request->path())) {
            return $next($request);
        }

        $shopQuery = $request->query('shop');
        $shop = is_string($shopQuery) && $shopQuery !== '' ? Utils::sanitizeShopDomain($shopQuery) : null;

        $offlineSession = $shop
            ? Session::where('session_id', OAuth::getOfflineSessionId($shop))->whereNotNull('access_token')->first()
            : null;

        if (!$offlineSession) {
            return AuthRedirection::redirect($request);
        }

        if (!ScopeCheck::hasRequiredScopes($offlineSession->scope) && ScopeCheck::claimReauthorization($shop)) {
            return AuthRedirection::redirect($request);
        }

        return $next($request);
    }
}
