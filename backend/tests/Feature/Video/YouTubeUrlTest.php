<?php

use App\Video\ChapterTime;
use App\Video\YouTubeClient;
use App\Video\YouTubeUrl;

test('every YouTube address shape yields the id', function (string $url, string $id, bool $short) {
    expect(YouTubeUrl::parse($url))->toBe(['id' => $id, 'is_short' => $short]);
})->with([
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'watch with other params' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42s', 'dQw4w9WgXcQ', false],
    'no scheme' => ['youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'short link with tracking' => ['https://youtu.be/dQw4w9WgXcQ?si=abc123', 'dQw4w9WgXcQ', false],
    'shorts' => ['https://www.youtube.com/shorts/aB3-_xYz09Q', 'aB3-_xYz09Q', true],
    'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ', false],
    'spaces around' => ["  https://youtu.be/dQw4w9WgXcQ \n", 'dQw4w9WgXcQ', false],
]);

test('anything else is refused', function (string $input) {
    expect(YouTubeUrl::parse($input))->toBeNull();
})->with([
    'empty' => [''],
    'another site' => ['https://vimeo.com/dQw4w9WgXcQ'],
    'look-alike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
    'look-alike prefix' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'],
    'credentials in the host' => ['https://youtube.com@evil.example/watch?v=dQw4w9WgXcQ'],
    'userinfo before the real host' => ['https://evil.example@youtube.com/watch?v=dQw4w9WgXcQ'],
    'too short id' => ['https://youtu.be/dQw4w9WgXc'],
    'too long id' => ['https://youtu.be/dQw4w9WgXcQQ'],
    'bad characters in id' => ['https://youtu.be/dQw4w9Wg<>Q'],
    'sql in id' => ["https://www.youtube.com/watch?v=x'; DROP TABLE"],
    'channel page' => ['https://www.youtube.com/@raqam'],
    'playlist only' => ['https://www.youtube.com/playlist?list=PLabcdefghijk'],
    'javascript' => ['javascript:alert(1)'],
    'other port' => ['https://youtu.be:8443/dQw4w9WgXcQ'],
    'newline smuggled' => ["https://youtu.be/dQw4w9WgXcQ\nhttps://evil.example"],
    'array id' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'],
    'very long' => ['https://youtu.be/'.str_repeat('a', 3000)],
]);

test('chapter times read as minutes and seconds, or hours', function (mixed $input, ?int $seconds) {
    expect(ChapterTime::parse($input))->toBe($seconds);
})->with([
    ['0:00', 0], ['1:15', 75], ['12:30', 750], ['1:02:03', 3723], ['75', 75], [75, 75], ['٣:٠٥', 185],
    ['1:75', null], ['1:60:00', null], ['abc', null], ['', null], ['-5', null], ['1:2', null], [null, null],
]);

test('chapter times are written back the same way', function () {
    expect(ChapterTime::format(75))->toBe('1:15')->and(ChapterTime::format(3723))->toBe('1:02:03')->and(ChapterTime::format(0))->toBe('0:00');
});

test('ISO durations from the API become seconds', function (string $iso, ?int $seconds) {
    expect(YouTubeClient::seconds($iso))->toBe($seconds);
})->with([['PT3M12S', 192], ['PT1H2M3S', 3723], ['PT45S', 45], ['P1DT1H', 90000], ['P0D', null], ['PT', null], ['junk', null], ['', null]]);
