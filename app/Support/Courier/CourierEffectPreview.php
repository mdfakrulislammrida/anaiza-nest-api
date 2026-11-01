<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Support\Email\TemplateDefinitions;

/**
 * Which orders WOULD change if the status mapping acted now, worked out from what is already stored on each order (the courier's last status),
 * with nothing written and no courier called. Not armed rows are treated as if they were: the point is to look before arming.
 *
 * It uses the same plan() the live sync uses, so the list and the later change cannot disagree.
 */
final class CourierEffectPreview
{
    /**
     * @return list<array{order: Order, number: string, courier: string, consignment: string, courier_status: string, status: string, new_status: string, cash: bool, email: string, sms: string, armed: bool, flag: bool}>
     */
    public static function rows(bool $notify = false): array
    {
        if (! CourierSetting::current()->status_sync_enabled) {
            return [];
        }

        $rows = [];

        Order::query()->notLegacy()
            ->whereNotNull('courier')->whereNotNull('courier_booked_at')->whereNotNull('courier_status')
            ->whereNull('courier_final_at')->whereNull('courier_returned_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$rows, $notify): void {
                foreach ($orders as $order) {
                    $map = CourierStatusMap::lookup((string) $order->courier, $order->courier_status);

                    if ($map === null) {
                        continue;
                    }

                    $plan = CourierStatusApplier::plan($order, $map);

                    // Worked out already while armed: the sync will not move the order again, only the cash can still be waiting.
                    if ($order->courier_effect_status === CourierStatusMap::normalise((string) $order->courier_status)) {
                        $plan['to'] = null;
                        $plan['flag'] = false;
                    }

                    if ($plan['to'] === null && ! $plan['cash'] && ! $plan['flag']) {
                        continue;
                    }

                    $order->loadMissing('customer');
                    $mailable = $plan['to'] !== null && isset(TemplateDefinitions::STATUS_TEMPLATES[$plan['to']]) && filled($order->recipient()['email']);
                    $old = CourierStatusApplier::oldNews($order, (string) $order->courier_status);

                    $rows[] = [
                        'order' => $order,
                        'number' => $order->number(),
                        'courier' => (string) $order->courier,
                        'consignment' => (string) $order->courier_consignment_id,
                        'courier_status' => (string) $order->courier_status,
                        'status' => (string) $order->status,
                        'new_status' => $plan['to'] ?? ($plan['flag'] ? 'unchanged (flagged returned)' : 'unchanged'),
                        'cash' => $plan['cash'],
                        'email' => ! $mailable ? 'no' : ($old && ! $notify ? 'no (old news)' : 'yes'),
                        'sms' => 'no (the shop sends no SMS)',
                        'armed' => (bool) $map->armed,
                        'flag' => $plan['flag'],
                    ];
                }
            });

        return $rows;
    }
}
