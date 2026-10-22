<?php

namespace App\Support;

use Mpdf\Container\SimpleContainer;
use Mpdf\File\LocalContentLoaderInterface;
use Mpdf\Http\ClientInterface;
use Mpdf\Mpdf;
use Mpdf\PsrHttpMessageShim\Response;
use Psr\Http\Message\RequestInterface;

/**
 * An mPDF document that reads nothing while it is drawn: every image and stylesheet it would fetch, from an address or
 * from a file of the server, comes back empty (its two readers are replaced). What it shows is the HTML it is given
 * and mPDF's own fonts, nothing else.
 */
final class OfflinePdf
{
    /** @param array<string, mixed> $config mPDF settings */
    public static function make(array $config): Mpdf
    {
        $dir = storage_path('framework/cache/mpdf');
        is_dir($dir) || mkdir($dir, 0775, true);

        $noAddress = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request)
            {
                return new Response(403);
            }
        };
        $noFile = new class implements LocalContentLoaderInterface
        {
            public function load($path)
            {
                return '';
            }
        };

        return new Mpdf($config + ['tempDir' => $dir], new SimpleContainer(['httpClient' => $noAddress, 'localContentLoader' => $noFile]));
    }
}
