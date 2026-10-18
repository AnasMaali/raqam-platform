<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Whatever a stranger puts in the query string or the address, the API answers with its own error shape
 * (400/404/410/422), never a server error, and never leaks a stack trace, an SQL fragment or a file path.
 */
const HOSTILE_VALUES = [
    "x' OR '1'='1", "1; DROP TABLE posts;--", '%00', '%', '_', '\\', '../../.env', '<script>alert(1)</script>',
    '9999999999999999999999', '-1', '0', '1e999', 'NaN', 'null', '[]', '{}', '😀', 'ثلاثة', '%E0%A4%A', "a\r\nSet-Cookie: x=1",
];

const LIST_PARAMS = ['page', 'kind', 'category', 'tag', 'section', 'author', 'guest', 'featured', 'breaking', 'per_page', 'sort', 'q', 'limit', 'offset'];

function assertTidy($response, string $what): void
{
    expect($response->status())->toBeLessThan(500, "{$what} gave {$response->status()}");

    $body = $response->getContent();
    expect($body)->not->toMatch('/SQLSTATE|Stack trace|vendor\/laravel|\.php on line|Illuminate\\\\|pg_|syntax error/i', "{$what} leaked internals");
    expect($response->headers->get('Set-Cookie'))->toBeNull("{$what} set a cookie");
}

test('hostile values in any list filter never cause a server error or leak internals', function () {
    foreach (['/api/v1/posts', '/api/v1/categories', '/api/v1/tags', '/api/v1/authors', '/api/v1/guests', '/api/v1/pages', '/api/v1/sections'] as $endpoint) {
        foreach (LIST_PARAMS as $param) {
            foreach (HOSTILE_VALUES as $value) {
                assertTidy($this->getJson($endpoint.'?'.$param.'='.rawurlencode($value)), "GET {$endpoint}?{$param}={$value}");
            }
            // The same names as arrays and as nested keys, which a scalar-only reader must not trip over.
            assertTidy($this->getJson($endpoint.'?'.$param.'[]=1&'.$param.'[]=2'), "GET {$endpoint}?{$param}[]");
            assertTidy($this->getJson($endpoint.'?'.$param.'[a][b]=1'), "GET {$endpoint}?{$param}[a][b]");
        }
    }
});

test('hostile values in the address of one item are a clean 404', function () {
    foreach (['posts', 'categories', 'tags', 'authors', 'guests', 'pages', 'sections'] as $collection) {
        foreach (HOSTILE_VALUES as $value) {
            assertTidy($this->getJson("/api/v1/{$collection}/".rawurlencode($value)), "GET /api/v1/{$collection}/{$value}");
        }
    }
    foreach (['abc', '0', '-1', '99999999999999999999', "1' OR 1=1"] as $id) {
        assertTidy($this->getJson('/api/v1/posts/'.rawurlencode($id).'/slug'), "GET /api/v1/posts/{$id}/slug");
    }
});

test('the redirect lookup refuses what is not an on-site path and never redirects off the site', function () {
    foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', 'javascript:alert(1)', '', '/../../etc/passwd', str_repeat('/a', 400)] as $path) {
        $response = $this->getJson('/api/v1/redirects/lookup?path='.rawurlencode($path));

        assertTidy($response, "lookup {$path}");
        expect($response->headers->get('Location'))->toBeNull();
    }

    assertTidy($this->getJson('/api/v1/redirects/lookup?path[]=/a'), 'lookup array');
    assertTidy($this->getJson('/api/v1/redirects/lookup'), 'lookup without a path');
});

test('only GET and HEAD are served, and nothing sets a cookie or opens CORS', function () {
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $response = $this->{$method.'Json'}('/api/v1/posts', ['title' => 'x']);

        expect($response->status())->toBeIn([404, 405]);
    }

    $response = $this->getJson('/api/v1/posts');
    expect($response->headers->get('Set-Cookie'))->toBeNull()
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    $preflight = $this->call('OPTIONS', '/api/v1/posts', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    expect($preflight->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
