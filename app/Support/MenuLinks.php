<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Page;
use Illuminate\Support\Str;

/**
 * Turns the menu items the admin builds (header and footer) into the links the storefront shows.
 *
 * An item is a Page, a Category, the Blog or a Custom URL. Page and Category items store only the record
 * id, and the slug is looked up each time the menu is served, so renaming a slug never breaks a menu. An
 * item whose page or category has been deleted simply drops out. Items saved before types existed have no
 * type and are read as Custom URL items.
 */
final class MenuLinks
{
    public const TYPES = [
        'page' => 'A page',
        'category' => 'A category',
        'blog' => 'The blog',
        'custom' => 'A custom URL',
    ];

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return list<array{label: string, url: string, children: list<array{label: string, url: string}>}>
     */
    public static function resolve(?array $items): array
    {
        $items = array_values(array_filter($items ?? [], 'is_array'));

        [$pages, $categories] = self::lookups($items);

        $links = [];

        foreach ($items as $item) {
            $link = self::link($item, $pages, $categories);

            if ($link === null) {
                continue;
            }

            $children = [];
            foreach (array_values(array_filter($item['children'] ?? [], 'is_array')) as $child) {
                $resolved = self::link($child, $pages, $categories);
                if ($resolved !== null) {
                    $children[] = $resolved;
                }
            }

            $links[] = $link + ['children' => $children];
        }

        return $links;
    }

    /**
     * Footer columns: each keeps its title and resolved items. A column with no title or no working item is dropped.
     *
     * @param  array<int, array<string, mixed>>|null  $columns
     * @return list<array{title: string, items: list<array{label: string, url: string}>}>
     */
    public static function resolveColumns(?array $columns): array
    {
        $out = [];

        foreach (array_slice(array_values(array_filter($columns ?? [], 'is_array')), 0, 3) as $column) {
            $title = trim((string) ($column['title'] ?? ''));
            $items = array_map(
                fn (array $link): array => ['label' => $link['label'], 'url' => $link['url']],
                self::resolve($column['items'] ?? []),
            );

            if ($title !== '' && $items !== []) {
                $out[] = ['title' => $title, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Adds every category that is in the menu under the Shop item (the first item that points at /shop).
     * A category already listed under it by hand is not repeated. With no Shop item there is nowhere to put them.
     *
     * @param  list<array{label: string, url: string, children: list<array{label: string, url: string}>}>  $links
     * @return list<array{label: string, url: string, children: list<array{label: string, url: string}>}>
     */
    public static function withAutoCategories(array $links): array
    {
        foreach ($links as $index => $link) {
            if ($link['url'] !== '/shop') {
                continue;
            }

            $have = array_column($link['children'], 'url');

            foreach (Category::query()->where('show_in_menu', true)->orderBy('menu_order')->orderBy('name')->get(['name', 'slug']) as $category) {
                $url = '/category/'.$category->slug;

                if (! in_array($url, $have, true)) {
                    $links[$index]['children'][] = ['label' => $category->name, 'url' => $url];
                }
            }

            break;
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, Page>  $pages
     * @param  array<int, Category>  $categories
     * @return array{label: string, url: string}|null
     */
    private static function link(array $item, array $pages, array $categories): ?array
    {
        $type = $item['type'] ?? 'custom';
        $label = trim((string) ($item['label'] ?? ''));

        switch ($type) {
            case 'page':
                $page = $pages[(int) ($item['page_id'] ?? 0)] ?? null;

                return $page ? ['label' => $label !== '' ? $label : $page->title, 'url' => '/pages/'.$page->slug] : null;

            case 'category':
                $category = $categories[(int) ($item['category_id'] ?? 0)] ?? null;

                return $category ? ['label' => $label !== '' ? $label : $category->name, 'url' => '/category/'.$category->slug] : null;

            case 'blog':
                return ['label' => $label !== '' ? $label : 'Blog', 'url' => '/blog'];

            default:
                $url = trim((string) ($item['url'] ?? ''));

                return $label !== '' && self::safeUrl($url) ? ['label' => $label, 'url' => $url] : null;
        }
    }

    /**
     * Anything but a script-running scheme. Menus have always taken relative paths and full addresses.
     */
    private static function safeUrl(string $url): bool
    {
        return $url !== '' && ! Str::startsWith(Str::lower($url), ['javascript:', 'data:', 'vbscript:']) && ! Str::startsWith($url, '//');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, Page>, 1: array<int, Category>}
     */
    private static function lookups(array $items): array
    {
        $pageIds = [];
        $categoryIds = [];

        $collect = function (array $item) use (&$pageIds, &$categoryIds): void {
            if (($item['type'] ?? null) === 'page' && ! empty($item['page_id'])) {
                $pageIds[] = (int) $item['page_id'];
            }
            if (($item['type'] ?? null) === 'category' && ! empty($item['category_id'])) {
                $categoryIds[] = (int) $item['category_id'];
            }
        };

        foreach ($items as $item) {
            $collect($item);
            foreach (array_filter($item['children'] ?? [], 'is_array') as $child) {
                $collect($child);
            }
        }

        return [
            $pageIds === [] ? [] : Page::query()->whereIn('id', array_unique($pageIds))->get(['id', 'title', 'slug'])->keyBy('id')->all(),
            $categoryIds === [] ? [] : Category::query()->whereIn('id', array_unique($categoryIds))->get(['id', 'name', 'slug'])->keyBy('id')->all(),
        ];
    }
}
