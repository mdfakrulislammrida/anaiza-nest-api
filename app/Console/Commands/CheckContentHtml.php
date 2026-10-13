<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\Page;
use App\Support\ContentHtml;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * A read-only look at long written content already stored: which category descriptions, pages and articles hold
 * something the shop no longer serves (scripts, styles, classes, frames), and which hold tables. Nothing is changed.
 * Run it after an upgrade, before anyone edits the content: php artisan content:check
 */
class CheckContentHtml extends Command
{
    protected $signature = 'content:check';

    protected $description = 'List stored category descriptions, pages and articles that hold HTML the shop cleans (read only).';

    public function handle(): int
    {
        $found = 0;

        $found += $this->scan('Category', Category::query()->get(['id', 'name', 'seo_description']), 'seo_description', 'name');
        $found += $this->scan('Page', Page::query()->get(['id', 'title', 'content']), 'content', 'title');
        $found += $this->scan('Article', Article::query()->get(['id', 'title', 'content']), 'content', 'title');

        $this->line($found === 0
            ? 'Nothing stored holds HTML that is cleaned. Nothing would change.'
            : "{$found} item(s) hold HTML that is cleaned when served. Nothing was changed; it is rewritten only if the item is saved with a change to that text.");

        return self::SUCCESS;
    }

    /**
     * @param  iterable<Model>  $records
     */
    private function scan(string $kind, iterable $records, string $attribute, string $label): int
    {
        $found = 0;

        foreach ($records as $record) {
            $html = (string) $record->getAttribute($attribute);
            $result = ContentHtml::clean($html);
            $hasTable = (bool) preg_match('/<table\b/i', $html);

            if (! $result['changed'] && ! $hasTable) {
                continue;
            }

            $notes = [];

            if ($hasTable) {
                $notes[] = 'has a table (kept)';
            }

            if ($result['changed']) {
                $found++;
                $notes = [...$notes, ...$result['removed']];
            }

            $this->line("{$kind} #{$record->getKey()} ".$record->getAttribute($label).': '.implode(' ', $notes));
        }

        return $found;
    }
}
