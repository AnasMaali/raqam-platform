<?php

namespace App\Content\Blocks;

use App\Filament\Forms\MediaSelect;
use App\Media\MediaHtml;
use App\Models\MediaAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/** A group of pictures shown as a gallery (decision 9.6: full-screen view and swipe are the site's job). */
final class GalleryBlock extends ContentBlock
{
    public const MAX = 24;

    public static function getId(): string
    {
        return 'gallery';
    }

    public static function getLabel(): string
    {
        return 'معرض صور';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function getPreviewLabel(array $config): string
    {
        return 'معرض صور ('.count($config['media_ids'] ?? []).')';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading('إدراج معرض صور')->modalSubmitActionLabel('إدراج')->schema([
            Select::make('media_ids')->label('الصور')->multiple()->searchable()->native(false)->required()->minItems(2)->maxItems(self::MAX)
                ->getSearchResultsUsing(fn (string $search) => MediaAsset::query()->publicImages()->search($search)->latest()->limit(30)->pluck('original_name', 'id')->all())
                ->getOptionLabelsUsing(fn (array $values) => MediaAsset::query()->whereIn('id', array_filter($values, fn ($v) => MediaSelect::isId($v)))->pluck('original_name', 'id')->all()),
        ]);
    }

    public static function normalize(array $config): ?array
    {
        $ids = self::ids($config['media_ids'] ?? null, self::MAX);
        if ($ids === null || count($ids) < 2) {
            return null;
        }

        $found = MediaAsset::query()->publicImages()->whereIn('id', $ids)->pluck('id')->all();

        // Keep the order given; a picture that no longer exists is dropped rather than failing the whole save.
        $kept = array_values(array_filter($ids, fn ($id) => in_array($id, $found, true)));

        return count($kept) >= 2 ? ['media_ids' => $kept] : null;
    }

    public static function references(array $config): array
    {
        return ['media' => array_map('intval', $config['media_ids'])];
    }

    public static function render(array $config): string
    {
        $ids = array_map('intval', $config['media_ids'] ?? []);
        $assets = MediaAsset::query()->publicImages()->whereIn('id', $ids)->get()->keyBy('id');
        $html = app(MediaHtml::class);

        $items = '';
        foreach ($ids as $id) {
            if ($asset = $assets->get($id)) {
                $items .= '<li>'.$html->picture($asset, '(min-width: 768px) 360px, 50vw').'</li>';
            }
        }

        return $items === '' ? '' : '<ul class="rq-gallery">'.$items.'</ul>';
    }
}
