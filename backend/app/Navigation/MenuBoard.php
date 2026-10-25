<?php

namespace App\Navigation;

use App\Jobs\SendSiteWebhook;
use App\Seo\SitePaths;
use Illuminate\Support\Facades\DB;

/**
 * The menus as the website draws them (decision 21.12): the header and the two footer lists, each link with its address
 * already made. A link the admin hid is left out, and so is one whose destination a visitor cannot open now (an
 * unpublished page, a hidden category or section): the site never shows a link to a missing page. A top-level link
 * that is left out takes its children with it.
 */
final class MenuBoard
{
    /** @return array<string, list<array<string, mixed>>> menu key => links in order */
    public function all(): array
    {
        $menus = DB::table('menus')->orderBy('id')->pluck('id', 'key');
        $rows = DB::table('menu_items')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        $pages = DB::table('pages')->where('is_published', true)->whereIn('id', $rows->pluck('page_id')->filter())->pluck('slug', 'id');
        $categories = DB::table('categories')->where('is_active', true)->whereIn('id', $rows->pluck('category_id')->filter())->pluck('slug', 'id');
        $sections = DB::table('sections')->where('is_visible', true)->whereIn('id', $rows->pluck('section_id')->filter())->pluck('slug', 'id');

        $href = function (object $row) use ($pages, $categories, $sections): ?string {
            return match (true) {
                $row->page_id !== null => isset($pages[$row->page_id]) ? SitePaths::page($pages[$row->page_id]) : null,
                $row->category_id !== null => isset($categories[$row->category_id]) ? SitePaths::category($categories[$row->category_id]) : null,
                $row->section_id !== null => isset($sections[$row->section_id]) ? SitePaths::section($sections[$row->section_id]) : null,
                default => $row->url,
            };
        };
        $link = function (object $row, string $href): array {
            return ['label' => $row->label_ar, 'href' => $href, 'external' => ! str_starts_with($href, '/'), 'opens_new_tab' => (bool) $row->opens_new_tab, 'children' => []];
        };

        $out = [];
        foreach ($menus as $key => $menuId) {
            $mine = $rows->where('menu_id', $menuId);
            $tree = [];
            foreach ($mine->whereNull('parent_id') as $row) {
                if (($to = $href($row)) !== null) {
                    $tree[$row->id] = $link($row, $to);
                }
            }
            foreach ($mine->whereNotNull('parent_id') as $row) {
                if (isset($tree[$row->parent_id]) && ($to = $href($row)) !== null) {
                    $tree[$row->parent_id]['children'][] = $link($row, $to);
                }
            }
            $out[$key] = array_values($tree);
        }

        return $out;
    }

    /** Tells the website to draw its menus again (every page carries them). */
    public static function changed(string $change): void
    {
        SendSiteWebhook::notify(['type' => 'menus', 'change' => $change, 'paths' => []]);
    }

    /**
     * A page, category or section changed in a way that changes a menu link (its address, or whether it can be opened):
     * the menus are drawn again, but only when some active link points at it.
     *
     * @param  'page_id'|'category_id'|'section_id'  $column
     */
    public static function targetChanged(string $column, int $id): void
    {
        if (DB::table('menu_items')->where($column, $id)->where('is_active', true)->exists()) {
            self::changed('target');
        }
    }
}
