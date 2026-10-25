<?php

namespace App\Models;

use App\Models\Concerns\HasSecretHints;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable overrides for mail/Socialite credentials that would
 * otherwise only live in .env. Never exposed via any API resource -- see
 * App\Providers\IntegrationSettingsServiceProvider for how these actually
 * take effect at runtime.
 *
 * It also holds the two "social login" switches. A provider is only on when its switch is on AND its client ID and secret
 * are filled in (here or in .env); the storefront learns which are on from the site-settings API, never the credentials.
 */
class IntegrationSetting extends Model
{
    /** Social login providers: the Socialite driver name => the credential columns and the .env/config keys behind them. */
    public const SOCIAL_PROVIDERS = [
        'google' => ['label' => 'Google', 'id' => 'google_client_id', 'secret' => 'google_client_secret', 'switch' => 'google_login_enabled'],
        'facebook' => ['label' => 'Facebook', 'id' => 'facebook_app_id', 'secret' => 'facebook_app_secret', 'switch' => 'facebook_login_enabled'],
    ];

    use HasSecretHints;

    /** The fields that hold a secret: encrypted at rest, never shown again once saved, kept when left blank on save. */
    public const SECRET_FIELDS = ['smtp_password', 'google_client_secret', 'facebook_app_secret'];

    /** Never serialised with its secrets, whatever calls toArray() or toJson() on it (the admin form is filled from toArray()). */
    protected $hidden = self::SECRET_FIELDS;

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
        'google_login_enabled',
        'facebook_login_enabled',
    ];

    protected $attributes = [
        'google_login_enabled' => false,
        'facebook_login_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'google_client_secret' => 'encrypted',
            'facebook_app_secret' => 'encrypted',
            'google_login_enabled' => 'boolean',
            'facebook_login_enabled' => 'boolean',
        ];
    }

    /**
     * The address a provider sends the browser back to: the API's own address (APP_URL) plus the path of the named callback route.
     * It is worked out here every time, never typed in anywhere and never taken from .env, so it cannot be blank or wrong by hand.
     * This is the exact address to register with the provider (Google Cloud > Credentials > Authorised redirect URIs).
     */
    public static function callbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').route("social.{$provider}.callback", [], false);
    }

    /**
     * Where a provider's sign-in stands: "on"; "not_set_up" (no client ID or secret yet, here or in .env: nothing can work, whatever
     * the switch says); or "off" (set up, and the admin has switched it off).
     */
    public static function socialLoginState(string $provider): string
    {
        $settings = null;

        try {
            $settings = static::query()->first();
        } catch (\Throwable) {
            // Not migrated yet: .env alone decides.
        }

        if (! self::hasCredentials($provider, $settings)) {
            return 'not_set_up';
        }

        $switch = self::SOCIAL_PROVIDERS[$provider]['switch'] ?? null;

        return $switch !== null && $settings?->{$switch} ? 'on' : 'off';
    }

    /**
     * Whether a provider has both a client ID and a secret to use: the admin's, else the server's .env.
     */
    public static function hasCredentials(string $provider, ?self $settings = null): bool
    {
        $fields = self::SOCIAL_PROVIDERS[$provider] ?? null;

        if ($fields === null) {
            return false;
        }

        $id = filled($settings?->{$fields['id']}) ? $settings->{$fields['id']} : config("services.{$provider}.client_id");
        $secret = filled($settings?->{$fields['secret']}) ? $settings->{$fields['secret']} : config("services.{$provider}.client_secret");

        return filled($id) && filled($secret);
    }

    /**
     * Whether "continue with {provider}" is on right now: switched on, and with credentials to work with.
     */
    public static function socialLoginEnabled(string $provider): bool
    {
        $fields = self::SOCIAL_PROVIDERS[$provider] ?? null;

        if ($fields === null) {
            return false;
        }

        try {
            $settings = static::query()->first();
        } catch (\Throwable) {
            // Not migrated yet: nothing is on.
            return false;
        }

        return (bool) ($settings?->{$fields['switch']}) && self::hasCredentials($provider, $settings);
    }

    /**
     * Which providers are on, as the public settings API reports them.
     *
     * @return array{google: bool, facebook: bool}
     */
    public static function socialLogin(): array
    {
        return [
            'google' => self::socialLoginEnabled('google'),
            'facebook' => self::socialLoginEnabled('facebook'),
        ];
    }
}
