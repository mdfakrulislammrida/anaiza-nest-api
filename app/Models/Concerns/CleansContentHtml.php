<?php

namespace App\Models\Concerns;

use App\Support\ContentHtml;

/**
 * Long written content (a category's long description, a page, an article) is cleaned by `ContentHtml` whenever it is
 * written. Only a field that was changed is looked at, and content with nothing to remove is stored exactly as given.
 * What was removed is kept on the instance, so the admin can say so in the save notification.
 */
trait CleansContentHtml
{
    /** @var list<string> */
    private array $contentHtmlReport = [];

    /**
     * The attributes that hold long written content.
     *
     * @return list<string>
     */
    abstract protected function contentHtmlAttributes(): array;

    protected static function bootCleansContentHtml(): void
    {
        static::saving(function (self $model): void {
            $model->contentHtmlReport = [];

            foreach ($model->contentHtmlAttributes() as $attribute) {
                if (! $model->isDirty($attribute) || ! is_string($model->getAttribute($attribute))) {
                    continue;
                }

                $result = ContentHtml::clean($model->getAttribute($attribute));

                if ($result['changed']) {
                    $model->setAttribute($attribute, $result['html']);
                    $model->contentHtmlReport = [...$model->contentHtmlReport, ...$result['removed']];
                }
            }
        });
    }

    /**
     * What the last save took out, in plain sentences.
     *
     * @return list<string>
     */
    public function contentHtmlReport(): array
    {
        return array_values(array_unique($this->contentHtmlReport));
    }
}
