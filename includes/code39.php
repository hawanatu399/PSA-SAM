<?php
declare(strict_types=1);

function renderAssetIdBarcode(int $assetId): string
{
    $patterns = [
        '0' => 'nnnwwnwnw',
        '1' => 'wnnwnnnnw',
        '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        '*' => 'nwnnwnwnn',
    ];
    $code = '*' . (string) $assetId . '*';
    $moduleWidth = 2;
    $quietZone = 12 * $moduleWidth;
    $barHeight = 46;
    $x = $quietZone;
    $bars = [];

    foreach (str_split($code) as $character) {
        $pattern = $patterns[$character];
        foreach (str_split($pattern) as $index => $widthCode) {
            $width = ($widthCode === 'w' ? 3 : 1) * $moduleWidth;
            if ($index % 2 === 0) {
                $bars[] = '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $barHeight . '"/>';
            }
            $x += $width;
        }
        $x += $moduleWidth;
    }

    $svgWidth = $x + $quietZone;
    return '<svg class="asset-label-barcode" xmlns="http://www.w3.org/2000/svg" role="img" '
        . 'aria-label="Code 39 barcode for asset ID ' . $assetId . '" viewBox="0 0 '
        . $svgWidth . ' ' . $barHeight . '" preserveAspectRatio="none">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<g fill="#000" shape-rendering="crispEdges">' . implode('', $bars) . '</g></svg>';
}
