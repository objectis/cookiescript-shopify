<?php

namespace App\Http\Controllers\Traits;

use App\Lib\TopLevelRedirection;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\Session;
use Shopify\Utils;

trait SessionTrait
{
    /**
     * Loads the offline session of the shop that sent the request.
     *
     * Returns null (so callers respond with 401) instead of throwing when the session token is missing, malformed,
     * expired or has an invalid signature.
     */
    private function loadCurrentSession(Request $request): ?Session
    {
        try {
            $session = Utils::loadCurrentSession(
                $request->header(),
                $request->cookie(),
                false
            );
        } catch (Exception $exception) {
            Log::debug('Could not load the current Shopify session: ' . $exception->getMessage());

            return null;
        }

        if (!$session) {
            return null;
        }

        if (!$session->getAccessToken()) {
            // The token was revoked or could not be renewed: send the merchant through OAuth again
            throw new HttpResponseException(
                TopLevelRedirection::redirect($request, '/api/auth?shop=' . urlencode($session->getShop()))
            );
        }

        return $session;
    }
}
