<?php

namespace App\Content\Blocks;

use App\Media\MediaHtml;
use App\Models\MediaAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** An awareness card (decision 7.12 – 7.13) shown inside an article. */
final class AwarenessCardBlock extends ContentBlock
{
    public static function getId(): string
    {
        return 'awareness';
    }

    public static function getLabel(): string
    {
        return 'بطاقة توعية';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedLightBulb;
    }

    public static function getPreviewLabel(array $config): string
    {
        return 'بطاقة توعية: '.(DB::table('posts')->where('id', (int) ($config['post_id'] ?? 0))->value('title') ?? '—');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading('إدراج بطاقة توعية')->modalSubmitActionLabel('إدراج')->schema([
            Select::make('post_id')->label('البطاقة')->searchable()->native(false)->required()
                ->getSearchResultsUsing(fn (string $search) => DB::table('posts')->where('kind_code', 'awareness')->whereNull('deleted_at')
                    ->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderByDesc('id')->limit(30)->pluck('title', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => ctype_digit((string) $value) ? DB::table('posts')->where('id', (int) $value)->value('title') : null),
        ]);
    }

    public static function normalize(array $config): ?array
    {
        $id = self::id($config['post_id'] ?? null);

        return $id !== null && PostCardQuery::existing([$id], 'awareness') !== [] ? ['post_id' => $id] : null;
    }

    public static function references(array $config): array
    {
        return ['posts' => [(int) $config['post_id']]];
    }

    public static function render(array $config): string
    {
        $card = PostCardQuery::published([(int) ($config['post_id'] ?? 0)])->first();
        if ($card === null) {
            return '';
        }

        // The character speaks: its picture in the chosen pose, its name in its colour, then the information (7.11).
        $who = DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')
            ->join('characters', 'characters.id', '=', 'character_poses.character_id')->where('awareness_cards.post_id', $card->id)->where('characters.is_active', true)
            ->first(['characters.name_ar', 'characters.slug', 'characters.accent_color', 'character_poses.static_media_id']);
        $picture = $who && ($asset = MediaAsset::query()->publicImages()->find($who->static_media_id)) ? app(MediaHtml::class)->picture($asset, '120px') : '';
        $style = $who ? ' style="--rq-accent:'.e($who->accent_color).'" data-character="'.e($who->slug).'"' : '';

        return '<aside class="rq-awareness" data-post-id="'.(int) $card->id.'"'.$style.'>'
            .($picture !== '' ? '<div class="rq-awareness-character">'.$picture.'<span>'.e($who->name_ar).'</span></div>' : '')
            .'<div class="rq-awareness-bubble"><strong>'.e($card->title).'</strong>'.($card->excerpt ? '<p>'.e($card->excerpt).'</p>' : '').'</div></aside>';
    }
}
