<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\Scopes;
use Shopify\Context;

/**
 * Compares the scopes a shop granted with the scopes the app needs (SCOPES_READ).
 *
 * Shops that installed the app before the theme scopes were added still have a token without read_themes and
 * write_themes. Shopify then answers theme requests with
 * 403 "[API] This action requires merchant approval for read_themes scope." Running OAuth again shows the merchant
 * the approval screen for the missing scopes.
 */
class ScopeCheck
{
    /** Don't send the same shop through OAuth again within this time, so a misconfiguration can't cause a loop */
    private const REAUTHORIZE_COOLDOWN_SECONDS = 600;

    public static function hasRequiredScopes(?string $grantedScopes): bool
    {
        if (!Context::$SCOPES) {
            return true;
        }

        return (new Scopes((string) $grantedScopes))->has(Context::$SCOPES);
    }

    public static function missingScopes(?string $grantedScopes): array
    {
        $granted = new Scopes((string) $grantedScopes);

        return array_values(array_filter(
            Context::$SCOPES ? Context::$SCOPES->toArray() : [],
            fn ($scope) => !$granted->has($scope)
        ));
    }

    /**
     * Whether the API error means the token lacks a scope the merchant has to approve.
     */
    public static function isMissingScopeError(int $status, string $body): bool
    {
        return $status === 403 && stripos($body, 'requires merchant approval for') !== false;
    }

    /**
     * Returns true (and starts the cooldown) if the shop may be sent through OAuth for missing scopes now.
     * Returns false if that already happened recently: the merchant then uses the app normally, and saving shows
     * an error instead of redirecting in a loop.
     */
    public static function claimReauthorization(string $shop): bool
    {
        $claimed = Cache::add('shopify-scope-reauthorize:' . $shop, true, self::REAUTHORIZE_COOLDOWN_SECONDS);

        if (!$claimed) {
            Log::warning('The shop still has not granted all required scopes after re-authorization', [
                'shop' => $shop,
                'required' => Context::$SCOPES ? Context::$SCOPES->toString() : null,
            ]);
        }

        return $claimed;
    }
}
