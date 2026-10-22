<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Validation helpers for CookieScript banner URLs and storefront hostnames.
 */
class CookieScriptUrl
{
    /**
     * Accepts both production (/s/) and staging (/staging/) banner scripts, with an explicit https: scheme or
     * protocol-relative (//...).
     */
    public const PATTERN =
        '#^(?:https:)?//(?:geo|cdn)\.cookie-script\.com/(?:s|staging)/[a-zA-Z0-9]{32}\.js(?:\?[a-zA-Z0-9=&_.,\-]*)?$#D';

    private const PREFIX_PATTERN = '#^((?:https?:)?//(?:geo|cdn)\.cookie-script\.com/)#i';

    private const HOSTNAME_PATTERN =
        '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D';

    /**
     * Returns the trimmed URL if it is a valid CookieScript banner URL, or null otherwise.
     * A plain "http://" scheme is upgraded to "https://" so the banner is never loaded insecurely.
     */
    public static function normalize($url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $url = trim($url);

        // Scheme and host are case-insensitive, the banner ID is not
        $url = preg_replace_callback(self::PREFIX_PATTERN, function ($matches) {
            return strtolower($matches[1]);
        }, $url);

        if (strpos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        return preg_match(self::PATTERN, $url) === 1 ? $url : null;
    }

    public static function isValid($url): bool
    {
        return self::normalize($url) !== null;
    }

    /**
     * Normalises user input ("https://Shop.example.com/path", "shop.example.com/") to a bare lowercase hostname.
     * Returns null when the result is not a valid hostname.
     */
    public static function normalizeHostname($rawDomain): ?string
    {
        if (!is_string($rawDomain)) {
            return null;
        }

        $domainHost = strtolower(trim($rawDomain));

        if (str_contains($domainHost, '://')) {
            $parsedHost = parse_url($domainHost, PHP_URL_HOST);
            $domainHost = is_string($parsedHost) ? $parsedHost : '';
        }

        // Drop any path that was pasted together with the domain
        $slashPosition = strpos($domainHost, '/');
        if ($slashPosition !== false) {
            $domainHost = substr($domainHost, 0, $slashPosition);
        }

        return self::isValidHostname($domainHost) ? $domainHost : null;
    }

    public static function isValidHostname($hostname): bool
    {
        return is_string($hostname) && preg_match(self::HOSTNAME_PATTERN, $hostname) === 1;
    }
}
