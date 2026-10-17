<?php

use App\Media\MediaRejected;
use App\Media\MediaUploader;
use App\Media\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake();
});

function storedSvg(string $svg): string
{
    $asset = app(MediaUploader::class)->store(uploadFile($svg), 'logo.svg', ['alt_text' => 'شعار'], null)->asset;

    return Storage::disk('media_local_public')->get($asset->path);
}

test('a clean SVG is kept and its drawing survives', function () {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="red"/></svg>');

    expect($out)->toContain('<circle')->and($out)->toContain('viewBox');
});

test('script elements are removed', function () {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><rect width="5" height="5"/></svg>');

    expect($out)->not->toContain('script')->not->toContain('alert')->and($out)->toContain('<rect');
});

test('event handler attributes are removed', function (string $attribute) {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg" '.$attribute.'><rect width="5" height="5" '.$attribute.'/></svg>');

    expect($out)->not->toMatch('/\bon\w+\s*=/i')->not->toContain('alert');
})->with(['onload="alert(1)"', 'onclick="alert(1)"', 'onmouseover="alert(1)"', 'onerror="alert(1)"']);

test('javascript: links are removed', function () {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><text>x</text></a><a href="javascript:alert(2)"><text>y</text></a></svg>');

    expect($out)->not->toContain('javascript');
});

test('foreignObject, which can carry HTML, is removed', function () {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe src="https://evil.example"></iframe><div onclick="alert(1)">x</div></foreignObject></svg>');

    expect($out)->not->toContain('foreignObject')->not->toContain('iframe')->not->toContain('evil.example');
});

test('references to outside files and addresses are removed', function () {
    $out = storedSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image href="https://tracker.example/pixel.png"/><use xlink:href="https://evil.example/x.svg#a"/></svg>');

    expect($out)->not->toContain('tracker.example')->not->toContain('evil.example');
});

test('a DOCTYPE or entity (XXE, "billion laughs") is refused outright', function (string $svg) {
    expect(fn () => (new SvgSanitizer)->clean($svg))->toThrow(MediaRejected::class);
})->with([
    'xxe' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'],
    'laughs' => ['<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>'],
]);

test('something that only looks like an SVG at the start is refused', function () {
    expect(fn () => (new SvgSanitizer)->clean('<svg'))->toThrow(MediaRejected::class);
});

test('HTML dressed as an SVG file is not accepted as one', function () {
    $e = null;
    try {
        app(MediaUploader::class)->store(uploadFile('<html><body><script>alert(1)</script></body></html>'), 'a.svg', ['alt_text' => 'x'], null);
    } catch (MediaRejected $e) {
    }

    expect($e)->not->toBeNull();
});

test('an SVG with a script, stored and read back, still has none', function () {
    $out = storedSvg("<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" onload=\"alert(1)\"><script type=\"text/javascript\">alert(1)</script><style>@import url(https://evil.example/x.css);</style><rect width=\"5\" height=\"5\"/></svg>");

    expect($out)->not->toContain('alert')->not->toContain('evil.example')->not->toContain('<script');
});
