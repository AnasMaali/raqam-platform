<?php

/** Helpers for the media tests: real image bytes made on the spot, so no binary fixtures live in the repository. */

/** @return string bytes of a w x h picture in the given format (jpeg, png, webp, avif) */
function imageBytes(int $w = 200, int $h = 100, string $format = 'jpeg', bool $alpha = false, bool $noise = false): string
{
    $img = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
    }
    // A red block on the left and a green one on the right, so crops and rotations can be told apart.
    imagefilledrectangle($img, 0, 0, intdiv($w, 4), $h, imagecolorallocate($img, 220, 30, 30));
    imagefilledrectangle($img, $w - intdiv($w, 4), 0, $w, $h, imagecolorallocate($img, 30, 200, 60));

    if ($noise) {
        mt_srand(7);
        for ($i = 0; $i < $w * $h / 2; $i++) {
            imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($img, null, 90),
        'png' => imagepng($img),
        'webp' => imagewebp($img, null, 90),
        'avif' => imageavif($img, null, 60),
    };

    return (string) ob_get_clean();
}

/** A temporary file holding these bytes; removed when the test process ends. */
function uploadFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'raqam-up-');
    file_put_contents($path, $bytes);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/** Inserts an EXIF APP1 segment (orientation and optionally a GPS position) right after the JPEG's SOI marker. */
function jpegWithExif(string $jpeg, int $orientation = 1, bool $gps = false): string
{
    $entries = [[0x0112, 3, 1, pack('v', $orientation)."\0\0"]];   // Orientation, SHORT
    if ($gps) {
        $entries[] = [0x8825, 4, 1, null];   // GPSInfo pointer, patched below
    }

    $ifd0Size = 2 + count($entries) * 12 + 4;
    $gpsOffset = 8 + $ifd0Size;
    $tiff = 'II*'."\0".pack('V', 8).pack('v', count($entries));
    foreach ($entries as [$tag, $type, $count, $value]) {
        $tiff .= pack('vvV', $tag, $type, $count).($value ?? pack('V', $gpsOffset));
    }
    $tiff .= pack('V', 0);

    if ($gps) {
        // GPS IFD: LatitudeRef "N", Latitude 32/1 deg 13/1 min 0/1 s (Nablus, roughly)
        $dataOffset = $gpsOffset + 2 + 2 * 12 + 4;
        $tiff .= pack('v', 2)
            .pack('vvV', 0x0001, 2, 2)."N\0\0\0"
            .pack('vvV', 0x0002, 5, 3).pack('V', $dataOffset)
            .pack('V', 0)
            .pack('V6', 32, 1, 13, 1, 0, 1);
    }

    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function hasGps(string $jpeg): bool
{
    $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg), 'ANY_TAG');

    return is_array($exif) && (isset($exif['GPSLatitude']) || isset($exif['GPSLatitudeRef']) || isset($exif['GPS']));
}

/** A pretend ffprobe: a script that prints the given JSON, so video tests need no real ffmpeg install. */
function fakeFfprobe(array $answer, int $exit = 0): void
{
    $dir = sys_get_temp_dir().'/raqam-fake-ffprobe-'.getmypid();
    @mkdir($dir);
    file_put_contents("$dir/answer.json", json_encode($answer));
    file_put_contents("$dir/ffprobe", "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then echo 'ffprobe version fake'; exit 0; fi\ncat '$dir/answer.json'\nexit $exit\n");
    chmod("$dir/ffprobe", 0755);
    config(['media.ffprobe' => "$dir/ffprobe"]);
    register_shutdown_function(fn () => array_map('unlink', glob("$dir/*") ?: []) && @rmdir($dir));
}

/** What ffprobe prints for an ordinary phone clip. */
function ffprobeAnswer(float $seconds = 30.0, int $width = 1280, int $height = 720, string $codec = 'h264', string $format = 'mov,mp4,m4a,3gp,3g2,mj2'): array
{
    return ['format' => ['format_name' => $format, 'duration' => (string) $seconds],
        'streams' => [['codec_type' => 'video', 'codec_name' => $codec, 'width' => $width, 'height' => $height]]];
}

/** The first bytes of an MP4 (an ftyp box); the fake ffprobe does the rest of the "reading". */
function mp4Bytes(int $extra = 2000): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x01", $extra);
}
