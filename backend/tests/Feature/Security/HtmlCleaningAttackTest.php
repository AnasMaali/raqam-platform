<?php

use App\Content\BodyRenderer;
use App\Content\BodySanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * More of what an attacker (or a hostile paste) would try on the rich-text field, on top of the usual list in
 * BodySanitizerTest: browser-parser tricks (mutation XSS), hidden characters inside a scheme, markup that changes
 * meaning when it is parsed twice. What is saved is also what the website is given, so the check is on both the
 * stored text and the HTML the API serves.
 */
const DANGER = '/<script|<iframe|<object|<embed|<form|<svg|<math|<img|<style|<link|<meta|<base|<template|<noscript|<xmp|<plaintext|<video|<audio|<input|<button|javascript:|vbscript:|data:text|expression\(/i';

/** Reads the HTML the way a browser does: no event-handler or style attribute anywhere, and every link on http, https, mailto, tel or the site. */
function expectInert(string $html): void
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    foreach ((new DOMXPath($document))->query('//*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect(strtolower($attribute->name))->not->toStartWith('on')->not->toBeIn(['style', 'srcdoc', 'formaction', 'src']);
        }
        if ($element->nodeName === 'a' && $element->hasAttribute('href')) {
            $href = preg_replace('/[\x00-\x20]+/', '', $element->getAttribute('href'));
            expect($href)->toMatch('#^(https?:|mailto:|tel:|/|\#|\?|[^:]*$)#i');
        }
    }
}

beforeEach(function () {
    $this->clean = fn (string $html) => app(BodySanitizer::class)->clean($html);
});

test('parser tricks and hidden characters give no live markup', function (string $payload) {
    $stored = ($this->clean)('<p>قبل</p>'.$payload.'<p>بعد</p>');
    expect($stored)->not->toMatch(DANGER);
    expectInert($stored);

    // Cleaning what was already cleaned changes nothing (a second pass must not "wake" anything up).
    expect(($this->clean)($stored))->toBe($stored);
    expect(app(BodyRenderer::class)->html($stored))->not->toMatch(DANGER);
    expectInert(app(BodyRenderer::class)->html($stored));
})->with([
    'noscript mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
    'style inside svg inside a table' => ['<table><svg><style><img src=x onerror=alert(1)></style></svg></table>'],
    'math namespace confusion' => ['<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=x onerror=alert(1)&gt;">'],
    'form inside form' => ['<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>'],
    'tab inside the scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
    'newline inside the scheme' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
    'carriage return inside the scheme' => ["<a href=\"jav\rascript:alert(1)\">x</a>"],
    'leading space and control characters' => ["<a href=\"  \x01javascript:alert(1)\">x</a>"],
    'entity-encoded scheme' => ['<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>'],
    'hex entity without semicolon' => ['<a href="&#x6A&#x61vascript:alert(1)">x</a>'],
    'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
    'data html link' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
    'protocol-relative link keeps no script' => ['<a href="//evil.example/x" onclick="alert(1)">x</a>'],
    'attribute breakout in a title' => ['<a href="https://ok.example" title="x&quot; onmouseover=&quot;alert(1)">x</a>'],
    'backtick attribute' => ['<p title=`x` onmouseover=alert(1)>x</p>'],
    'srcdoc and formaction' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe><button formaction="https://evil.example">x</button>'],
    'comment and CDATA games' => ['<!--><script>alert(1)</script>--><![CDATA[<script>alert(1)</script>]]>'],
    'null byte in a tag' => ["<scr\0ipt>alert(1)</scr\0ipt>"],
    'uppercase and odd spacing' => ['<SCRIPT/XSS SRC="https://evil.example/x.js"></SCRIPT><IMG """><SCRIPT>alert("XSS")</SCRIPT>">'],
    'xmp and plaintext' => ['<xmp><img src=x onerror=alert(1)></xmp><plaintext><img src=x onerror=alert(1)>'],
    'link to import styles' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
    'css expression' => ['<p style="width:expression(alert(1))">x</p>'],
    'target blank without noopener' => ['<a href="https://ok.example" target="_blank">x</a>'],
]);

test('a link that is kept carries rel=noopener noreferrer, and a new tab only when asked for it properly', function () {
    $out = ($this->clean)('<a href="https://ok.example" target="_blank">a</a><a href="https://ok.example" target="evil-frame">b</a>');

    expect($out)->toContain('rel="noopener noreferrer"')->and($out)->not->toContain('evil-frame');
    expect(substr_count($out, 'rel="noopener noreferrer"'))->toBe(2);
});

test('a hostile block configuration builds nothing but a reference', function () {
    $config = json_encode(['media_id' => '1 OR 1=1', 'caption' => '<script>alert(1)</script>', 'html' => '<img src=x onerror=alert(1)>', 'post_ids' => ['x', "1'; DROP TABLE posts;--", [1]]]);
    foreach (['image', 'gallery', 'read-also', 'awareness', 'video-hidden', 'script', '../image', ''] as $id) {
        $stored = ($this->clean)('<div data-type="customBlock" data-id="'.$id.'" data-config="'.e($config).'"></div>');

        expect($stored)->not->toMatch(DANGER)->and(app(BodyRenderer::class)->html($stored))->not->toMatch(DANGER);
    }
});

test('an enormous or deeply nested text is refused or handled, never a crash', function () {
    expect(fn () => ($this->clean)(str_repeat('a', BodySanitizer::MAX_LENGTH + 1)))->toThrow(LengthException::class);

    $nested = str_repeat('<div><blockquote><ul><li>', 3000).'x';
    expect(fn () => ($this->clean)($nested))->not->toThrow(Throwable::class);
});
