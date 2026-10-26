<?php

namespace App\Console\Commands;

use App\Support\System\GoLiveCheck as Check;
use Illuminate\Console\Command;

class GoLiveCheck extends Command
{
    protected $signature = 'golive:check
        {--api-url=https://api.anaizanest.com : The address the API must be on}
        {--site-url=https://anaizanest.com : The address of the shop}';

    protected $description = 'PASS / WARN / FAIL for everything that must be right before the shop opens. Reads only: sends nothing, changes nothing, prints no secret.';

    public function handle(): int
    {
        $rows = Check::run((string) $this->option('api-url'), (string) $this->option('site-url'));

        foreach ($rows as $row) {
            $this->line(sprintf('%-4s  %-28s %s', $row['status'], $row['label'], $row['detail']));
        }

        $counts = array_count_values(array_column($rows, 'status'));
        $this->newLine();
        $this->line(sprintf('%d PASS, %d WARN, %d FAIL', $counts[Check::PASS] ?? 0, $counts[Check::WARN] ?? 0, $counts[Check::FAIL] ?? 0));

        return Check::worst($rows) === Check::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
