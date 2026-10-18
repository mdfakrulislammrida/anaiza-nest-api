<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;

/** The courier's name for people ("Steadfast"), from its key ("steadfast"). */
final class CourierSettingNames
{
    public static function label(?string $courier): string
    {
        return CourierSetting::COURIERS[$courier] ?? (string) $courier;
    }
}
