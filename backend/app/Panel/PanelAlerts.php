<?php

namespace App\Panel;

use App\Media\ImageProcessor;

/**
 * The warnings shown at the top of the panel's home. Each one says what is wrong and what to do about it.
 */
final class PanelAlerts
{
    /** @return list<string> */
    public static function all(): array
    {
        $alerts = [];

        // Development machines may lack AVIF; a production image without it serves heavier pictures.
        if (app()->isProduction() && ! app(ImageProcessor::class)->canEncode('avif')) {
            $alerts[] = 'صيغة AVIF غير مدعومة على هذا السيرفر، فالصور تُقدَّم بـ WebP وJPEG فقط وبحجم أكبر. صورة Docker لازم يكون فيها GD مبني مع libavif.';
        }

        return $alerts;
    }
}
