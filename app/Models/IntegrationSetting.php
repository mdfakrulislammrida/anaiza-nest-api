<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable overrides for mail/Socialite credentials that would
 * otherwise only live in .env. Never exposed via any API resource -- see
 * App\Providers\IntegrationSettingsServiceProvider for how these actually
 * take effect at runtime.
 */
class IntegrationSetting extends Model
{
    protected $fillable = [
        'mailer',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
        'google_client_id',
        'google_client_secret',
        'facebook_app_id',
        'facebook_app_secret',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'google_client_secret' => 'encrypted',
            'facebook_app_secret' => 'encrypted',
        ];
    }
}
