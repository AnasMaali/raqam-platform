<?php

namespace App\Analytics;

/**
 * The answer engines a visit can come from (phase 10b, GEO): their sites, the campaign tag they add to links, and their
 * Android apps. Data only; TrafficSource reads it before the search engines, because one of them lives on a subdomain of
 * a search engine. The codes are rows of traffic_sources (the seed).
 */
final class AnswerEngineSources
{
    /** @var array<string, list<string>> source => hosts (a host matches itself and its subdomains) */
    public const HOSTS = [
        'chatgpt' => ['chatgpt.com', 'chat.openai.com'],
        'perplexity' => ['perplexity.ai'],
        'gemini' => ['gemini.google.com', 'bard.google.com'],
        'copilot' => ['copilot.microsoft.com', 'copilot.cloud.microsoft'],
        'claude' => ['claude.ai'],
    ];

    /** @var array<string, string> utm_source values => source */
    public const TAGS = [
        'chatgpt.com' => 'chatgpt', 'chatgpt' => 'chatgpt', 'openai' => 'chatgpt',
        'perplexity' => 'perplexity', 'perplexity.ai' => 'perplexity',
        'gemini' => 'gemini', 'gemini.google.com' => 'gemini',
        'copilot' => 'copilot', 'copilot.microsoft.com' => 'copilot',
        'claude' => 'claude', 'claude.ai' => 'claude',
    ];

    /** @var array<string, string> Android packages (android-app://<package>) => source */
    public const APPS = [
        'com.openai.chatgpt' => 'chatgpt', 'ai.perplexity.app.android' => 'perplexity', 'com.google.android.apps.bard' => 'gemini',
        'com.microsoft.copilot' => 'copilot', 'com.anthropic.claude' => 'claude',
    ];
}
