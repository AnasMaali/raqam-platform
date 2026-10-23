<?php

namespace App\Chat;

/**
 * One answer of the chatbot. `html` is safe to show as it is (cleaned by an allow-list, links https or of the site);
 * `text` is the same without markup (what the conversation log keeps). `entryId` is the knowledge-base entry that
 * answered (null: no answer), `score` how close the question was (null for a button).
 */
final class ChatAnswer
{
    public const ANSWER = 'answer';

    public const LIVE = 'live';

    public const NO_ANSWER = 'no_answer';

    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<array{id: int, label: string}>  $buttons
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $html,
        public readonly array $links = [],
        public readonly array $buttons = [],
        public readonly ?string $whatsapp = null,
        public readonly ?int $entryId = null,
        public readonly ?float $score = null,
    ) {}

    public function text(): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace(['~<br\s*/?>~i', '~</(p|li)>~i'], "\n", $this->html)), ENT_QUOTES | ENT_HTML5);

        return mb_substr(trim((string) preg_replace("/\n{2,}/", "\n", $text)), 0, 4000);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'html' => $this->html, 'text' => $this->text(), 'links' => $this->links, 'buttons' => $this->buttons, 'whatsapp' => $this->whatsapp];
    }
}
