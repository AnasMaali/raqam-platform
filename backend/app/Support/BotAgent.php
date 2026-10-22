<?php

namespace App\Support;

/**
 * Whether a User-Agent is a robot rather than a person's browser (decision 22.3): search engines, link previews
 * (WhatsApp, Facebook, X, Telegram, Slack), monitors, headless browsers and HTTP libraries. No agent at all counts as a
 * robot too. Used where a person's action is counted (ad views and clicks, contact buttons).
 */
final class BotAgent
{
    private const PATTERN = '~bot\b|bot/|crawl|spider|slurp|mediapartners|adsbot|facebookexternalhit|facebookcatalog|meta-externalagent'
        .'|whatsapp|telegram|discord|slack|skype|linkedin|pinterest|embedly|preview|vkshare|bingpreview|yahoo|yandex|baidu'
        .'|duckduck|petal|semrush|ahrefs|mj12|dotbot|bytespider|ccbot|amazonbot|applebot'
        .'|headless|phantom|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|statuscake'
        .'|curl|wget|python|java/|okhttp|go-http|axios|node-fetch|undici|httpclient|libwww|perl|ruby|php/|guzzle|postman|insomnia|scrapy|feedfetcher|rss~i';

    public static function isBot(?string $agent): bool
    {
        $agent = trim((string) $agent);

        return $agent === '' || strlen($agent) < 10 || preg_match(self::PATTERN, $agent) === 1;
    }
}
