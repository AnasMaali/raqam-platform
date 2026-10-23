<?php

namespace App\Chat;

use App\Content\PostPath;
use App\Models\Post;
use App\Search\Searcher;
use App\Search\SearchQuery;
use Illuminate\Support\Facades\DB;

/**
 * The free chatbot of V1 (18.3–18.8): the knowledge base first; an entry may point to a service, a page, a link,
 * WhatsApp, or carry today's currency or weather numbers. Without a good match: the site's closest published posts
 * (the same search as the site, 18.5) and a WhatsApp button that carries the visitor's question (18.8).
 */
final class KnowledgeBaseResponder implements ChatResponder
{
    public function __construct(private KnowledgeBase $kb, private LiveAnswers $live, private Searcher $searcher) {}

    public function answer(string $question): ChatAnswer
    {
        $match = $this->kb->match($question);
        if ($match === null) {
            return $this->noAnswer($question);
        }

        return $this->fromEntry($match['entry'], $question, (float) $match['score']);
    }

    public function press(int $quickReplyId): ?ChatAnswer
    {
        $q = DB::table('bot_quick_replies')->where('id', $quickReplyId)->where('is_active', true)->first();
        if ($q === null) {
            return null;
        }
        if ($q->action_type === 'entry') {
            $entry = $this->kb->entry((int) $q->target_entry_id);

            return $entry === null ? null : $this->fromEntry($entry, (string) $q->label_ar, null);
        }
        [$html, $links, $whatsapp, $kind] = $this->action($q->action_type, match ($q->action_type) {
            'service' => (int) $q->target_service_id, 'page' => (int) $q->target_page_id, 'url' => $q->target_url, default => null,
        }, (string) $q->label_ar);

        return new ChatAnswer($kind, $html ?: '<p>'.e($q->label_ar).'</p>', $links, [], $whatsapp);
    }

    /** @param  array<string, mixed>  $entry */
    private function fromEntry(array $entry, string $question, ?float $score): ChatAnswer
    {
        [$html, $links, $whatsapp, $kind] = $this->action($entry['action'], $entry['target'], $question);
        $buttons = DB::table('bot_quick_replies')->whereIn('id', $entry['followups'])->where('is_active', true)->get(['id', 'label_ar'])
            ->sortBy(fn ($b) => array_search((int) $b->id, $entry['followups'], true))
            ->map(fn ($b) => ['id' => (int) $b->id, 'label' => $b->label_ar])->values()->all();

        return new ChatAnswer($kind, BotAnswerSanitizer::clean($entry['html'].$html), $links, $buttons, $whatsapp, (int) $entry['id'], $score);
    }

    /**
     * What an entry's or a button's action adds: a live block, a link, or WhatsApp.
     *
     * @return array{0: string, 1: list<array{label: string, url: string}>, 2: ?string, 3: string}
     */
    private function action(string $type, mixed $target, string $question): array
    {
        switch ($type) {
            case 'rates':
                $r = $this->live->rates($question);

                return [$r['html'], $r['links'], null, ChatAnswer::LIVE];
            case 'weather':
                $w = $this->live->weather($question);

                return [$w['html'], $w['links'], null, ChatAnswer::LIVE];
            case 'service':
                $s = DB::table('services')->where('id', $target)->where('is_active', true)->whereNull('deleted_at')->first(['name_ar', 'slug']);

                return ['', $s ? [['label' => $s->name_ar, 'url' => '/services/'.rawurlencode($s->slug)]] : [], null, ChatAnswer::ANSWER];
            case 'page':
                $p = DB::table('pages')->where('id', $target)->where('is_published', true)->first(['title', 'slug']);

                return ['', $p ? [['label' => $p->title, 'url' => '/'.rawurlencode($p->slug)]] : [], null, ChatAnswer::ANSWER];
            case 'url':
                return ['', is_string($target) && str_starts_with($target, 'https://') ? [['label' => 'افتح الرابط', 'url' => $target]] : [], null, ChatAnswer::ANSWER];
            case 'whatsapp':
                return ['', [], self::whatsapp($question), ChatAnswer::ANSWER];
            default:
                return ['', [], null, ChatAnswer::ANSWER];
        }
    }

    private function noAnswer(string $question): ChatAnswer
    {
        $links = [];
        try {
            // The words that carry meaning together first, then each alone, longest first: a question rarely matches a title word for word.
            $words = KnowledgeBase::words($question);
            usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach (array_unique([implode(' ', $words), ...array_slice($words, 0, 3)]) as $terms) {
                if (mb_strlen($terms) < 2) {
                    continue;
                }
                $ids = collect($this->searcher->search(new SearchQuery(mb_substr($terms, 0, 120)))->posts)->pluck('id')->take(6);
                $posts = Post::query()->live()->whereIn('posts.id', $ids)->get()->sortBy(fn (Post $p) => $ids->search($p->id))->take(3);
                $links = $posts->map(fn (Post $p) => ['label' => $p->title, 'url' => PostPath::for($p->kind_code, $p->id, $p->slug)])->values()->all();
                if ($links !== []) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            report($e);   // search down: the visitor still gets WhatsApp
        }
        $whatsapp = self::whatsapp($question);
        $html = '<p>لم أجد جواباً دقيقاً لسؤالك.</p>'
            .($links !== [] ? '<p>قد تفيدك هذه المواد من الموقع.</p>' : '')
            .($whatsapp !== null ? '<p>يمكنك سؤال فريقنا مباشرة على واتساب.</p>' : '');

        return new ChatAnswer(ChatAnswer::NO_ANSWER, $html, $links, [], $whatsapp, null, 0.0);
    }

    /** WhatsApp with a ready message that carries the visitor's question (18.8); null when no number is set. */
    public static function whatsapp(string $question): ?string
    {
        $number = DB::table('settings')->where('key', 'contact.whatsapp')->value('value');

        return filled($number) ? 'https://wa.me/'.ltrim((string) $number, '+').'?text='.rawurlencode('مرحباً، سؤالي: '.mb_substr($question, 0, 300)) : null;
    }
}
