<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CookieConsentSetting;

class CookieConsentController extends Controller
{
    /**
     * The cookie banner's settings. A GET is always a 200: with nothing saved yet the plain defaults are served.
     */
    public function show()
    {
        $settings = CookieConsentSetting::current()->loadMissing('privacyPage');

        return response()->json(['data' => [
            'enabled' => $settings->enabled,
            'mode' => $settings->mode === 'opt_in' ? 'opt_in' : 'notice',
            'banner_text' => $settings->bannerText(),
            // The privacy page's current URL; null when none is chosen (or it has been deleted).
            'privacy_url' => $settings->privacyPage ? '/pages/'.$settings->privacyPage->slug : null,
            'privacy_label' => $settings->label('privacy'),
            'labels' => [
                'accept' => $settings->label('accept'),
                'reject' => $settings->label('reject'),
                'customize' => $settings->label('customize'),
                'save' => $settings->label('save'),
            ],
        ]]);
    }
}
