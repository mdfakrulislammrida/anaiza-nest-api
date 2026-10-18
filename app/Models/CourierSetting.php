<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings > Courier: the one row of courier credentials and choices. Every secret is stored encrypted and is never sent to
 * the storefront, the API, a log or the admin page again once saved (the page only says "saved").
 */
class CourierSetting extends Model
{
    public const STEADFAST = 'steadfast';

    public const PATHAO = 'pathao';

    public const COURIERS = [
        self::STEADFAST => 'Steadfast',
        self::PATHAO => 'Pathao',
    ];

    /** The fields that hold a secret: encrypted at rest, blank in the admin form, kept when left blank on save. */
    public const SECRETS = [
        'steadfast_api_key',
        'steadfast_secret_key',
        'steadfast_webhook_token',
        'pathao_client_id',
        'pathao_client_secret',
        'pathao_username',
        'pathao_password',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'auto_book' => 'boolean',
            'default_weight' => 'float',
            'steadfast_enabled' => 'boolean',
            'steadfast_api_key' => 'encrypted',
            'steadfast_secret_key' => 'encrypted',
            'steadfast_webhook_token' => 'encrypted',
            'steadfast_auth_failed_at' => 'datetime',
            'steadfast_webhook_last_at' => 'datetime',
            'pathao_enabled' => 'boolean',
            'pathao_sandbox' => 'boolean',
            'pathao_client_id' => 'encrypted',
            'pathao_client_secret' => 'encrypted',
            'pathao_username' => 'encrypted',
            'pathao_password' => 'encrypted',
            'pathao_access_token' => 'encrypted',
            'pathao_refresh_token' => 'encrypted',
            'pathao_token_expires_at' => 'datetime',
            'pathao_auth_failed_at' => 'datetime',
        ];
    }

    /** Never serialised with its secrets, whatever calls toArray() or toJson() on it. */
    protected $hidden = [
        'steadfast_api_key', 'steadfast_secret_key', 'steadfast_webhook_token',
        'pathao_client_id', 'pathao_client_secret', 'pathao_username', 'pathao_password',
        'pathao_access_token', 'pathao_refresh_token',
    ];

    public static function current(): self
    {
        $row = static::query()->firstOrCreate([]);

        // A row just made holds only what was given; read it back so the database's own defaults (weight 0.5, delivery type 48, ...) are on it.
        return $row->wasRecentlyCreated ? $row->fresh() : $row;
    }

    public function hasSteadfastKeys(): bool
    {
        return filled($this->steadfast_api_key) && filled($this->steadfast_secret_key);
    }

    public function hasPathaoLogin(): bool
    {
        return filled($this->pathao_client_id) && filled($this->pathao_client_secret)
            && filled($this->pathao_username) && filled($this->pathao_password);
    }

    /** Switched on, with everything it needs. A Steadfast 401 pauses it until the keys are saved again. */
    public function steadfastReady(): bool
    {
        return $this->steadfast_enabled && $this->hasSteadfastKeys() && $this->steadfast_auth_failed_at === null;
    }

    public function pathaoReady(): bool
    {
        return $this->pathao_enabled && $this->hasPathaoLogin() && filled($this->pathao_store_id) && $this->pathao_auth_failed_at === null;
    }

    public function ready(string $courier): bool
    {
        return match ($courier) {
            self::STEADFAST => $this->steadfastReady(),
            self::PATHAO => $this->pathaoReady(),
            default => false,
        };
    }

    /** @return list<string> the couriers that can take a booking right now */
    public function readyCouriers(): array
    {
        return array_values(array_filter(array_keys(self::COURIERS), fn (string $courier): bool => $this->ready($courier)));
    }

    /** The courier a booking uses when none is chosen: the default if it is ready, else the only one that is. */
    public function defaultCourier(): ?string
    {
        $ready = $this->readyCouriers();

        if ($this->default_courier !== null && in_array($this->default_courier, $ready, true)) {
            return $this->default_courier;
        }

        return count($ready) === 1 ? $ready[0] : null;
    }

    public function pathaoBaseUrl(): string
    {
        return $this->pathao_sandbox ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com';
    }

    /** The courier's public tracking page for a code, from the template the owner entered ({code} stands for the code). Null when none. */
    public function trackingUrl(string $courier, ?string $code): ?string
    {
        $template = $courier === self::STEADFAST ? $this->steadfast_tracking_url : $this->pathao_tracking_url;

        if (blank($template) || blank($code) || ! str_starts_with((string) $template, 'https://')) {
            return null;
        }

        return str_replace('{code}', rawurlencode((string) $code), (string) $template);
    }
}
