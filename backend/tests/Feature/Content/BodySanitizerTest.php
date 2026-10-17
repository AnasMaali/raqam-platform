<?php

use App\Content\Blocks\ImageBlock;
use App\Content\BodySanitizer;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake();
    $this->clean = fn (string $html, bool $blocks = true) => app(BodySanitizer::class)->clean($html, $blocks);
    $this->media = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'p.jpg', ['alt_text' => 'x'], null)->asset;
});

function block(string $id, array $config): string
{
    return '<div data-type="customBlock" data-id="'.$id.'" data-config="'.e(json_encode($config)).'"></div>';
}

test('ordinary article HTML survives', function () {
    $html = '<h2>عنوان</h2><p>فقرة <strong>غامقة</strong> و<em>مائلة</em> و<u>مسطّرة</u> و<s>مشطوبة</s><br>سطر</p><ul><li>أ</li><li>ب</li></ul><ol><li>1</li></ol><blockquote><p>اقتباس</p></blockquote><hr>';

    expect(($this->clean)($html))->toBe($html);
});

test('tables keep their structure and spans', function () {
    $html = '<table><thead><tr><th colspan="2">ع</th></tr></thead><tbody><tr><td>1</td><td rowspan="2">2</td></tr></tbody></table>';

    expect(($this->clean)($html))->toBe($html);
});

test('scripts and the usual XSS tricks are gone', function (string $payload) {
    $out = ($this->clean)('<p>قبل</p>'.$payload.'<p>بعد</p>');

    expect($out)->not->toMatch('/<script|javascript:|onerror|onload|onclick|onmouseover|<iframe|<object|<embed|<form|<svg|<img|<style|alert\(/i')
        ->and($out)->toContain('قبل')->toContain('بعد');
})->with([
    'script tag' => ['<script>alert(1)</script>'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'svg onload' => ['<svg onload=alert(1)><circle/></svg>'],
    'iframe' => ['<iframe src="https://evil.example"></iframe>'],
    'javascript link' => ['<a href="javascript:alert(1)">انقر</a>'],
    'javascript link, case and entity tricks' => ['<a href="JaVa&#115;cript:alert(1)">انقر</a>'],
    'data link' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
    'event handler on a paragraph' => ['<p onclick="alert(1)" onmouseover="alert(2)">نص</p>'],
    'style tag' => ['<style>body{display:none}</style>'],
    'form' => ['<form action="https://evil.example"><input name="x"><button>go</button></form>'],
    'object and embed' => ['<object data="x.swf"></object><embed src="x.swf">'],
    'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
    'base and meta' => ['<base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example">'],
    'video with onerror' => ['<video src=x onerror=alert(1)></video>'],
]);

test('broken markup meant to dodge a filter is neutralised (what follows it may be lost, nothing unsafe is kept)', function () {
    $out = ($this->clean)('<p>قبل</p><scr<script>ipt>alert(1)</scr</script>ipt><p>بعد</p>');

    expect($out)->not->toMatch('/<script|alert\(/i')->toContain('قبل');
});

test('style attributes and classes do not survive', function () {
    $out = ($this->clean)('<p style="position:fixed;top:0" class="x" id="y" dir="ltr">نص</p>');

    expect($out)->toBe('<p>نص</p>');
});

test('pictures cannot be put in the text by hand: no <img> at all', function () {
    expect(($this->clean)('<p><img src="https://tracker.example/pixel.gif" alt="x"></p>'))->not->toContain('img')->not->toContain('tracker');
});

test('links keep web, mail and phone addresses and always carry rel', function () {
    $out = ($this->clean)('<a href="https://example.com/a?b=1">1</a><a href="mailto:a@b.com">2</a><a href="tel:+970599123456">3</a><a href="/news/5/x">4</a><a href="http://example.com" target="_blank">5</a><a href="https://e.com" target="_top">6</a>');

    expect($out)->toContain('href="https://example.com/a?b=1"')->toContain('mailto:a@b.com')->toContain('tel:+970599123456')->toContain('href="/news/5/x"');
    expect($out)->toContain('target="_blank"')->not->toContain('target="_top"');
    expect(substr_count($out, 'rel="noopener noreferrer"'))->toBe(6);
});

test('a link to an unknown scheme loses its address but not its words', function () {
    $out = ($this->clean)('<a href="ftp://x.com/f">ملف</a><a href="file:///etc/passwd">ملف2</a>');

    expect($out)->not->toContain('ftp:')->not->toContain('file:')->toContain('ملف')->toContain('ملف2');
});

test('plain divs and spans lose their box but not their words', function () {
    expect(($this->clean)('<div>كلمات <span>وسطر</span></div>'))->toBe('كلمات وسطر');
});

test('text that is too long is refused rather than cut', function () {
    expect(fn () => ($this->clean)(str_repeat('ا', BodySanitizer::MAX_LENGTH + 1)))->toThrow(LengthException::class);
});

test('empty and tag-only input gives an empty body', function () {
    expect(($this->clean)(''))->toBe('')->and(($this->clean)('   '))->toBe('')->and(($this->clean)('<script>x</script>'))->toBe('');
});

// ---- blocks --------------------------------------------------------------------------------------

test('a valid image block is kept as a bare reference, rebuilt from scratch', function () {
    $out = ($this->clean)('<div data-type="customBlock" data-id="image" data-config=\'{"media_id":'.$this->media->id.',"caption":"تعليق <b>x</b>","evil":"<script>"}\' class="x" onclick="alert(1)">محتوى <script>alert(1)</script></div>');

    expect($out)->toBe('<div data-type="customBlock" data-id="image" data-config=\'{"media_id":'.$this->media->id.',"caption":"تعليق x"}\'></div>');
});

test('a block that points at nothing real is dropped', function (string $id, array $config) {
    expect(($this->clean)('<p>نص</p>'.block($id, $config)))->toBe('<p>نص</p>');
})->with([
    'image missing' => ['image', ['media_id' => 987654]],
    'image without id' => ['image', []],
    'image id as text' => ['image', ['media_id' => 'abc']],
    'image negative' => ['image', ['media_id' => -1]],
    'image huge' => ['image', ['media_id' => '99999999999999999999']],
    'image as array' => ['image', ['media_id' => [1, 2]]],
    'gallery of one' => ['gallery', ['media_ids' => [1]]],
    'gallery empty' => ['gallery', ['media_ids' => []]],
    'gallery too many' => ['gallery', ['media_ids' => range(1, 40)]],
    'gallery not a list' => ['gallery', ['media_ids' => 'x']],
    'awareness missing' => ['awareness', ['post_id' => 987654]],
    'read-also empty' => ['read-also', ['post_ids' => []]],
    'unknown block' => ['script', ['x' => 1]],
    'video that does not exist' => ['video', ['video_id' => 987654]],
]);

test('a block needs the customBlock type and valid JSON', function () {
    expect(($this->clean)('<div data-type="other" data-id="image" data-config=\'{"media_id":'.$this->media->id.'}\'></div>'))->toBe('')
        ->and(($this->clean)('<div data-type="customBlock" data-id="image" data-config="{not json"></div>'))->toBe('');
});

test('a private picture, a PDF or a trashed picture cannot be used in a block', function () {
    Storage::fake('media_local_private');
    $uploader = app(MediaUploader::class);
    $private = $uploader->store(uploadFile(imageBytes(301, 200)), 'a.jpg', ['visibility' => 'private'], null)->asset;
    $pdf = $uploader->store(uploadFile("%PDF-1.4\n%%EOF"), 'a.pdf', [], null)->asset;
    $trashed = $uploader->store(uploadFile(imageBytes(302, 200)), 'b.jpg', ['alt_text' => 'x'], null)->asset;
    app(MediaTrash::class)->trash($trashed);

    foreach ([$private->id, $pdf->id, $trashed->id] as $id) {
        expect(($this->clean)(block('image', ['media_id' => $id])))->toBe('');
    }
});

test('a gallery keeps its order and drops pictures that no longer exist, but needs two left', function () {
    $second = app(MediaUploader::class)->store(uploadFile(imageBytes(303, 200)), 'c.jpg', ['alt_text' => 'x'], null)->asset;
    $third = app(MediaUploader::class)->store(uploadFile(imageBytes(304, 200)), 'd.jpg', ['alt_text' => 'x'], null)->asset;

    $out = ($this->clean)(block('gallery', ['media_ids' => [$third->id, 99999, $this->media->id, $second->id]]));
    expect($out)->toContain('"media_ids":['.$third->id.','.$this->media->id.','.$second->id.']');

    expect(($this->clean)(block('gallery', ['media_ids' => [$third->id, 99999]])))->toBe('');
});

test('read-also and awareness blocks keep only posts that exist', function () {
    $news = insertPost();
    $card = insertPost(['kind_code' => 'awareness']);
    $gone = insertPost(['deleted_at' => now()]);

    expect(($this->clean)(block('read-also', ['post_ids' => [$news, 99999, $gone]])))->toContain('"post_ids":['.$news.']');
    expect(($this->clean)(block('awareness', ['post_id' => $card])))->toContain('"post_id":'.$card);
    expect(($this->clean)(block('awareness', ['post_id' => $news])))->toBe('');   // a news item is not an awareness card
});

test('blocks are refused where blocks are not allowed (static pages)', function () {
    expect(($this->clean)(block('image', ['media_id' => $this->media->id]).'<p>نص</p>', blocks: false))->toBe('<p>نص</p>');
});

test('a block in the middle of the text stays where it is', function () {
    $out = ($this->clean)('<p>أول</p>'.block('image', ['media_id' => $this->media->id]).'<p>آخر</p>');

    expect($out)->toStartWith('<p>أول</p><div data-type="customBlock" data-id="image"')->toEndWith('</div><p>آخر</p>');
});

test('cleaning twice changes nothing', function () {
    $once = ($this->clean)('<h2>عنوان</h2><p>نص <a href="https://e.com" target="_blank">رابط</a></p>'.block('image', ['media_id' => $this->media->id, 'caption' => 'ت']));

    expect(($this->clean)($once))->toBe($once);
});

test('what a block shows is made from the database at display time', function () {
    $html = ImageBlock::render(['media_id' => $this->media->id, 'caption' => 'قلعة <b>نابلس</b>']);

    expect($html)->toContain('<figure class="rq-image">')->toContain('<picture>')->toContain('alt="x"')->toContain('قلعة &lt;b&gt;نابلس&lt;/b&gt;')->not->toContain('<b>');

    DB::table('media_assets')->where('id', $this->media->id)->update(['deleted_at' => now()]);
    expect(ImageBlock::render(['media_id' => $this->media->id]))->toBe('');
});
