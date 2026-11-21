<?php

/*
 * The outside checks of the QA environment that a scanner does not make, or makes without saying why
 * (docs/qa/security.md): the security headers on every kind of answer, what must never be served, the cookies, CORS,
 * the HTTP methods, open redirects, a forged Host, oversized bodies and the request limits. Against the QA API and
 * panel (127.0.0.1:8100) and the QA website (127.0.0.1:3300) only.
 *
 *   php scripts/qa-outside.php [--limits]
 *
 * --limits also fills the request limits of the forms (contact, newsletter, chat) and of the API, for the QA visitor
 * addresses below: run it last, the limits hold for a minute. The site token and the panel's address are read from
 * backend/.env.qa and never printed: the panel is shown as <panel>.
 */

const API = 'http://127.0.0.1:8100';
const SITE = 'http://127.0.0.1:3300';

$envFile = __DIR__.'/../.env.qa';
if (! is_file($envFile) || ! preg_match('/^DB_DATABASE=raqam_qa$/m', file_get_contents($envFile))) {
    fwrite(STDERR, "qa-outside.php: backend/.env.qa is missing or not the QA environment's.\n");
    exit(1);
}
function qaValue(string $key): string
{
    preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', file_get_contents(__DIR__.'/../.env.qa'), $m);

    return trim($m[1] ?? '');
}
$token = qaValue('API_SITE_TOKEN');
$panel = '/'.qaValue('ADMIN_PATH');
$limits = in_array('--limits', $argv, true);

/** One request, never following a redirect: [status, headers (lower-case name => list of values), body]. */
function call(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: qa-outside-check'], $headers),
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$received) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $received[strtolower(trim($name))][] = trim($value);
            }

            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') {
        curl_setopt($curl, CURLOPT_NOBODY, true);
    }
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $text = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return [$status, $received, $text];
}

$results = [];
function check(string $area, string $what, bool $ok, string $detail = ''): void
{
    global $results, $panel;
    $results[] = [$area, str_replace($panel, '<panel>', $what), $ok, str_replace([$panel, ltrim($panel, '/')], ['<panel>', '<panel>'], $detail)];
}
function note(string $area, string $what, string $detail): void
{
    global $results, $panel;
    $results[] = [$area, str_replace($panel, '<panel>', $what), null, str_replace([$panel, ltrim($panel, '/')], ['<panel>', '<panel>'], $detail)];
}
$h = fn (array $headers, string $name) => $headers[$name][0] ?? null;

$siteToken = ["X-Raqam-Site-Token: {$token}", 'X-Raqam-Visitor-Ip: 203.0.113.21', 'X-Raqam-Visitor-Ua: qa-outside-check'];
$fromSite = ['Origin: '.SITE, 'X-Forwarded-For: 203.0.113.22', 'Content-Type: application/json'];

// ---------------------------------------------------------------------------------------------------------------------
// The headers on every kind of answer.
[, , $home] = call('GET', SITE.'/');
preg_match('#/_next/static/[^"\']+\.js#', $home, $js);
preg_match('#/_next/static/media/[^"\')]+\.woff2#', $home, $font);
preg_match('#/_next/static/[^"\']+\.css#', $home, $css);
[, , $posts] = call('GET', API.'/api/v1/posts', ['Accept: application/json']);
$post = json_decode($posts, true)['data'][0] ?? null;
if (! is_array($post)) {
    fwrite(STDERR, "qa-outside.php: the QA API gave no post (is it answering, or is its request limit reached?).\n");
    exit(1);
}
$picture = null;
array_walk_recursive($post, function ($value) use (&$picture) {
    if ($picture === null && is_string($value) && preg_match('#^https?://127\.0\.0\.1:8100/storage/.+\.(webp|jpe?g|png|avif)$#', $value)) {
        $picture = $value;
    }
});
$movedPath = $post ? preg_replace('#/[^/]+$#', '/old-slug-qa', $post['path']) : '/news/1/x';

$siteAnswers = [
    ['an HTML page', 'GET', '/', [], null, 200],
    ['an HTML 404', 'GET', '/no-such-page-qa8', [], null, 404],
    ['a moved post (301)', 'GET', $movedPath, [], null, 301],
    ['a JSON route', 'GET', '/api/chat', [], null, 200],
    ['a route refusing (403)', 'POST', '/api/chat', ['Origin: https://evil.example', 'Content-Type: application/json'], '{}', 403],
    ['a method not allowed (405)', 'PUT', '/api/chat', [], '{}', 405],
    ['RSS', 'GET', '/feeds/posts', [], null, 200],
    ['sitemap index', 'GET', '/sitemap.xml', [], null, 200],
    ['robots.txt', 'GET', '/robots.txt', [], null, 200],
    ['llms.txt', 'GET', '/llms.txt', [], null, 200],
    ['a script of the build', 'GET', $js[0] ?? '/_next/static/missing.js', [], null, 200],
    ['a stylesheet of the build', 'GET', $css[0] ?? '/_next/static/missing.css', [], null, 200],
    ['a font', 'GET', $font[0] ?? '/_next/static/media/missing.woff2', [], null, 200],
    ['a public picture', 'GET', '/icon.svg', [], null, 200],
];
$siteExpect = [
    'content-security-policy' => fn ($v) => $v !== null && str_contains($v, "frame-ancestors 'none'") && str_contains($v, "default-src 'self'"),
    'strict-transport-security' => fn ($v) => $v === 'max-age=31536000; includeSubDomains',
    'x-frame-options' => fn ($v) => $v === 'DENY',
    'x-content-type-options' => fn ($v) => $v === 'nosniff',
    'referrer-policy' => fn ($v) => $v === 'strict-origin-when-cross-origin',
];
foreach ($siteAnswers as [$what, $method, $path, $headers, $body, $status]) {
    [$got, $answer] = call($method, SITE.$path, $headers, $body);
    $missing = array_keys(array_filter($siteExpect, fn ($test, $name) => ! $test($h($answer, $name)), ARRAY_FILTER_USE_BOTH));
    $leaks = array_filter(['x-powered-by' => $h($answer, 'x-powered-by'), 'server' => $h($answer, 'server')]);
    check('headers: site', "{$what} ({$method} {$path})", $got === $status && $missing === [] && $leaks === [],
        "status {$got}".($missing ? '; missing or wrong: '.implode(', ', $missing) : '').($leaks ? '; sends '.json_encode($leaks) : ''));
}

$backendAnswers = [
    ['JSON 200', 'GET', '/api/v1/posts', ['Accept: application/json'], null, 200],
    ['JSON 404', 'GET', '/api/v1/posts/999999999', ['Accept: application/json'], null, 404],
    ['JSON 403 (a site-only route without the token)', 'POST', '/api/v1/contact', ['Accept: application/json', 'Content-Type: application/json'], '{}', 403],
    ['JSON 422', 'POST', '/api/v1/contact', array_merge($siteToken, ['Accept: application/json', 'Content-Type: application/json']), '{}', 422],
    ['JSON 405', 'DELETE', '/api/v1/posts', ['Accept: application/json'], null, 405],
    ['JSON 422 (text that is not UTF-8)', 'GET', '/api/v1/search?q=%FF%FE', ['Accept: application/json'], null, 422],
    ['a 404 for /up without its token', 'GET', '/up', [], null, 404],
    ['/livez', 'GET', '/livez', [], null, 200],
    ['an HTML 404', 'GET', '/no-such-page-qa8', [], null, 404],
    ['a preview link without its signature (403, whether the post exists or not)', 'GET', '/preview/1', [], null, 403],
    ['a private file without its signature', 'GET', '/private-media/x.pdf', [], null, 403],
    ['the sign-in page', 'GET', $panel.'/login', [], null, 200],
    ['the panel, signed out (redirect)', 'GET', $panel, [], null, 302],
    ['the panel\'s script', 'GET', '/livewire/livewire.min.js', [], null, 200],
];
$backendExpect = [
    'content-security-policy' => fn ($v) => $v !== null && str_contains($v, "frame-ancestors 'none'"),
    'x-frame-options' => fn ($v) => $v === 'DENY',
    'x-content-type-options' => fn ($v) => $v === 'nosniff',
    'referrer-policy' => fn ($v) => $v !== null,
];
foreach ($backendAnswers as [$what, $method, $path, $headers, $body, $status]) {
    [$got, $answer, $text] = call($method, API.$path, $headers, $body);
    $missing = array_keys(array_filter($backendExpect, fn ($test, $name) => ! $test($h($answer, $name)), ARRAY_FILTER_USE_BOTH));
    $leaks = array_filter(['x-powered-by' => $h($answer, 'x-powered-by'), 'server' => $h($answer, 'server')]);
    $trace = preg_match('/Stack trace|vendor\/laravel|\.php:\d+|Whoops|Symfony\\\\Component/', $text) === 1;
    check('headers: back end', "{$what} ({$method} {$path})", $got === $status && $missing === [] && $leaks === [] && ! $trace,
        "status {$got}".($missing ? '; missing or wrong: '.implode(', ', $missing) : '').($leaks ? '; sends '.json_encode($leaks) : '').($trace ? '; a stack trace' : ''));
}
// A picture of the media: a file the web server sends by itself, so PHP never adds the headers (here PHP's own server
// sends it). They are the web server's job (docs/deployment-requirements.md, "The web server in front").
if ($picture) {
    [$status, $answer] = call('GET', $picture);
    $missing = array_keys(array_filter($backendExpect, fn ($test, $name) => ! $test($h($answer, $name)), ARRAY_FILTER_USE_BOTH));
    note('headers: back end', 'a picture of the media (GET '.parse_url($picture, PHP_URL_PATH).')', "status {$status}".($missing ? '; without '.implode(', ', $missing).': the web server in front adds them' : '; every header'));
}
// HSTS on the back end: only over https (the browser ignores it on http, and the proxy in front ends TLS).
[, $answer] = call('GET', API.$panel.'/login');
note('headers: back end', 'Strict-Transport-Security', $h($answer, 'strict-transport-security') === null
    ? 'not sent over plain http (by design: SecurityHeaders sends it on https requests, which need TrustProxies behind the proxy; SecurityHeadersTest)'
    : 'sent over plain http');
// A server error: no page here can be made to fail from outside; SecurityHeadersTest makes one (500) and checks it.

// ---------------------------------------------------------------------------------------------------------------------
// Nothing that must never be served.
$never = ['/.env', '/.env.qa', '/.env.example', '/.git/HEAD', '/.git/config', '/composer.json', '/composer.lock', '/package.json',
    '/package-lock.json', '/artisan', '/server.php', '/storage/', '/storage/logs/laravel.log', '/../storage/logs/laravel.log', '/vendor/',
    '/vendor/autoload.php', '/phpinfo.php', '/info.php', '/index.php/../.env', '/.htaccess', '/web.config', '/.DS_Store', '/storage/media/',
    '/css/', '/js/', '/build/', '/next.config.ts', '/.next/', '/_next/', '/_next/static/', '/src/', '/node_modules/', '/cache-handler.js',
    '/.well-known/', '/backup.sql', '/database/raqam_schema.sql'];
$secretMarks = '/APP_KEY|DB_PASSWORD|API_SITE_TOKEN|\[core\]|"require"|"dependencies"|phpinfo\(\)|<title>Index of|Directory listing|ref: refs\/|<\?php|module\.exports|nextConfig/';
foreach ([['back end', API], ['site', SITE]] as [$name, $base]) {
    $served = [];
    foreach ($never as $path) {
        [$status, , $text] = call('GET', $base.$path);
        if ($status === 200 || preg_match($secretMarks, $text)) {
            $served[] = "{$path} ({$status})";
        }
    }
    check('never served', "{$name}: ".count($never).' paths (.env, .git, composer/package files, storage, logs, vendor, phpinfo, folders)', $served === [], $served ? 'served: '.implode(', ', $served) : 'none served');
}
// Source maps: none of the site's build (they would show its source); Livewire's own map is the open-source library's.
$maps = 0;
preg_match_all('#/_next/static/[^"\']+\.(js|css)#', $home, $files);
foreach (array_unique($files[0]) as $file) {
    [$status] = call('GET', SITE.$file.'.map');
    $maps += $status === 200 ? 1 : 0;
    [, $answer, $text] = call('GET', SITE.$file);
    $maps += (isset($answer['sourcemap']) || isset($answer['x-sourcemap']) || str_contains(substr($text, -300), 'sourceMappingURL')) ? 1 : 0;
}
check('never served', 'the site\'s source maps ('.count(array_unique($files[0])).' build files of the home page)', $maps === 0, "{$maps} found");
[$status] = call('GET', API.'/livewire/livewire.min.js.map');
note('never served', 'Livewire\'s source map', "status {$status}: the map of the open-source library the panel loads (Livewire serves it itself), nothing of the platform's own code");

// ---------------------------------------------------------------------------------------------------------------------
// Cookies.
[, $answer] = call('GET', API.$panel.'/login');
foreach ($answer['set-cookie'] ?? [] as $cookie) {
    $name = strtok($cookie, '=');
    $flags = strtolower($cookie);
    $httpOnly = str_contains($flags, 'httponly');
    $secure = str_contains($flags, 'secure');
    $strict = str_contains($flags, 'samesite=strict');
    $wantHttpOnly = $name !== 'XSRF-TOKEN';
    check('cookies', "the sign-in page's {$name}", $secure && $strict && ($httpOnly === $wantHttpOnly) && (! str_starts_with($name, '__Host-') || (str_contains($flags, 'path=/') && ! str_contains($flags, 'domain='))),
        trim(preg_replace('/^[^;]*;/', '', $cookie)).($wantHttpOnly ? '' : ' (read by the panel\'s own script for its CSRF header, by design)'));
}
note('cookies', 'the newsletter challenge (site)', 'httpOnly, SameSite=Strict, path /api/newsletter; Secure whenever SITE_URL is https (the QA site is http, so not here)');

// ---------------------------------------------------------------------------------------------------------------------
// CORS: no origin may read an answer.
foreach ([['back end', API.'/api/v1/posts'], ['site', SITE.'/api/chat'], ['site', SITE.'/api/search?q=x']] as [$name, $url]) {
    [$preflight, $answer] = call('OPTIONS', $url, ['Origin: https://evil.example', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type']);
    [, $plain] = call('GET', $url, ['Origin: https://evil.example', 'Accept: application/json']);
    $allowed = array_filter([$h($answer, 'access-control-allow-origin'), $h($plain, 'access-control-allow-origin'), $h($answer, 'access-control-allow-credentials')]);
    check('CORS', "{$name}: ".parse_url($url, PHP_URL_PATH).' from another origin', $allowed === [], "preflight {$preflight}".($allowed ? '; allows '.implode(', ', $allowed) : '; no Access-Control-Allow-* header'));
}

// ---------------------------------------------------------------------------------------------------------------------
// HTTP methods.
foreach ([['back end', API.'/api/v1/posts'], ['back end', API.$panel.'/login'], ['site', SITE.'/'], ['site', SITE.'/api/chat']] as [$name, $url]) {
    [$trace, , $text] = call('TRACE', $url, ['X-Echo-Probe: qa8']);
    check('methods', "{$name}: TRACE ".parse_url($url, PHP_URL_PATH), $trace !== 200 && ! str_contains($text, 'X-Echo-Probe'), "status {$trace}");
    [$options, $answer] = call('OPTIONS', $url);
    note('methods', "{$name}: OPTIONS ".parse_url($url, PHP_URL_PATH), "status {$options}".(isset($answer['allow']) ? '; Allow: '.$answer['allow'][0] : ''));
}

// ---------------------------------------------------------------------------------------------------------------------
// Open redirects: nothing sends a visitor to another site.
$offsite = fn (?string $location) => $location !== null && preg_match('#^(https?:)?//(?!127\.0\.0\.1[:/]|localhost[:/])#i', $location) === 1 || ($location !== null && str_starts_with($location, '/\\'));
$probes = [
    [SITE, '//evil.example/'], [SITE, '//evil.example/%2e%2e'], [SITE, '/%2F%2Fevil.example'], [SITE, '/\\evil.example'], [SITE, '/%5Cevil.example'],
    [SITE, '/news/?next=https://evil.example'], [SITE, '/https:/evil.example'], [SITE, $movedPath.'?next=//evil.example'],
    [SITE, '/api/newsletter/one-click?token=x&next=https://evil.example'], [SITE, '/api/newsletter/unsubscribe?next=https://evil.example'],
    [API, '//evil.example/'], [API, $panel.'/login?redirect=https://evil.example'], [API, $panel.'?redirect=//evil.example'],
    [API, '/api/v1/ads/click/1/top_banner/'.str_repeat('a', 64)], [API, '/api/v1/redirects/lookup?path=//evil.example'],
];
$redirected = [];
foreach ($probes as [$base, $path]) {
    [$status, $answer, $text] = call('GET', $base.$path);
    $location = $h($answer, 'location');
    if ($offsite($location) || preg_match('#"(to|location|url)"\s*:\s*"(https?:)?//evil#', $text)) {
        $redirected[] = "{$path} → {$location}";
    }
}
check('open redirects', count($probes).' addresses that try to send the visitor to evil.example', $redirected === [], $redirected ? implode('; ', $redirected) : 'none leaves the site');

// ---------------------------------------------------------------------------------------------------------------------
// A forged Host (or X-Forwarded-Host): no address written from it.
$forged = ['Host: evil.example', 'X-Forwarded-Host: evil.example'];
[$status, $answer] = call('GET', SITE.$movedPath, $forged);
check('Host', "site: a moved post's redirect with a forged Host", ! str_contains((string) $h($answer, 'location'), 'evil'), "status {$status}, Location ".($h($answer, 'location') ?? '-'));
[, , $text] = call('GET', SITE.'/', $forged);
check('Host', 'site: the home page with a forged Host', ! str_contains($text, 'evil.example'), str_contains($text, 'evil.example') ? 'the page names evil.example' : 'the page never names it');
[, , $text] = call('GET', SITE.'/sitemap.xml', $forged);
check('Host', 'site: the sitemap with a forged Host', ! str_contains($text, 'evil.example'), str_contains($text, 'evil.example') ? 'names evil.example' : 'addresses from SITE_URL');
[, , $text] = call('GET', API.'/api/v1/posts', array_merge($forged, ['Accept: application/json']));
check('Host', 'back end: the API with a forged Host', ! str_contains($text, 'evil.example'), str_contains($text, 'evil.example') ? 'names evil.example' : 'addresses from APP_URL');
[, , $text] = call('GET', API.$panel.'/login', ['X-Forwarded-Host: evil.example']);
check('Host', 'back end: the sign-in page with a forged X-Forwarded-Host', ! str_contains($text, 'evil.example'), str_contains($text, 'evil.example') ? 'names evil.example' : 'never names it');
[, , $text] = call('GET', API.$panel.'/login', ['Host: evil.example']);
note('Host', 'back end: the sign-in page with a forged Host', str_contains($text, 'evil.example')
    ? 'names evil.example here: the QA server is not production, which writes every address from APP_URL whatever the Host (AppServiceProvider::pinUrls, SecurityHeadersTest)'
    : 'never names it');

// ---------------------------------------------------------------------------------------------------------------------
// Oversized bodies.
$big = json_encode(['message' => str_repeat('ب', 600_000)]);
foreach ([
    ['site: the chat, 1.2 MB', 'POST', SITE.'/api/chat', $fromSite, $big, [413]],
    ['site: the contact form, 1.2 MB', 'POST', SITE.'/api/contact', $fromSite, $big, [413]],
    ['site: a CSP report, 1.2 MB', 'POST', SITE.'/api/csp-report', ['Content-Type: application/csp-report'], $big, [413]],
    ['back end: the contact route, 1.2 MB', 'POST', API.'/api/v1/contact', array_merge($siteToken, ['Content-Type: application/json', 'Accept: application/json']), $big, [413, 422]],
] as [$what, $method, $url, $headers, $body, $statuses]) {
    $started = microtime(true);
    [$status] = call($method, $url, $headers, $body);
    check('body size', $what, in_array($status, $statuses, true), "status {$status} in ".round((microtime(true) - $started) * 1000).' ms');
}

// ---------------------------------------------------------------------------------------------------------------------
// The request limits (they hold for a minute once reached: --limits only).
if ($limits) {
    $limit = function (string $what, int $times, callable $send) {
        $statuses = [];
        for ($i = 1; $i <= $times; $i++) {
            $statuses[] = $send($i);
        }
        $first = array_search(429, $statuses, true);
        check('limits', $what, $first !== false, $first === false ? 'never 429 in '.$times : '429 from request '.($first + 1).' on');
    };
    $limit('site: the chat (10 messages a minute per visitor)', 35, fn () => call('POST', SITE.'/api/chat', ['Origin: '.SITE, 'X-Forwarded-For: 203.0.113.31', 'Content-Type: application/json'], '{"message":"سلام","path":"/","website":""}')[0]);
    $limit('site: the contact form (per visitor)', 15, fn () => call('POST', SITE.'/api/contact', ['Origin: '.SITE, 'X-Forwarded-For: 203.0.113.32', 'Content-Type: application/json'], '{"name":"x","email":"x@example.org","subject":"x","message":"x","website":""}')[0]);
    $limit('site: the newsletter (per visitor)', 15, fn () => call('POST', SITE.'/api/newsletter/subscribe', ['Origin: '.SITE, 'X-Forwarded-For: 203.0.113.33', 'Content-Type: application/json'], '{"email":"limit@example.org","website":"","placement":"home","path":"/"}')[0]);
    $limit('back end: the API without the token (120 a minute per visitor)', 130, fn () => call('GET', API.'/api/v1/categories', ['Accept: application/json'])[0]);
    [$status, $answer] = call('GET', API.'/api/v1/categories', ['Accept: application/json']);
    check('limits', 'back end: a 429 says when to come back, with the headers', $status === 429 && isset($answer['retry-after']) && $h($answer, 'x-frame-options') === 'DENY', "status {$status}, Retry-After ".($h($answer, 'retry-after') ?? '-'));
    $reports = [];
    for ($i = 0; $i < 50; $i++) {
        $reports[] = call('POST', SITE.'/api/csp-report', ['Content-Type: application/csp-report'], '{"csp-report":{"effective-directive":"img-src","blocked-uri":"https://example.org/x.png","document-uri":"'.SITE.'/"}}')[0];
    }
    note('limits', 'site: CSP reports (50 in a row)', 'statuses '.implode(',', array_unique($reports)).': no limit in the site itself; the limit is a Cloudflare rule (docs/deployment-requirements.md)');
    note('limits', 'the panel\'s sign-in', 'a Livewire form: checked in a real browser by e2e/qa/signin-limit.spec.ts (the sixth password in a minute is refused unchecked), and by the sign-in tests (5 a minute per e-mail and address, 20 an hour per e-mail, 30 per address)');
}

// ---------------------------------------------------------------------------------------------------------------------
$failed = 0;
$area = null;
foreach ($results as [$where, $what, $ok, $detail]) {
    if ($where !== $area) {
        echo "\n## {$where}\n\n| | Check | Result |\n|---|---|---|\n";
        $area = $where;
    }
    $mark = $ok === null ? 'note' : ($ok ? 'ok' : '**FAIL**');
    $failed += $ok === false ? 1 : 0;
    echo "| {$mark} | {$what} | ".str_replace('|', '\\|', $detail)." |\n";
}
$count = count(array_filter($results, fn ($r) => $r[2] !== null));
echo "\n".($count - $failed)." of {$count} checks passed.\n";
exit($failed > 0 ? 1 : 0);
