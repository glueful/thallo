<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder;
use Thallo\Core\Content\Fonts\UnreadableFont;

/** Every decoder Thallo may select meets one bounded contract (spec §2.3; plan Task 1). */
final class BrotliDecoderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts/brotli';
    private const PROBE = __DIR__ . '/../../Support/Fonts/decode-probe.php';
    private const MEMORY_BUDGET = 32 * 1024 * 1024;
    private const PORT = __DIR__ . '/../../../core/src/Content/Fonts/Brotli/Port';

    private function decoder(): BrotliDecoder
    {
        return new PurePhpBrotliDecoder();
    }

    public function testEveryVectorDecodesByteExact(): void
    {
        $files = glob(self::DIR . '/*.q*.br') ?: [];
        self::assertCount(18, $files);
        foreach ($files as $file) {
            $raw = (string) file_get_contents((string) preg_replace('/\.q\d+\.br$/', '.raw', $file));
            $decoded = $this->decoder()->decode((string) file_get_contents($file), 1 << 22);
            self::assertSame($raw, $decoded, basename($file));
        }
    }

    public function testACorruptStreamIsUnreadableNotAnError(): void
    {
        $this->expectException(UnreadableFont::class);
        $this->expectExceptionMessage('Couldn\'t read this font\'s data');
        $this->decoder()->decode(substr((string) file_get_contents(self::DIR . '/text.q11.br'), 0, 40), 1 << 20);
    }

    public function testOutputPastTheCapIsRefused(): void
    {
        $this->expectExceptionMessage('This font is too large to read');
        $this->decoder()->decode((string) file_get_contents(self::DIR . '/text.q11.br'), 1063);
    }

    public function testOutputExactlyAtTheCapIsAccepted(): void
    {
        $decoded = $this->decoder()->decode((string) file_get_contents(self::DIR . '/text.q11.br'), 1064);
        self::assertSame(1064, strlen($decoded));
    }

    public function testTheDecodersOwnDeadlineRefusesASlowDecode(): void
    {
        $this->expectExceptionMessage('This font took too long to read');
        $stream = (string) file_get_contents(self::DIR . '/big-tables.q11.br');
        (new PurePhpBrotliDecoder(0.001))->decode($stream, 1 << 22);
    }

    public function testTheDictionaryIsUpstreamsByteForByte(): void
    {
        // c/common/dictionary.bin at google/brotli 42a2ed4355bc6287da6bb6319f090b499cba4550.
        $dictionary = (string) file_get_contents(self::PORT . '/dictionary.bin');
        self::assertSame(122784, strlen($dictionary));
        $sha256 = '20e42eb1b511c21806d4d227d07e5dd06877d8ce7b3a817f378f313653f35c70';
        self::assertSame($sha256, hash('sha256', $dictionary));
    }

    /**
     * Runs the probe in a child process, killed at the deadline; returns its JSON report.
     *
     * @return array{result: string, baseline: int, peak: int}
     */
    private static function probe(string $which, string $file = self::DIR . '/expansion-64m.br'): array
    {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', self::PROBE, $which, $file, (string) (1 << 20)];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 10.0;
        $out = '';
        while (proc_get_status($proc)['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                proc_close($proc);
                return ['result' => 'KILLED', 'baseline' => 0, 'peak' => PHP_INT_MAX];
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        $report = json_decode($out, true);
        self::assertIsArray($report, $out . $err);
        /** @var array{result: string, baseline: int, peak: int} $report */
        return $report;
    }

    /** @param array{result: string, baseline: int, peak: int} $report */
    private static function assertWithinBudget(array $report): void
    {
        // The budget leaves room for the decoder's window and tables; a 64 MiB expansion cannot fit.
        self::assertLessThan($report['baseline'] + self::MEMORY_BUDGET, $report['peak'], 'allocated far past the cap');
    }

    /** The safety check: refused at the cap, within the deadline, without allocating the expansion. */
    public function testExpansionIsBoundedInAllocationAndTime(): void
    {
        $report = self::probe('pure');
        self::assertSame('REFUSED:This font is too large to read', $report['result']);
        self::assertWithinBudget($report);
    }

    /** Headers demanding 256 block types and 256 trees per group cannot force an unbounded allocation. */
    public function testMalformedHeadersCannotForceALargeAllocation(): void
    {
        $report = self::probe('pure', __DIR__ . '/../../fixtures/brotli/max-trees.br');
        self::assertSame('REFUSED:Couldn\'t read this font\'s data', $report['result']);
        self::assertWithinBudget($report);
    }

    /**
     * The negative control proves the MEMORY check specifically: the unbounded decoder must produce a
     * valid report with the expected refusal (a crash, malformed output or a timeout fails here, as
     * setup, rather than counting as proof), and its peak must exceed the budget the checks use.
     */
    public function testTheMemoryCheckDetectsAllocateThenRefuse(): void
    {
        $report = self::probe('unbounded');
        self::assertNotSame('KILLED', $report['result'], 'the control timed out: no proof either way');
        self::assertSame('REFUSED:This font is too large to read', $report['result']);
        self::assertGreaterThanOrEqual(
            $report['baseline'] + self::MEMORY_BUDGET,
            $report['peak'],
            'the control stayed inside the budget, so the memory check proves nothing',
        );
    }

    public function testThePurePhpDecoderIsFastEnoughOnRealTables(): void
    {
        $decoder = new PurePhpBrotliDecoder();
        foreach (['font-tables' => 2.0, 'big-tables' => 20.0] as $name => $budget) {
            $start = hrtime(true);
            $decoder->decode((string) file_get_contents(self::DIR . "/{$name}.q11.br"), 1 << 22);
            self::assertLessThan($budget, (hrtime(true) - $start) / 1e9, $name);
        }
    }

    public function testThePurePhpDecoderIsSelected(): void
    {
        self::assertInstanceOf(PurePhpBrotliDecoder::class, BrotliDecoders::best());
    }
}
