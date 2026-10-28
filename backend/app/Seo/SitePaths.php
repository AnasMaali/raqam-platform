<?php

namespace App\Seo;

/**
 * The public addresses of everything that is not a post (decision 23.3), in one place, so the menus, the sitemaps and the
 * feeds give the website the same address for the same thing. Posts have their own (App\Content\PostPath).
 */
final class SitePaths
{
    public static function category(string $slug): string
    {
        return '/category/'.$slug;
    }

    public static function tag(string $slug): string
    {
        return '/tag/'.$slug;
    }

    public static function author(string $slug): string
    {
        return '/author/'.$slug;
    }

    public static function guest(string $slug): string
    {
        return '/guest/'.$slug;
    }

    /** A section lives at its own fixed address: /podcast, /success-stories, … */
    public static function section(string $slug): string
    {
        return '/'.$slug;
    }

    /** A static page lives at the root; the reserved words (news, podcast, api, …) can never be a page's slug. */
    public static function page(string $slug): string
    {
        return '/'.$slug;
    }

    public static function character(string $slug): string
    {
        return '/awareness/characters/'.$slug;
    }

    public static function playlist(string $slug): string
    {
        return '/video/playlists/'.$slug;
    }

    public static function video(int $id): string
    {
        return '/video/'.$id;
    }

    public static function service(string $slug): string
    {
        return '/services/'.$slug;
    }

    /** One currency's page («سعر الدولار مقابل الشيكل اليوم»): its ISO code in lower case. */
    public static function currency(string $code): string
    {
        return '/currencies/'.strtolower(trim($code));
    }

    public static function city(string $slug): string
    {
        return '/weather/'.$slug;
    }
}
