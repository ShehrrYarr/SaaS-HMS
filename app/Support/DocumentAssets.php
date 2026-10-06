<?php

namespace App\Support;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Inline (data URI) images for PDFs: dompdf/TCPDF cannot read private storage URLs.
 */
class DocumentAssets
{
    public static function qr(string $data, int $scale = 4): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'scale' => $scale,
            'outputBase64' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 1,
        ]);

        return (new QRCode($options))->render($data);
    }

    public static function barcode(string $code, int $widthFactor = 2, int $height = 40): string
    {
        $png = (new BarcodeGeneratorPNG)->getBarcode($code, BarcodeGeneratorPNG::TYPE_CODE_128, $widthFactor, $height);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    public static function image(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    public static function logo(): string
    {
        $h = hospital();
        if ($h && ($custom = static::image($h->logo_path))) {
            return $custom;
        }
        $file = public_path('assets/images/hms-logo.png');

        return 'data:image/png;base64,'.base64_encode(file_get_contents($file));
    }
}
