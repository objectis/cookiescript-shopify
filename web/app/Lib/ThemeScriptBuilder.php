<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Builds the content of the snippets/google_consent_mode_script.liquid theme snippet.
 *
 * Every value that ends up in the snippet is either whitelisted, cast, or escaped here, so stored data can never
 * inject JavaScript, HTML or Liquid into the storefront, even if it was saved before validation existed.
 */
class ThemeScriptBuilder
{
    public const CONSENT_FIELDS = [
        'ad_storage',
        'analytics_storage',
        'ad_user_data',
        'ad_personalization',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
    ];

    public const CONSENT_VALUES = ['granted', 'denied'];

    public const DEFAULT_WAIT_FOR_UPDATE = 500;

    public const MAX_WAIT_FOR_UPDATE = 3600000;

    private const REGION_CODE_PATTERN = '/^[a-z]{2}(-[a-z0-9]{1,3})?$/iD';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * @param array      $globalConsent     Global consent settings (keys of CONSENT_FIELDS + wait_for_update)
     * @param array      $regionalConsents  List of regional consent settings (same keys + region)
     * @param bool       $consentEnabled    Whether Google Consent Mode is enabled
     * @param string[]   $scriptSources     Banner script URLs added via the main form, newest first
     * @param array|null $domainMap         [hostname => script URL] when multi-domain mode is enabled, otherwise null
     */
    public static function build(
        array $globalConsent,
        array $regionalConsents,
        bool $consentEnabled,
        array $scriptSources,
        ?array $domainMap
    ): string {
        $scriptTags = '';

        if ($consentEnabled) {
            $consentScript = self::buildGoogleConsentModeScript($globalConsent, $regionalConsents);
            $scriptTags .= "<script>\n{$consentScript}\n</script>";
        }

        $defaultTags = '';
        foreach ($scriptSources as $scriptSource) {
            $defaultTags .= self::scriptTag($scriptSource);
        }

        $domainTags = [];
        foreach ($domainMap ?? [] as $domainName => $bannerScriptUrl) {
            $domainName = strtolower((string) $domainName);
            $bannerScriptUrl = CookieScriptUrl::normalize($bannerScriptUrl);

            if (!CookieScriptUrl::isValidHostname($domainName) || $bannerScriptUrl === null) {
                continue;
            }

            $domainTags[$domainName] = self::scriptTag($bannerScriptUrl);
        }

        if (!empty($domainTags)) {
            $scriptTags .= "\n{%- assign _cs_host = request.host | downcase -%}\n";
            $scriptTags .= "{% case _cs_host %}\n";

            foreach ($domainTags as $domainName => $domainTag) {
                // $domainName only contains [a-z0-9.-] (validated above), so it is safe inside a Liquid string
                $scriptTags .= "{% when \"{$domainName}\" %}{$domainTag}";
            }

            $scriptTags .= "{% else %}{$defaultTags}";
            $scriptTags .= "{% endcase %}\n";
        } else {
            $scriptTags .= $defaultTags;
        }

        return $scriptTags;
    }

    public static function buildGoogleConsentModeScript(array $globalConsent, array $regionalConsents): string
    {
        $googleConsentModeScript = "
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () {
            dataLayer.push(arguments);
        };";

        foreach ($regionalConsents as $regionalConsent) {
            $regionalConsent = (array) $regionalConsent;
            $regionCodes = self::regionCodes($regionalConsent['region'] ?? '');

            if (empty($regionCodes)) {
                continue;
            }

            $consentDefaults = self::consentDefaults($regionalConsent);
            $consentDefaults['region'] = $regionCodes;

            $googleConsentModeScript .= "
        gtag('consent', 'default', " . json_encode($consentDefaults, self::JSON_FLAGS) . ");";
        }

        $googleConsentModeScript .= "
        gtag('consent', 'default', " . json_encode(self::consentDefaults($globalConsent), self::JSON_FLAGS) . ");

        gtag('set', 'developer_id.dMmY1Mm', true);
        gtag('set', 'ads_data_redaction', true);
        ";

        return $googleConsentModeScript;
    }

    /**
     * Safe consent defaults: unknown values fall back to "denied", wait_for_update is always an integer.
     */
    public static function consentDefaults(array $settings): array
    {
        $defaults = [];

        foreach (self::CONSENT_FIELDS as $field) {
            $value = $settings[$field] ?? null;
            $defaults[$field] = in_array($value, self::CONSENT_VALUES, true) ? $value : 'denied';
        }

        $defaults['wait_for_update'] = self::waitForUpdate($settings['wait_for_update'] ?? null);

        return $defaults;
    }

    public static function waitForUpdate($value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $value = (int) $value;

            if ($value >= 0 && $value <= self::MAX_WAIT_FOR_UPDATE) {
                return $value;
            }
        }

        return self::DEFAULT_WAIT_FOR_UPDATE;
    }

    /**
     * @return string[] valid ISO region codes from a comma separated list, e.g. "US, US-CA" => ["US", "US-CA"]
     */
    public static function regionCodes($region): array
    {
        if (!is_string($region)) {
            return [];
        }

        $regionCodes = array_map('trim', explode(',', $region));

        $validRegionCodes = array_filter($regionCodes, function ($regionCode) {
            return preg_match(self::REGION_CODE_PATTERN, $regionCode) === 1;
        });

        // Google Consent Mode expects uppercase ISO 3166 codes (e.g. "US-CA")
        return array_values(array_unique(array_map('strtoupper', $validRegionCodes)));
    }

    /**
     * Escaped <script> tag for a banner URL. Values that could break out of the attribute or contain Liquid
     * delimiters are dropped.
     */
    public static function scriptTag($scriptSource): string
    {
        if (!is_string($scriptSource)) {
            return '';
        }

        $scriptSource = trim($scriptSource);

        if ($scriptSource === '' || preg_match('/[{}<>"\'\s\\\\]/', $scriptSource)) {
            return '';
        }

        $escapedSource = htmlspecialchars($scriptSource, ENT_QUOTES, 'UTF-8');

        return "<script data-cs-plugin='shopify' src=\"{$escapedSource}\"></script>\n";
    }
}
