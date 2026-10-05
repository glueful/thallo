<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FaceMetadata;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Thallo\Core\Content\Fonts\Woff2FaceReader;

/** A face's weight, style and range come from the file, never from labels (spec §2.3; plan Task 1). */
final class Woff2FaceReaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts';

    /** @return array{bool, bool, int, int} variable, italic, weight min, weight max */
    private static function described(FaceMetadata $face): array
    {
        return [$face->variable, $face->italic, $face->weightMin, $face->weightMax];
    }

    private function reader(int $max = 33554432): Woff2FaceReader
    {
        return new Woff2FaceReader(BrotliDecoders::best(), $max);
    }

    public function testAVariableFontReportsItsWeightRange(): void
    {
        $face = $this->reader()->read(self::DIR . '/variable.woff2');
        self::assertSame([true, false, 300, 900], self::described($face));
    }

    public function testAVariableItalicIsItalic(): void
    {
        $face = $this->reader()->read(self::DIR . '/variable-italic.woff2');
        self::assertSame([true, true, 300, 900], self::described($face));
    }

    public function testAStaticFaceReportsOneWeight(): void
    {
        $face = $this->reader()->read(self::DIR . '/static-700.woff2');
        self::assertSame([false, false, 700, 700], self::described($face));
        $italic = $this->reader()->read(self::DIR . '/static-400-italic.woff2');
        self::assertSame([false, true, 400, 400], self::described($italic));
    }

    public function testAFontLargerThanTheReadersCapIsRefusedBeforeDecoding(): void
    {
        try {
            $this->reader(1024)->read(self::DIR . '/variable.woff2');
            self::fail('read');
        } catch (UnreadableFont $e) {
            self::assertSame('This font is too large to read', $e->reason);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'truncated' => ['truncated.woff2', 'Couldn\'t read this font\'s data'];
        yield 'not a font' => ['not-a-font.woff2', 'Not a WOFF2 font'];
        yield 'woff 1' => ['wrong-flavour.woff', 'Not a WOFF2 font'];
        yield 'bomb' => ['bomb.woff2', 'This font is too large to read'];
        yield 'missing file' => ['no-such-file.woff2', 'Couldn\'t read this font\'s data'];
    }

    #[DataProvider('refused')]
    public function testAnUnsupportedFileIsRefusedWithItsReason(string $file, string $reason): void
    {
        try {
            $this->reader()->read(self::DIR . '/' . $file);
            self::fail('read');
        } catch (UnreadableFont $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /** A UIntBase128 with a leading zero byte breaks the WOFF2 rules, whatever follows. */
    public function testALeadingZeroInADirectoryLengthIsRefused(): void
    {
        $font = (string) file_get_contents(self::DIR . '/static-700.woff2');
        // The first directory entry starts at byte 48: its flags byte, then origLength (UIntBase128).
        $broken = substr($font, 0, 49) . "\x80" . substr($font, 49);
        $path = tempnam(sys_get_temp_dir(), 'woff2');
        self::assertIsString($path);
        file_put_contents($path, $broken);
        try {
            $this->reader()->read($path);
            self::fail('read');
        } catch (UnreadableFont $e) {
            self::assertSame('Couldn\'t read this font\'s data', $e->reason);
        } finally {
            unlink($path);
        }
    }
}
