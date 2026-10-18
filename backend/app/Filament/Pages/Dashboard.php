<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * The panel's home (21.4): the shape only. Each card says which phase fills it with real numbers.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'الرئيسية';

    protected static ?string $navigationLabel = 'الرئيسية';

    /** @var array<string, string> card title => where its numbers come from */
    private const CARDS = [
        'زيارات اليوم' => 'تظهر مع مرحلة الإحصائيات.',
        'الأكثر قراءة' => 'تظهر مع مرحلة الإحصائيات.',
        'رسائل جديدة' => 'تظهر مع نموذج «اتصل بنا».',
        'حملات إعلانية فعّالة' => 'تظهر مع مرحلة الإعلانات.',
        'حالة مصادر العملات والطقس' => 'تظهر مع مرحلة العملات والطقس.',
        'التنبيهات' => 'تظهر مع مرحلة التنبيهات.',
    ];

    public function getWidgets(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        $cards = [];
        foreach (self::CARDS as $title => $note) {
            $cards[] = Section::make($title)->schema([Text::make($note)->color('gray')]);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($cards),
        ]);
    }
}
