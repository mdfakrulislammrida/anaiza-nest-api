<?php

namespace App\Console\Commands;

use App\Support\EmailThumbs;
use Illuminate\Console\Command;

class BackfillOrderImages extends Command
{
    protected $signature = 'orders:backfill-images';

    protected $description = 'Give order lines placed before pictures were kept their small picture (from the product as it is now). Safe to run again.';

    public function handle(): int
    {
        $result = EmailThumbs::backfill();

        $this->info("Order line pictures: {$result['filled']} filled, {$result['left']} left without one (no product, or the product has no picture).");

        return self::SUCCESS;
    }
}
