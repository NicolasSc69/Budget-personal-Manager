<?php

namespace App\Service;

/**
 * Generates readable random colors (moderate saturation/lightness so white text stays legible),
 * guaranteeing uniqueness against a list of colors already in use.
 */
final class ColorGenerator
{
    /**
     * Returns a random color absent from $usedColors (compared case-insensitively) and appends it
     * to that list, so successive calls with the same array never return the same color twice.
     *
     * @param list<string> $usedColors upper-cased hexadecimal colors already in use
     */
    public function generateUnique(array &$usedColors): string
    {
        do {
            $color = $this->hslToHex(random_int(0, 359), random_int(35, 55), random_int(35, 48));
        } while (\in_array($color, $usedColors, true));

        $usedColors[] = $color;

        return $color;
    }

    /**
     * Returns $count distinct colors whose hues are spread evenly around the color wheel (from a
     * random starting hue, in a random order), so neighbouring items stay easy to tell apart.
     *
     * @return list<string>
     */
    public function generateSpreadPalette(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $hueStep = 360 / $count;
        $hueOffset = random_int(0, 359);
        $slots = range(0, $count - 1);
        shuffle($slots);

        $palette = [];

        foreach ($slots as $slot) {
            $hue = ((int) round($slot * $hueStep) + $hueOffset) % 360;
            $saturation = random_int(35, 55);
            $lightness = random_int(35, 48);
            $color = $this->hslToHex($hue, $saturation, $lightness);

            while (\in_array($color, $palette, true)) {
                $hue = ($hue + 1) % 360;
                $color = $this->hslToHex($hue, $saturation, $lightness);
            }

            $palette[] = $color;
        }

        return $palette;
    }

    public function hslToHex(int $hue, int $saturation, int $lightness): string
    {
        $s = $saturation / 100;
        $l = $lightness / 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$c, $x, 0],
            $hue < 120 => [$x, $c, 0],
            $hue < 180 => [0, $c, $x],
            $hue < 240 => [0, $x, $c],
            $hue < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return \sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
