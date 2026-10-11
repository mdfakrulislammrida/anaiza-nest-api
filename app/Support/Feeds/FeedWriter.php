<?php

namespace App\Support\Feeds;

use App\Models\SiteSetting;
use XMLWriter;

/**
 * Writes feed items in each platform's file format.
 *
 * Google: RSS 2.0 with the g: namespace. Meta and TikTok: CSV with a header row, every field quoted only when it needs to be
 * and any double quote inside it doubled (RFC 4180), UTF-8, no byte order mark.
 */
final class FeedWriter
{
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    /** The CSV columns, per platform, in order. TikTok calls the id column sku_id. */
    private const CSV_COLUMNS = [
        'meta' => ['id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'additional_image_link', 'brand', 'gtin', 'mpn', 'item_group_id', 'google_product_category', 'product_type', 'custom_label_0', 'custom_label_1', 'custom_label_2'],
        'tiktok' => ['sku_id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'additional_image_link', 'brand', 'gtin', 'mpn', 'item_group_id', 'product_type', 'google_product_category', 'custom_label_0', 'custom_label_1', 'custom_label_2'],
    ];

    public static function price(int $amount): string
    {
        return number_format($amount, 2, '.', '').' BDT';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function google(array $items, SiteSetting $site): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::GOOGLE_NS);
        $xml->startElement('channel');
        $xml->writeElement('title', (string) ($site->site_name ?: config('app.name')));
        $xml->writeElement('link', $site->feedStorefrontUrl());
        $xml->writeElement('description', 'Products from '.($site->site_name ?: config('app.name')));

        foreach ($items as $item) {
            $xml->startElement('item');
            $fields = [
                'id' => $item['id'],
                'title' => $item['title'],
                'description' => $item['description'],
                'link' => $item['link'],
                'image_link' => $item['image_link'],
                'availability' => $item['in_stock'] ? 'in_stock' : 'out_of_stock',
                'price' => self::price($item['price']),
                'sale_price' => $item['sale_price'] !== null ? self::price($item['sale_price']) : null,
                'condition' => 'new',
                'brand' => $item['brand'],
                'gtin' => $item['gtin'],
                'mpn' => $item['mpn'],
                // Google's way of saying "this has no GTIN or MPN", so the item is not turned down for lacking one.
                'identifier_exists' => $item['identifier_exists'] ? null : 'no',
                'item_group_id' => $item['item_group_id'],
                'product_type' => $item['product_type'],
                'google_product_category' => $item['google_product_category'],
                'custom_label_0' => $item['custom_label_0'],
                'custom_label_1' => $item['custom_label_1'],
                'custom_label_2' => $item['custom_label_2'],
            ];

            foreach ($fields as $name => $value) {
                if (filled($value)) {
                    $xml->writeElementNs('g', $name, null, (string) $value);
                }
            }
            foreach ($item['additional_image_link'] as $url) {
                $xml->writeElementNs('g', 'additional_image_link', null, $url);
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function csv(array $items, string $platform): string
    {
        $columns = self::CSV_COLUMNS[$platform];
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $columns, ',', '"', '', "\n");

        foreach ($items as $item) {
            $values = [
                'id' => $item['id'],
                'sku_id' => $item['id'],
                'title' => $item['title'],
                'description' => $item['description'],
                // Meta: "in stock" and "out of stock", with a space. TikTok uses the same words.
                'availability' => $item['in_stock'] ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => self::price($item['price']),
                'sale_price' => $item['sale_price'] !== null ? self::price($item['sale_price']) : '',
                'link' => $item['link'],
                'image_link' => $item['image_link'] ?? '',
                'additional_image_link' => implode(',', $item['additional_image_link']),
                'brand' => $item['brand'],
                'gtin' => $item['gtin'] ?? '',
                'mpn' => $item['mpn'] ?? '',
                'item_group_id' => $item['item_group_id'] ?? '',
                'google_product_category' => $item['google_product_category'] ?? '',
                'product_type' => $item['product_type'],
                'custom_label_0' => $item['custom_label_0'],
                'custom_label_1' => $item['custom_label_1'],
                'custom_label_2' => $item['custom_label_2'],
            ];

            fputcsv($handle, array_map(fn (string $column) => $values[$column], $columns), ',', '"', '', "\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
