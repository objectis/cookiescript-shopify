<?php

namespace App\Http\Middleware;

use App\Lib\AuthRedirection;
use App\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Shopify\Utils;

class EnsureShopifyInstalled
{
    /**
     * Checks if the shop in the query arguments is currently installed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $shopQuery = $request->query('shop');
        $shop = is_string($shopQuery) && $shopQuery !== '' ? Utils::sanitizeShopDomain($shopQuery) : null;

        $appInstalled = $shop && Session::where('shop', $shop)->where('access_token', '<>', null)->exists();
        $isExitingIframe = preg_match("/^ExitIframe/i", $request->path());

        return ($appInstalled || $isExitingIframe) ? $next($request) : AuthRedirection::redirect($request);
    }
}
