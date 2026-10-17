<?php

namespace App\Content\Subtypes;

/** The kinds that have a table of their own, by kind code. News, reports and opinion pieces have none. */
final class SubtypeRegistry
{
    /** @var array<string, Subtype>|null */
    private ?array $subtypes = null;

    public function for(string $kind): ?Subtype
    {
        return $this->all()[$kind] ?? null;
    }

    /** @return array<string, Subtype> */
    public function all(): array
    {
        return $this->subtypes ??= collect([
            new EpisodeSubtype, new StorySubtype, new ProductSubtype, new HistorySubtype,
            new InitiativeSubtype, new RoundtableSubtype, new AwarenessSubtype, new InfographicSubtype,
        ])->keyBy(fn (Subtype $s) => $s->kind())->all();
    }
}
