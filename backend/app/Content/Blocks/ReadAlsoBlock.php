<?php

namespace App\Content\Blocks;

use App\Content\PostPath;
use App\Filament\Forms\MediaSelect;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** «اقرأ أيضاً» (decision 21.6, 23.14): links to other articles, which also feeds the internal linking. */
final class ReadAlsoBlock extends ContentBlock
{
    public const MAX = 6;

    public static function getId(): string
    {
        return 'read-also';
    }

    public static function getLabel(): string
    {
        return 'اقرأ أيضاً';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedLink;
    }

    public static function getPreviewLabel(array $config): string
    {
        return 'اقرأ أيضاً ('.count($config['post_ids'] ?? []).')';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading('إدراج «اقرأ أيضاً»')->modalSubmitActionLabel('إدراج')->schema([
            Select::make('post_ids')->label('المواد')->multiple()->searchable()->native(false)->required()->maxItems(self::MAX)
                ->getSearchResultsUsing(fn (string $search) => DB::table('posts')->where('status', 'published')->whereNull('deleted_at')
                    ->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderByDesc('published_at')->limit(30)->pluck('title', 'id')->all())
                ->getOptionLabelsUsing(fn (array $values) => DB::table('posts')->whereIn('id', array_filter($values, fn ($v) => MediaSelect::isId($v)))->pluck('title', 'id')->all()),
        ]);
    }

    public static function normalize(array $config): ?array
    {
        $ids = self::ids($config['post_ids'] ?? null, self::MAX);
        $kept = $ids === null ? [] : PostCardQuery::existing($ids);

        return $kept === [] ? null : ['post_ids' => $kept];
    }

    public static function references(array $config): array
    {
        return ['posts' => array_map('intval', $config['post_ids'])];
    }

    public static function render(array $config): string
    {
        $ids = array_map('intval', $config['post_ids'] ?? []);
        $posts = PostCardQuery::published($ids);

        $items = '';
        foreach ($ids as $id) {
            if ($post = $posts->get($id)) {
                $items .= '<li><a href="'.e(PostPath::for($post->kind_code, $post->id, $post->slug)).'">'.e($post->title).'</a></li>';
            }
        }

        return $items === '' ? '' : '<aside class="rq-read-also"><strong>اقرأ أيضاً</strong><ul>'.$items.'</ul></aside>';
    }
}
