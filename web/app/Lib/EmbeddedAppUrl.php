<?php

declare(strict_types=1);

namespace App\Lib;

use Shopify\Context;
use Shopify\Utils;

/**
 * Builds the Shopify admin URL of the embedded app.
 *
 * Utils::getEmbeddedAppUrl() trusts the base64 "host" query argument blindly, which allows redirecting users to any
 * website. This helper only accepts hosts that point to a Shopify admin and otherwise falls back to the shop domain.
 */
class EmbeddedAppUrl
{
    private const ADMIN_SHOPIFY_PATTERN = '#^admin\.shopify\.com/store/[a-zA-Z0-9][a-zA-Z0-9\-]*$#D';

    /**
     * @return string|null The embedded app URL, or null when neither the host nor the shop is valid
     */
    public static function build(?string $host, ?string $shop): ?string
    {
        $decodedHost = self::validDecodedHost($host);

        if ($decodedHost === null) {
            $sanitizedShop = $shop ? Utils::sanitizeShopDomain($shop) : null;

            if ($sanitizedShop === null) {
                return null;
            }

            $decodedHost = "$sanitizedShop/admin";
        }

        return "https://$decodedHost/apps/" . Context::$API_KEY;
    }

    public static function validDecodedHost(?string $host): ?string
    {
        if (!$host) {
            return null;
        }

        $decodedHost = base64_decode($host, true);

        if (!is_string($decodedHost) || $decodedHost === '') {
            return null;
        }

        $decodedHost = rtrim($decodedHost, '/');

        if (preg_match(self::ADMIN_SHOPIFY_PATTERN, $decodedHost) === 1) {
            return $decodedHost;
        }

        // Legacy format: {shop}.myshopify.com/admin (or a configured custom shop domain)
        if (substr($decodedHost, -6) === '/admin') {
            $shopDomain = substr($decodedHost, 0, -6);

            if (
                preg_match('/^[a-zA-Z0-9.\-]+$/D', $shopDomain) === 1
                && Utils::sanitizeShopDomain($shopDomain) === strtolower($shopDomain)
            ) {
                return $decodedHost;
            }
        }

        return null;
    }
}
