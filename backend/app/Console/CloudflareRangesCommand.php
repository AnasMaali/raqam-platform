<?php

namespace App\Console;

use App\Integrations\OutboundHttp;
use App\Integrations\ProviderUnavailable;
use App\Panel\AdminAlerts;
use App\Support\ProxyAddresses;
use Illuminate\Console\Command;

/**
 * Cloudflare's address ranges against config/cloudflare.php (24.2). Without --write (the weekly run): when Cloudflare's
 * lists differ, the admins get an alert in the bell; nothing changes, since a running server must never start
 * believing new machines by itself. With --write (a developer, then a pull request): the file is rewritten.
 */
class CloudflareRangesCommand extends Command
{
    public const V4_URL = 'https://www.cloudflare.com/ips-v4';

    public const V6_URL = 'https://www.cloudflare.com/ips-v6';

    protected $signature = 'proxies:cloudflare-ranges {--write : Rewrite config/cloudflare.php with the published lists}';

    protected $description = "Compare Cloudflare's published address ranges with config/cloudflare.php (alert on a difference), or rewrite it";

    public function handle(): int
    {
        try {
            $v4 = $this->fetch(self::V4_URL, 4);
            $v6 = $this->fetch(self::V6_URL, 6);
        } catch (ProviderUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $known = [...(array) config('cloudflare.ipv4', []), ...(array) config('cloudflare.ipv6', [])];
        $published = [...$v4, ...$v6];
        $added = array_values(array_diff($published, $known));
        $removed = array_values(array_diff($known, $published));

        if ($this->option('write')) {
            file_put_contents(config_path('cloudflare.php'), $this->file($v4, $v6));
            $this->info('Wrote config/cloudflare.php: '.count($v4).' IPv4 and '.count($v6).' IPv6 ranges ('.count($added).' added, '.count($removed).' removed).');

            return self::SUCCESS;
        }

        if ($added === [] && $removed === []) {
            $this->info('Cloudflare\'s ranges match config/cloudflare.php.');

            return self::SUCCESS;
        }

        $this->warn('Cloudflare\'s ranges differ: added '.(implode(', ', $added) ?: 'none').'; removed '.(implode(', ', $removed) ?: 'none').'.');
        AdminAlerts::raise('system', 'cloudflare-ranges:'.hash('sha256', implode(',', $published)), 'تغيّرت عناوين Cloudflare',
            'نشرت Cloudflare قائمة عناوين مختلفة عن المحفوظة في المنصة. حتى تُحدَّث القائمة (proxies:cloudflare-ranges --write ثم نشر جديد) قد يُحسب زوار يصلون من العناوين الجديدة كأنهم زائر واحد. '
            .'أُضيف: '.(implode('، ', $added) ?: 'لا شيء').'. حُذف: '.(implode('، ', $removed) ?: 'لا شيء').'.', null, null, 60 * 24 * 6);

        return self::SUCCESS;
    }

    /**
     * One published list: one range per line, every line a range of the right family and not too wide, and at least
     * one. A list that fails any of that is refused whole (an error page, a cut answer).
     *
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function fetch(string $url, int $family): array
    {
        $body = OutboundHttp::get('Cloudflare', $url, maxBytes: 16384)->body();
        $ranges = array_values(array_filter(array_map('trim', preg_split('~\R~', $body)), fn (string $line) => $line !== ''));
        $wrongFamily = array_filter($ranges, fn (string $r) => str_contains($r, ':') !== ($family === 6));
        if ($ranges === [] || $wrongFamily !== [] || ProxyAddresses::cloudflareProblems($ranges) !== []) {
            throw new ProviderUnavailable("قائمة عناوين Cloudflare (IPv{$family}) غير صالحة.");
        }

        return $ranges;
    }

    /**
     * @param  list<string>  $v4
     * @param  list<string>  $v6
     */
    private function file(array $v4, array $v6): string
    {
        $list = fn (array $ranges) => implode('', array_map(fn (string $r) => "        '{$r}',\n", $ranges));
        $head = <<<'PHP'
            <?php

            /*
            | Cloudflare's published address ranges (https://www.cloudflare.com/ips-v4 and /ips-v6): the only machines, besides the
            | platform's own reverse proxy, allowed to name the visitor (App\Http\Middleware\TrustedProxies). Written by
            | `php artisan proxies:cloudflare-ranges --write`; a weekly run without --write alerts the admins when Cloudflare's lists
            | differ from these, and never changes them by itself.
            */

            PHP;

        return $head."\nreturn [\n    'checked' => '".now('Asia/Hebron')->format('Y-m-d')."',\n    'ipv4' => [\n".$list($v4)."    ],\n    'ipv6' => [\n".$list($v6)."    ],\n];\n";
    }
}
