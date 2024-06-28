<?php

namespace App\Http\Controllers\Traits;

use Shopify\Exception\CookieNotFoundException;
use Shopify\Exception\MissingArgumentException;
use Illuminate\Http\Request;
use Shopify\Utils;

trait SessionTrait
{
    /**
     * @throws CookieNotFoundException
     * @throws MissingArgumentException
     */
    private function loadCurrentSession(Request $request)
    {
        return Utils::loadCurrentSession(
            $request->header(),
            $request->cookie(),
            false
        );
    }
}
