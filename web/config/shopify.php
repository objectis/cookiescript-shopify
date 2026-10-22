<?php

use App\Lib\EnsureBilling;

return [

    /*
    |--------------------------------------------------------------------------
    | Admin REST API version
    |--------------------------------------------------------------------------
    |
    | Version used for the theme and asset REST requests made by ScriptController. Shopify only supports each
    | version for about a year (older ones are served by the oldest supported version), so after testing on a
    | development store set SHOPIFY_REST_API_VERSION to a current version, e.g. 2026-07.
    | See https://shopify.dev/docs/api/usage/versioning
    |
    */
    "rest_api_version" => env('SHOPIFY_REST_API_VERSION', '2023-04'),

    /*
    |--------------------------------------------------------------------------
    | Local theme preview (development only)
    |--------------------------------------------------------------------------
    |
    | Development apps without Shopify's Online Store protected scope exemption can't write theme files. When this
    | is true and the theme update fails, the generated snippet is saved to storage/app/theme-preview instead.
    | It only works when APP_ENV is local, development (set by the Shopify CLI) or testing.
    |
    */
    "local_theme_preview" => (bool) env('SHOPIFY_LOCAL_THEME_PREVIEW', false),

    /*
    |--------------------------------------------------------------------------
    | Shopify billing
    |--------------------------------------------------------------------------
    |
    | You may want to charge merchants for using your app. Setting required to true will cause the EnsureShopifySession
    | middleware to also ensure that the session is for a merchant that has an active one-time payment or subscription.
    | If no payment is found, it starts off the process and sends the merchant to a confirmation URL so that they can
    | approve the purchase.
    |
    | Learn more about billing in our documentation: https://shopify.dev/docs/apps/billing
    |
    */
    "billing" => [
        "required" => false,

        // Example set of values to create a charge for $5 one time
        "chargeName" => "My Shopify App One-Time Billing",
        "amount" => 5.0,
        "currencyCode" => "USD", // Currently only supports USD
        "interval" => EnsureBilling::INTERVAL_ONE_TIME,
    ],

];
