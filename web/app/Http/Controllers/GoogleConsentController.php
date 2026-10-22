<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SessionTrait;
use App\Lib\ThemeScriptBuilder;
use App\Models\ConsentSetting;
use App\Models\RegionalConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GoogleConsentController extends Controller
{
    use SessionTrait;

    private const REGION_PATTERN = '/^\s*([a-z]{2}(-[a-z0-9]{1,3})?\s*)(,\s*[a-z]{2}(-[a-z0-9]{1,3})?\s*)*$/i';

    public function store(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();

        $consentEnabled = $request->boolean('google_consent_enabled');

        $validator = Validator::make($request->all(), $this->validationRules($consentEnabled));

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $consentFields = array_merge(ThemeScriptBuilder::CONSENT_FIELDS, ['wait_for_update']);

        $globalConsent = [];
        foreach ($consentFields as $field) {
            $globalConsent[$field] = $this->consentInput($field, $request->input($field));
        }

        if ($request->has('google_consent_enabled')) {
            $globalConsent['google_consent_enabled'] = $request->boolean('google_consent_enabled');
        }

        $regionalConsents = $request->input('regional_consents') ?? [];

        DB::transaction(function () use ($shop, $globalConsent, $regionalConsents, $consentFields) {
            $consentSetting = ConsentSetting::updateOrCreate(
                ['shop_domain' => $shop],
                $globalConsent
            );

            $existingRegions = RegionalConsent::where('consent_setting_id', $consentSetting->id)
                ->pluck('id', 'region')->toArray();

            foreach ($regionalConsents as $consent) {
                $region = trim((string) ($consent['region'] ?? ''));

                if ($region === '') {
                    continue;
                }

                // Only whitelisted fields are copied, so the request can never set consent_setting_id (or any other
                // column) and write into another shop's settings.
                $regionalValues = [];
                foreach ($consentFields as $field) {
                    $regionalValues[$field] = $this->consentInput($field, $consent[$field] ?? null);
                }

                RegionalConsent::updateOrCreate(
                    ['consent_setting_id' => $consentSetting->id, 'region' => $region],
                    $regionalValues
                );

                unset($existingRegions[$region]);
            }

            if (!empty($existingRegions)) {
                RegionalConsent::where('consent_setting_id', $consentSetting->id)
                    ->whereIn('id', array_values($existingRegions))
                    ->delete();
            }
        });

        return response()->json(['statusText' => 'Settings saved successfully']);
    }

    public function getStoredSettings(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        return response()->json(self::settingsForShop($session->getShop()));
    }

    /**
     * Stored Google Consent Mode settings of a shop (or the defaults when nothing was saved yet).
     *
     * @return array{global_consent: array, regional_consents: array}
     */
    public static function settingsForShop(string $shop): array
    {
        $consentSetting = ConsentSetting::where('shop_domain', $shop)->first();

        if (!$consentSetting) {
            return [
                'global_consent' => [
                    'ad_storage' => 'denied',
                    'analytics_storage' => 'denied',
                    'ad_user_data' => 'denied',
                    'ad_personalization' => 'denied',
                    'functionality_storage' => 'denied',
                    'personalization_storage' => 'denied',
                    'security_storage' => 'denied',
                    'wait_for_update' => 500,
                    'google_consent_enabled' => false,
                ],
                'regional_consents' => [],
            ];
        }

        return [
            'global_consent' => $consentSetting->toArray(),
            'regional_consents' => RegionalConsent::where('consent_setting_id', $consentSetting->id)->get()->toArray(),
        ];
    }

    /**
     * Value to store for a consent field. Missing values fall back to the defaults, so a record can always be saved.
     * (Values are whitelisted again when the theme snippet is rendered.)
     */
    private function consentInput(string $field, $value): string
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            return mb_substr(trim((string) $value), 0, 255);
        }

        return $field === 'wait_for_update' ? (string) ThemeScriptBuilder::DEFAULT_WAIT_FOR_UPDATE : 'denied';
    }

    /**
     * Strict rules apply while Google Consent Mode is enabled (the values are rendered into the storefront).
     * While it is disabled, the hidden fields may still hold older values, so they must not block saving.
     */
    private function validationRules(bool $consentEnabled): array
    {
        if ($consentEnabled) {
            $consentValueRule = 'required|string|in:' . implode(',', ThemeScriptBuilder::CONSENT_VALUES);
            $waitForUpdateRule = 'required|integer|min:0|max:' . ThemeScriptBuilder::MAX_WAIT_FOR_UPDATE;
            $regionRule = ['required', 'string', 'max:255', 'regex:' . self::REGION_PATTERN];
        } else {
            $consentValueRule = 'nullable|string|max:255';
            $waitForUpdateRule = 'nullable|max:255';
            $regionRule = ['nullable', 'string', 'max:255'];
        }

        $rules = [
            'google_consent_enabled' => 'sometimes|nullable|boolean',
            'wait_for_update' => $waitForUpdateRule,
            'regional_consents' => 'sometimes|nullable|array',
            'regional_consents.*' => 'array',
            'regional_consents.*.region' => $regionRule,
            'regional_consents.*.wait_for_update' => $waitForUpdateRule,
        ];

        foreach (ThemeScriptBuilder::CONSENT_FIELDS as $field) {
            $rules[$field] = $consentValueRule;
            $rules["regional_consents.*.$field"] = $consentValueRule;
        }

        return $rules;
    }
}
