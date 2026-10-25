<?php

namespace App\Seo;

/**
 * The crawlers robots.txt names by purpose (phase 10b, GEO). Data only; RobotsPolicy decides who may come in. Search
 * engines need no list: they fall under `*` and are always welcome. A crawler an operator uses both to answer and to
 * train is listed where its operator documents it.
 */
final class CrawlerAgents
{
    /** Fetch a page to answer a person's question, and name the source (search assistants). */
    public const ANSWER = [
        'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User',
        'DuckAssistBot', 'MistralAI-User',
    ];

    /** Collect pages to train language models. */
    public const TRAINING = [
        'GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent',
        'Bytespider', 'cohere-training-data-crawler',
    ];
}
