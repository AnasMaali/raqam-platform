<?php

use App\Models\Author;
use App\Models\Category;
use App\Models\Character;
use App\Models\Guest;
use App\Models\Page;
use App\Models\Playlist;
use App\Models\Service;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/* 23.6 beyond posts: every page addressed by a slug keeps its old address (301), and a deleted one answers 410. */

function redirectsNow(): array
{
    return DB::table('redirects')->orderBy('from_path')->get(['from_path', 'to_path', 'status_code'])
        ->map(fn ($r) => $r->from_path.' '.$r->status_code.' '.($r->to_path ?? '-'))->all();
}

dataset('addressed', [
    'category' => [fn () => Category::create(['name_ar' => 'طاقة متجددة', 'slug' => 'old-energy']), '/category/'],
    'tag' => [fn () => Tag::create(['name_ar' => 'وسم قديم', 'slug' => 'old-tag']), '/tag/'],
    'author' => [fn () => Author::create(['name' => 'سامر', 'slug' => 'old-author']), '/author/'],
    'guest' => [fn () => Guest::create(['name' => 'ضيف', 'slug' => 'old-guest']), '/guest/'],
    'character' => [fn () => Character::create(['name_ar' => 'المستشار', 'slug' => 'old-character', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']), '/awareness/characters/'],
    'service' => [fn () => Service::query()->findOrFail(insertService(['slug' => 'old-service'])), '/services/'],
    'page' => [fn () => Page::create(['title' => 'صفحة', 'slug' => 'old-page', 'is_published' => true]), '/'],
    'playlist' => [fn () => Playlist::create(['name_ar' => 'قائمة', 'slug' => 'old-playlist']), '/video/playlists/'],
]);

test('a changed slug leaves a 301 from the old address, without chains, and going back removes it', function (Closure $make, string $prefix) {
    $record = $make();
    $old = $record->slug;

    $record->update(['slug' => 'new-one']);
    $record->update(['slug' => 'newer-one']);
    expect(redirectsNow())->toBe([$prefix.'new-one 301 '.$prefix.'newer-one', $prefix.$old.' 301 '.$prefix.'newer-one']);

    $record->update(['slug' => $old]);
    expect(redirectsNow())->toBe([$prefix.'new-one 301 '.$prefix.$old, $prefix.'newer-one 301 '.$prefix.$old]);

    $lookup = api('/redirects/lookup?path='.rawurlencode($prefix.'newer-one'))->assertStatus(301);
    expect($lookup->json('to'))->toBe($prefix.$old);
})->with('addressed');

test('a deleted one answers 410, with its old addresses; made again at the same address, it is live again', function (Closure $make, string $prefix) {
    $record = $make();
    $record->update(['slug' => 'kept']);
    $class = $record::class;

    $record->delete();
    expect(redirectsNow())->toContain($prefix.'kept 410 -');
    expect(collect(redirectsNow())->filter(fn ($r) => str_contains($r, ' 301 '))->all())->toBe([]);   // the old address is gone too

    if (method_exists($record, 'restore')) {
        $record->restore();
    } else {
        $copy = $record->replicate();
        $copy->save();
    }
    expect(collect(redirectsNow())->filter(fn ($r) => str_starts_with($r, $prefix.'kept '))->all())->toBe([]);
    expect($class)->toBeString();
})->with('addressed');
