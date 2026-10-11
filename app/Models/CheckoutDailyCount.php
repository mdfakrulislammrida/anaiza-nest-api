<?php

namespace App\Models;

use App\Support\Dhaka;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * How many visitors reached each stage on each day (Dhaka time) when there was nothing to contact them by. Only a number:
 * no token, no cart, nothing that identifies a visitor.
 */
class CheckoutDailyCount extends Model
{
    public $timestamps = false;

    protected $fillable = ['day', 'stage', 'visitors'];

    public static function bump(string $stage): void
    {
        $day = Dhaka::day();

        $updated = DB::table('checkout_daily_counts')->where(['day' => $day, 'stage' => $stage])->increment('visitors');

        if ($updated === 0) {
            try {
                DB::table('checkout_daily_counts')->insert(['day' => $day, 'stage' => $stage, 'visitors' => 1]);
            } catch (UniqueConstraintViolationException) {
                DB::table('checkout_daily_counts')->where(['day' => $day, 'stage' => $stage])->increment('visitors');
            }
        }
    }
}
