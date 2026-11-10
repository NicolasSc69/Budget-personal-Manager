<?php

namespace App\Tests\Service;

use App\Service\ColorGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorGeneratorTest extends TestCase
{
    private const HEX_PATTERN = '/^#[0-9A-F]{6}$/';

    private ColorGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ColorGenerator();
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: string}>
     */
    public static function provideHslColors(): iterable
    {
        yield 'black' => [0, 0, 0, '#000000'];
        yield 'white' => [0, 0, 100, '#FFFFFF'];
        yield 'red' => [0, 100, 50, '#FF0000'];
        yield 'green' => [120, 100, 50, '#00FF00'];
        yield 'blue' => [240, 100, 50, '#0000FF'];
        yield 'yellow' => [60, 100, 50, '#FFFF00'];
        yield 'magenta' => [300, 100, 50, '#FF00FF'];
        yield 'mid grey' => [200, 0, 50, '#808080'];
    }

    #[DataProvider('provideHslColors')]
    public function testHslToHexConvertsKnownColors(int $hue, int $saturation, int $lightness, string $expected): void
    {
        self::assertSame($expected, $this->generator->hslToHex($hue, $saturation, $lightness));
    }

    public function testGenerateUniqueReturnsAnUpperCaseHexColorAndRecordsIt(): void
    {
        $usedColors = [];

        $color = $this->generator->generateUnique($usedColors);

        self::assertMatchesRegularExpression(self::HEX_PATTERN, $color);
        self::assertSame([$color], $usedColors);
    }

    public function testGenerateUniqueNeverReturnsAColorAlreadyUsed(): void
    {
        $usedColors = [];

        for ($i = 0; $i < 300; ++$i) {
            $this->generator->generateUnique($usedColors);
        }

        self::assertCount(300, $usedColors);
        self::assertCount(300, array_unique($usedColors));
    }

    public function testGenerateUniqueSkipsTheColorsPassedIn(): void
    {
        $preset = [];
        for ($i = 0; $i < 50; ++$i) {
            $this->generator->generateUnique($preset);
        }
        $usedColors = $preset;

        $color = $this->generator->generateUnique($usedColors);

        self::assertNotContains($color, $preset);
    }

    public function testGeneratedColorsStayReadableUnderWhiteText(): void
    {
        $usedColors = [];

        for ($i = 0; $i < 100; ++$i) {
            $color = $this->generator->generateUnique($usedColors);
            [$r, $g, $b] = sscanf($color, '#%02X%02X%02X');

            // HSL lightness = (max + min) / 2: generated colors stay at or below 48 % (+ rounding).
            $lightness = (max($r, $g, $b) + min($r, $g, $b)) / 2 / 255;
            self::assertGreaterThanOrEqual(0.34, $lightness, $color);
            self::assertLessThanOrEqual(0.49, $lightness, $color);
        }
    }

    public function testGenerateSpreadPaletteReturnsNothingForZeroOrNegativeCount(): void
    {
        self::assertSame([], $this->generator->generateSpreadPalette(0));
        self::assertSame([], $this->generator->generateSpreadPalette(-3));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function providePaletteSizes(): iterable
    {
        yield 'one' => [1];
        yield 'a few' => [5];
        yield 'many' => [60];
        yield 'more than the hues' => [400];
    }

    #[DataProvider('providePaletteSizes')]
    public function testGenerateSpreadPaletteReturnsTheRequestedNumberOfDistinctColors(int $count): void
    {
        $palette = $this->generator->generateSpreadPalette($count);

        self::assertCount($count, $palette);
        self::assertTrue(array_is_list($palette));
        self::assertCount($count, array_unique($palette));
        foreach ($palette as $color) {
            self::assertMatchesRegularExpression(self::HEX_PATTERN, $color);
        }
    }
}
