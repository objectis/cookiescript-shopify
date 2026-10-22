<?php

namespace App\Http\Controllers\Traits;

use Exception;
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

        if (!$session || !$session->getAccessToken()) {
            return null;
        }

        return $session;
    }
}
