<?php

namespace App\Filament\Resources\BotQuestions\Pages;

use App\Chat\BotStats;
use App\Filament\Resources\BotQuestions\BotQuestionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class ListBotQuestions extends ListRecords
{
    protected static string $resource = BotQuestionResource::class;

    /** The chatbot's numbers above the list (18.11). */
    public function content(Schema $schema): Schema
    {
        $line = fn (array $t) => [
            Text::make('المحادثات: '.$t['conversations']), Text::make('الأسئلة: '.$t['questions'].' — أُجيب منها: '.$t['answered'].($t['rate'] !== null ? ' ('.$t['rate'].'%)' : '')),
            Text::make('ضغطات الأزرار: '.$t['buttons'])->color('gray'),
        ];
        $top = collect(BotStats::top(30))->map(fn ($q) => Text::make(($q['answered'] ? '✓ ' : '✗ ').$q['question'].' ('.$q['count'].')'))->all();

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 3])->schema([
                Section::make('آخر 7 أيام')->schema($line(BotStats::totals(7))),
                Section::make('آخر 30 يوماً')->schema($line(BotStats::totals(30))),
                Section::make('الأكثر تكراراً (30 يوماً)')->collapsible()->collapsed()->schema($top ?: [Text::make('لا أسئلة بعد.')->color('gray')]),
            ]),
            EmbeddedTable::make(),
        ]);
    }
}
