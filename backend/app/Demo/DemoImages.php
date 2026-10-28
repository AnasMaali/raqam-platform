<?php

namespace App\Demo;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Abstract placeholder pictures for the demonstration content, drawn with GD in the brand palette: slanted rounded
 * shapes, hollow circles and bars; people are a letter in a circle (never a face). Each picture comes from its own seed,
 * so the same seed draws the same picture. Drawn at twice the size and scaled down, which smooths the edges.
 *
 * $scale shrinks the content pictures (tests use a small one to stay fast); banners keep their exact sizes because a
 * campaign's picture must fit its slot.
 */
class DemoImages
{
    public const PALETTE = ['#124FFF', '#21CEEA', '#8C8C8C', '#89A7FF', '#198FF4'];

    private const LIGHT = ['#F4F6FA', '#EEF2FB', '#E6F7FB', '#F7F8FA'];

    private const DARK = ['#0B1736', '#13224A', '#0F1B2D'];

    /** The slant of the parallelograms, in degrees from the horizontal. */
    private const SLANT = 55;

    /** @var list<string> */
    private array $files = [];

    public function __construct(public readonly float $scale = 1.0) {}

    /** A cover or gallery picture. */
    public function scene(int $width, int $height, int $seed): string
    {
        [$w, $h] = $this->size($width, $height);
        $rng = $this->rng($seed);
        $img = $this->canvas($w, $h);

        $dark = $rng->getInt(0, 3) === 0;
        $this->gradient($img, $dark ? $this->pick($rng, self::DARK) : $this->pick($rng, self::LIGHT), $dark ? '#1E3A8A' : '#FFFFFF', $w, $h);

        // A few large slanted shapes, then rings and bars over them.
        for ($i = 0, $n = $rng->getInt(2, 4); $i < $n; $i++) {
            $sh = $h * $rng->getFloat(0.25, 0.7);
            $sw = $w * $rng->getFloat(0.12, 0.35);
            $x = $w * $rng->getFloat(-0.1, 0.9);
            $y = $h * $rng->getFloat(-0.1, 0.8);
            $this->parallelogram($img, $x, $y, $sw, $sh, min($sw, $sh) * 0.18, $this->color($img, $this->pick($rng, self::PALETTE), $rng->getInt(0, 50)));
        }
        for ($i = 0, $n = $rng->getInt(1, 3); $i < $n; $i++) {
            $r = min($w, $h) * $rng->getFloat(0.08, 0.28);
            $this->ring($img, $w * $rng->getFloat(0.1, 0.9), $h * $rng->getFloat(0.1, 0.9), $r, $r * $rng->getFloat(0.08, 0.2),
                $this->color($img, $this->pick($rng, self::PALETTE), $rng->getInt(0, 40)), $rng->getInt(0, 1) === 0 ? 360 : $rng->getInt(120, 300));
        }
        if ($rng->getInt(0, 1) === 1) {
            $this->bars($img, $w * $rng->getFloat(0.05, 0.55), $h * 0.9, $w * 0.4, $h * $rng->getFloat(0.3, 0.6), $rng->getInt(4, 7), $rng);
        }

        return $this->save($img, $w, $h);
    }

    /** A tall picture with charts, the downloadable file of an infographic. */
    public function infographic(int $width, int $height, int $seed): string
    {
        [$w, $h] = $this->size($width, $height);
        $rng = $this->rng($seed);
        $img = $this->canvas($w, $h);
        $this->gradient($img, '#FFFFFF', $this->pick($rng, self::LIGHT), $w, $h);

        $band = $h * 0.12;
        $this->rect($img, 0, 0, $w, $band, $this->color($img, '#124FFF'));
        $this->parallelogram($img, $w * 0.62, $band * 0.15, $w * 0.12, $band * 0.7, $band * 0.1, $this->color($img, '#21CEEA'));
        $this->parallelogram($img, $w * 0.78, $band * 0.15, $w * 0.12, $band * 0.7, $band * 0.1, $this->color($img, '#89A7FF'));

        $section = ($h - $band) / 3;
        $this->bars($img, $w * 0.1, $band + $section * 0.9, $w * 0.8, $section * 0.65, $rng->getInt(5, 9), $rng);
        $r = min($w * 0.22, $section * 0.35);
        $this->ring($img, $w * 0.3, $band + $section * 1.5, $r, $r * 0.32, $this->color($img, '#E6E9F0'), 360);
        $this->ring($img, $w * 0.3, $band + $section * 1.5, $r, $r * 0.32, $this->color($img, '#198FF4'), $rng->getInt(90, 300));
        for ($i = 0; $i < 4; $i++) {
            $this->rect($img, $w * 0.58, $band + $section * (1.2 + $i * 0.15), $w * $rng->getFloat(0.15, 0.32), $section * 0.06, $this->color($img, $this->pick($rng, self::PALETTE)), $section * 0.03);
        }
        for ($i = 0; $i < 10; $i++) {
            $cx = $w * (0.12 + $i * 0.085);
            $this->disc($img, $cx, $band + $section * 2.5, $w * 0.03, $this->color($img, $i < $rng->getInt(3, 9) ? '#124FFF' : '#C9D3E6'));
        }

        return $this->save($img, $w, $h);
    }

    /** A person: the first letter of the name in a circle, on a brand colour. */
    public function portrait(string $name, int $seed): string
    {
        [$w, $h] = $this->size(1200, 1200);
        $rng = $this->rng($seed);
        $img = $this->canvas($w, $h);
        $base = $this->pick($rng, self::PALETTE);
        $this->gradient($img, $base, '#0B1736', $w, $h);
        $this->parallelogram($img, $w * 0.62, $h * -0.1, $w * 0.22, $h * 0.6, $w * 0.04, $this->color($img, '#FFFFFF', 105));
        $this->disc($img, $w / 2, $h / 2, $w * 0.32, $this->color($img, '#FFFFFF', 20));
        $this->letter($img, $this->initial($name), $w / 2, $h / 2, $w * 0.28, $this->color($img, $base));

        return $this->save($img, $w, $h);
    }

    /** An organisation's logo: a rounded square with a slanted mark and the first letter of its name. */
    public function logo(string $name, int $seed): string
    {
        [$w, $h] = $this->size(800, 800);
        $rng = $this->rng($seed);
        $img = $this->canvas($w, $h);
        $this->rect($img, 0, 0, $w, $h, $this->color($img, '#FFFFFF'));
        $color = $this->pick($rng, self::PALETTE);
        $this->rect($img, $w * 0.1, $h * 0.1, $w * 0.8, $h * 0.8, $this->color($img, $color), $w * 0.16);
        $this->parallelogram($img, $w * 0.58, $h * 0.18, $w * 0.14, $h * 0.64, $w * 0.03, $this->color($img, '#FFFFFF', 80));
        $this->letter($img, $this->initial($name), $w * 0.42, $h / 2, $w * 0.3, $this->color($img, '#FFFFFF'));

        return $this->save($img, $w, $h);
    }

    /** A still pose of an awareness character: a simple figure in its colour, with a sign of the pose. */
    public function pose(string $accent, string $pose, int $seed): string
    {
        [$w, $h] = $this->size(1000, 1000);
        $rng = $this->rng($seed);
        $img = $this->canvas($w, $h);
        $this->gradient($img, '#FFFFFF', '#EEF2FB', $w, $h);
        $ink = $this->color($img, $accent);
        $soft = $this->color($img, $accent, 90);

        $this->ring($img, $w * 0.5, $h * 0.62, $w * 0.42, $w * 0.01, $this->color($img, $this->pick($rng, self::PALETTE), 80), 360);
        $this->disc($img, $w * 0.5, $h * 0.3, $w * 0.13, $ink);                                   // head
        $this->parallelogram($img, $w * 0.3, $h * 0.47, $w * 0.3, $h * 0.42, $w * 0.05, $ink);     // body
        $white = $this->color($img, '#FFFFFF');
        $this->disc($img, $w * 0.455, $h * 0.28, $w * 0.018, $white);
        $this->disc($img, $w * 0.545, $h * 0.28, $w * 0.018, $white);

        match ($pose) {
            'smiling' => $this->ring($img, $w * 0.5, $h * 0.3, $w * 0.075, $w * 0.012, $white, 140, 20),
            'pointing' => $this->parallelogram($img, $w * 0.62, $h * 0.42, $w * 0.28, $h * 0.05, $h * 0.02, $ink),
            'warning' => $this->triangle($img, $w * 0.8, $h * 0.2, $w * 0.14, $this->color($img, '#F2B705')),
            'thinking' => array_map(fn ($i) => $this->disc($img, $w * (0.68 + $i * 0.07), $h * (0.22 - $i * 0.06), $w * (0.02 + $i * 0.015), $soft), [0, 1, 2]),
            default => $this->rect($img, $w * 0.66, $h * 0.38, $w * 0.26, $h * 0.2, $soft, $w * 0.02),   // explaining: a board
        };

        return $this->save($img, $w, $h);
    }

    /** An ad banner of an exact size (never scaled), with the size written small in a corner. */
    public function banner(int $width, int $height, int $seed, bool $house = false): string
    {
        $rng = $this->rng($seed);
        $img = $this->canvas($width, $height);
        $this->gradient($img, $house ? '#0B1736' : $this->pick($rng, self::PALETTE), $house ? '#124FFF' : '#0B1736', $width, $height);
        for ($i = 0; $i < 5; $i++) {
            $sh = $height * 0.8;
            $this->parallelogram($img, $width * (0.45 + $i * 0.11), $height * 0.1, $sh * 0.35, $sh, $sh * 0.08,
                $this->color($img, $this->pick($rng, self::PALETTE), 40 + $i * 12));
        }
        $this->ring($img, $width * 0.1, $height * 0.5, $height * 0.3, $height * 0.06, $this->color($img, '#FFFFFF', 30), 360);
        $this->label($img, $width.'×'.$height, $width * 0.98, $height * 0.94, $height * 0.08, $this->color($img, '#FFFFFF', 40));

        return $this->save($img, $width, $height);
    }

    /** Removes the temporary files written so far. */
    public function cleanUp(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    // ---- drawing ------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int} */
    private function size(int $width, int $height): array
    {
        return [max(64, (int) round($width * $this->scale)), max(64, (int) round($height * $this->scale))];
    }

    private function rng(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    private function pick(Randomizer $rng, array $list): mixed
    {
        return $list[$rng->getInt(0, count($list) - 1)];
    }

    /** Twice the final size: drawn big, scaled down in save(). */
    private function canvas(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor($w * 2, $h * 2);
        imagealphablending($img, true);

        return $img;
    }

    private function color(\GdImage $img, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
    }

    private function gradient(\GdImage $img, string $from, string $to, int $w, int $h): void
    {
        [$r1, $g1, $b1] = sscanf($from, '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($to, '#%02x%02x%02x');
        $rows = $h * 2;
        for ($y = 0; $y < $rows; $y++) {
            $t = $y / max(1, $rows - 1);
            imageline($img, 0, $y, $w * 2, $y, imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
        }
    }

    /** A parallelogram leaning at SLANT degrees with rounded corners; x, y is its top-left on the final scale. */
    private function parallelogram(\GdImage $img, float $x, float $y, float $w, float $h, float $radius, int $color): void
    {
        $dx = $h / tan(deg2rad(self::SLANT));
        $this->polygon($img, [[$x + $dx, $y], [$x + $dx + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]], $radius, $color);
    }

    private function rect(\GdImage $img, float $x, float $y, float $w, float $h, int $color, float $radius = 0): void
    {
        $this->polygon($img, [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]], $radius, $color);
    }

    private function triangle(\GdImage $img, float $cx, float $cy, float $size, int $color): void
    {
        $this->polygon($img, [[$cx, $cy - $size], [$cx + $size, $cy + $size * 0.8], [$cx - $size, $cy + $size * 0.8]], $size * 0.15, $color);
    }

    /** @param list<array{0: float, 1: float}> $points corners on the final scale; each is rounded with a short curve */
    private function polygon(\GdImage $img, array $points, float $radius, int $color): void
    {
        $out = [];
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            [$px, $py] = $points[($i - 1 + $n) % $n];
            [$vx, $vy] = $points[$i];
            [$nx, $ny] = $points[($i + 1) % $n];
            if ($radius <= 0) {
                array_push($out, (int) round($vx * 2), (int) round($vy * 2));

                continue;
            }
            $t1 = min(0.5, $radius / max(1e-6, hypot($px - $vx, $py - $vy)));
            $t2 = min(0.5, $radius / max(1e-6, hypot($nx - $vx, $ny - $vy)));
            $ax = $vx + ($px - $vx) * $t1;
            $ay = $vy + ($py - $vy) * $t1;
            $bx = $vx + ($nx - $vx) * $t2;
            $by = $vy + ($ny - $vy) * $t2;
            for ($s = 0; $s <= 6; $s++) {
                $u = $s / 6;
                $qx = (1 - $u) ** 2 * $ax + 2 * (1 - $u) * $u * $vx + $u ** 2 * $bx;
                $qy = (1 - $u) ** 2 * $ay + 2 * (1 - $u) * $u * $vy + $u ** 2 * $by;
                array_push($out, (int) round($qx * 2), (int) round($qy * 2));
            }
        }
        imagefilledpolygon($img, $out, $color);
    }

    private function disc(\GdImage $img, float $cx, float $cy, float $r, int $color): void
    {
        imagefilledellipse($img, (int) round($cx * 2), (int) round($cy * 2), (int) round($r * 4), (int) round($r * 4), $color);
    }

    /** A hollow circle (or an arc of $degrees from $start) of the given thickness: one polygon, out along the edge and back inside. */
    private function ring(\GdImage $img, float $cx, float $cy, float $r, float $thickness, int $color, int $degrees = 360, int $start = -90): void
    {
        $inner = max(0.5, $r - $thickness);
        $outer = [];
        $back = [];
        for ($a = 0; $a <= $degrees; $a += 3) {
            $t = deg2rad($start + $a);
            array_push($outer, (int) round(($cx + cos($t) * $r) * 2), (int) round(($cy + sin($t) * $r) * 2));
            array_unshift($back, (int) round(($cx + cos($t) * $inner) * 2), (int) round(($cy + sin($t) * $inner) * 2));
        }
        imagefilledpolygon($img, [...$outer, ...$back], $color);
    }

    /** A small bar chart standing on the line y = $base. */
    private function bars(\GdImage $img, float $x, float $base, float $w, float $h, int $count, Randomizer $rng): void
    {
        $gap = $w / $count;
        for ($i = 0; $i < $count; $i++) {
            $bh = $h * $rng->getFloat(0.25, 1.0);
            $this->rect($img, $x + $i * $gap, $base - $bh, $gap * 0.62, $bh, $this->color($img, $this->pick($rng, self::PALETTE), 10), $gap * 0.12);
        }
    }

    /** One letter, centred (a single Arabic letter needs no joining, so GD can draw it). Skipped when no font is at hand. */
    private function letter(\GdImage $img, string $letter, float $cx, float $cy, float $size, int $color): void
    {
        $font = self::font();
        if ($font === null || $letter === '') {
            return;
        }
        $box = imagettfbbox($size * 2, 0, $font, $letter);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];
        imagettftext($img, $size * 2, 0, (int) round($cx * 2 - $tw / 2 - $box[0]), (int) round($cy * 2 + $th / 2 - $box[1]), $color, $font, $letter);
    }

    /** Latin text aligned to the right edge at x (the size of a banner). */
    private function label(\GdImage $img, string $text, float $right, float $baseline, float $size, int $color): void
    {
        $font = self::font();
        if ($font === null) {
            return;
        }
        $box = imagettfbbox($size * 2, 0, $font, $text);
        imagettftext($img, $size * 2, 0, (int) round($right * 2 - ($box[2] - $box[0])), (int) round($baseline * 2), $color, $font, $text);
    }

    /** The bold DejaVu face shipped with the PDF library (it has the Arabic letters); null when it is not installed. */
    private static function font(): ?string
    {
        $path = base_path('vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf');

        return function_exists('imagettftext') && is_file($path) ? $path : null;
    }

    private function initial(string $name): string
    {
        // Skip the article: «الأفق» is drawn as «أ», not «ا».
        $name = preg_replace('/^(ال|شركة |مصنع |مركز |مختبرات |تعاونية |جمعية |صندوق |دار |حاضنة |مزارع |مطاحن |محاجر |مخابز |حلويات |معصرة |نسيج |شمس |ضوء )+/u', '', trim($name)) ?? $name;
        $name = preg_replace('/^ال/u', '', $name) ?? $name;

        return mb_substr($name, 0, 1);
    }

    /** Scales the drawing down to its final size and writes it as a JPEG; returns the temporary file. */
    private function save(\GdImage $img, int $w, int $h): string
    {
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, $w * 2, $h * 2);
        $path = tempnam(sys_get_temp_dir(), 'raqam-demo-');
        imagejpeg($out, $path, 90);
        $this->files[] = $path;

        return $path;
    }
}
