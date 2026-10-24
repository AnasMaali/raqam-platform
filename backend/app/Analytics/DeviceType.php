<?php

namespace App\Analytics;

/**
 * Phone, tablet or computer (22.7) from the browser's User-Agent. An Android browser without "Mobile" is a tablet.
 * An iPad with iPadOS 13 or later says it is a Mac and is counted as a computer: the header cannot tell them apart.
 */
final class DeviceType
{
    public static function of(?string $agent): string
    {
        $ua = (string) $agent;

        return match (true) {
            preg_match('~iPad|Tablet|Kindle|Silk/|PlayBook|Nexus (7|9|10)\b|SM-T\d~i', $ua) === 1,
            stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false => 'tablet',
            preg_match('~Mobi|iPhone|iPod|Android|Windows Phone|IEMobile|Opera Mini|BlackBerry|BB10~i', $ua) === 1 => 'mobile',
            default => 'desktop',
        };
    }
}
