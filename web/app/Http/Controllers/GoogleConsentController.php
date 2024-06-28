<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SessionTrait;
use App\Models\ConsentSetting;
use App\Models\RegionalConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoogleConsentController extends Controller
{
    use SessionTrait;

    public function store(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();

        $globalConsent = $request->only([
            'ad_storage',
            'analytics_storage',
            'ad_user_data',
            'ad_personalization',
            'functionality_storage',
            'personalization_storage',
            'security_storage',
            'wait_for_update',
            'google_consent_enabled',
        ]);

        $regionalConsents = $request->input('regional_consents', []);

        try {
            $this->validateRegionalConsents($regionalConsents);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        }

        $consentSetting = ConsentSetting::updateOrCreate(
            ['shop_domain' => $shop],
            $globalConsent
        );

        if (empty($regionalConsents)) {
            RegionalConsent::where('consent_setting_id', $consentSetting->id)->delete();
        } else {
            $existingRegions = RegionalConsent::where('consent_setting_id', $consentSetting->id)
                ->pluck('id', 'region')->toArray();

            foreach ($regionalConsents as $consent) {
                $region = $consent['region'];
                unset($consent['region']);

                RegionalConsent::updateOrCreate(
                    ['consent_setting_id' => $consentSetting->id, 'region' => $region],
                    $consent
                );

                unset($existingRegions[$region]);
            }

            if (!empty($existingRegions)) {
                RegionalConsent::whereIn('id', $existingRegions)->delete();
            }
        }

        return response()->json(['statusText' => 'Settings saved successfully']);
    }


    public function getStoredSettings(Request $request)
    {
        $session = $this->loadCurrentSession($request);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 401);
        }

        $shop = $session->getShop();
        $consentSetting = ConsentSetting::where('shop_domain', $shop)->first();

        if (!$consentSetting) {
            return response()->json(['error' => 'Settings not found'], 404);
        }

        $globalConsent = $consentSetting->toArray();
        $regionalConsents = RegionalConsent::where('consent_setting_id', $consentSetting->id)->get();

        return response()->json([
            'global_consent' => $globalConsent,
            'regional_consents' => $regionalConsents,
        ]);
    }

    private function validateRegionalConsents(array $regionalConsents)
    {
        $validator = Validator::make(['regional_consents' => $regionalConsents], [
            'regional_consents.*.region' => [
                'required',
                'regex:/^\s*([a-z]{2}(-[a-z0-9]{1,3})?\s*)(,\s*[a-z]{2}(-[a-z0-9]{1,3})?\s*)*$/i'
            ]
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }
    }
}
