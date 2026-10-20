<?php

namespace App\Video;

/**
 * Reads a pasted YouTube address (decision 8.2): the normal watch link, the short youtu.be link, a Shorts
 * link, an embed link. Only YouTube's own hosts are accepted and the id must be exactly eleven characters
 * of the id alphabet, so nothing else a person pastes can reach the API or the player.
 */
final class YouTubeUrl
{
    private const ID = '/^[A-Za-z0-9_-]{11}$/';

    private const WATCH_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** @return array{id: string, is_short: bool}|null */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2048 || preg_match('/[\x00-\x20\x7f]/', $input)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true))) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            return self::result(explode('/', ltrim($path, '/'))[0], false);
        }

        if (! in_array($host, self::WATCH_HOSTS, true)) {
            return null;
        }

        if ($path === '/watch') {
            return self::result($query['v'] ?? null, false);
        }

        if (preg_match('#^/(shorts|embed|live)/([^/]+)/?$#', $path, $m)) {
            return self::result($m[2], $m[1] === 'shorts');
        }

        return null;
    }

    private static function result(mixed $id, bool $short): ?array
    {
        return is_string($id) && preg_match(self::ID, $id) === 1 ? ['id' => $id, 'is_short' => $short] : null;
    }

    /** The canonical watch address of an id, for the "open on YouTube" link. */
    public static function watchUrl(string $id): string
    {
        return 'https://www.youtube.com/watch?v='.$id;
    }

    /** The thumbnail YouTube serves for every public video, used when the API gave none. */
    public static function thumbnail(string $id): string
    {
        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }
}
