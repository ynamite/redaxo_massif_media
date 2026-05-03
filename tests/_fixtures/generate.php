<?php

declare(strict_types=1);

// One-shot fixture regenerator. Run manually with `php tests/_fixtures/generate.php`
// when image formats need to change. NOT executed as part of the test suite.

$fixturesDir = __DIR__;

if (!extension_loaded('imagick') && !extension_loaded('gd')) {
    fwrite(STDERR, "Need Imagick or GD to generate fixtures.\n");
    exit(1);
}

$useImagick = extension_loaded('imagick');

function makeImage(int $w, int $h, string $color, string $outPath, string $format, bool $imagick): void
{
    if ($imagick) {
        $im = new Imagick();
        $im->newImage($w, $h, new ImagickPixel($color));
        $im->setImageFormat($format);
        if ($format === 'jpeg') {
            $im->setImageCompressionQuality(85);
        }
        $im->writeImage($outPath);
        $im->clear();
        return;
    }

    $img = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x') ?: [128, 128, 128];
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    match ($format) {
        'jpeg' => imagejpeg($img, $outPath, 85),
        'png' => imagepng($img, $outPath),
        'gif' => imagegif($img, $outPath),
    };
    imagedestroy($img);
}

makeImage(800, 600, '#3366aa', $fixturesDir . '/landscape-800x600.jpg', 'jpeg', $useImagick);
makeImage(600, 800, '#aa3366', $fixturesDir . '/portrait-600x800.jpg', 'jpeg', $useImagick);
makeImage(400, 400, '#33aa66', $fixturesDir . '/square-400x400.png', 'png', $useImagick);
makeImage(32, 32, '#aaaa33', $fixturesDir . '/tiny-32x32.gif', 'gif', $useImagick);

// Animated GIF — three solid-colour frames. Used by AnimatedWebpEncoder
// integration tests; needs >1 frame so MetadataReader::probeAnimated flips
// isAnimated to true and the encoder produces multi-frame WebP output.
if ($useImagick) {
    $gif = new Imagick();
    foreach (['#ff0000', '#00ff00', '#0000ff'] as $color) {
        $frame = new Imagick();
        $frame->newImage(64, 64, new ImagickPixel($color));
        $frame->setImageFormat('gif');
        $frame->setImageDelay(20);
        $gif->addImage($frame);
        $frame->clear();
    }
    $gif->setImageFormat('gif');
    $gif->writeImages($fixturesDir . '/animated-3frame.gif', true);
    $gif->clear();
} else {
    fwrite(STDERR, "Skipping animated GIF fixture: needs Imagick.\n");
}

file_put_contents(
    $fixturesDir . '/vector.svg',
    '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' . "\n"
    . '<rect width="100" height="100" fill="#33aa66"/>' . "\n"
    . '<circle cx="50" cy="50" r="30" fill="#3366aa"/>' . "\n"
    . '</svg>' . "\n",
);

echo "Generated 5 fixtures in $fixturesDir\n";
