<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder;
use Thallo\Core\Content\Fonts\UnreadableFont;

/**
 * The port's outcome equals upstream's on the whole differential corpus (plan Task 1): google/brotli's
 * own test data and SynthTest vectors at the pinned commit, streams from the C encoder chosen for
 * specific decoder paths, and truncated or mutated streams with the C decoder's verdict.
 * Rebuild the corpus with scripts/build-brotli-vectors.py.
 */
final class BrotliDifferentialTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/brotli';

    /** Outcomes are compared without the production deadline: this test is about correctness. */
    private function decoder(): PurePhpBrotliDecoder
    {
        return new PurePhpBrotliDecoder(600.0);
    }

    /** @return array<mixed> */
    private static function json(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::DIR . '/' . $name), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * The port's outcome in the corpus's terms: the output's length and SHA-256, or 'error'.
     *
     * @param 'error'|array{length: int, sha256: string} $expected
     * @return 'error'|array{length: int, sha256: string}
     */
    private function outcome(string $compressed, string|array $expected): string|array
    {
        // A success is decoded with its exact length as the cap; an expected refusal with a generous one.
        $cap = is_array($expected) ? $expected['length'] : 1 << 25;
        try {
            $out = $this->decoder()->decode($compressed, $cap);
        } catch (UnreadableFont $e) {
            self::assertContains($e->reason, ['Couldn\'t read this font\'s data', 'This font is too large to read']);
            return 'error';
        }
        return ['length' => strlen($out), 'sha256' => hash('sha256', $out)];
    }

    /** @param list<string> $mismatches */
    private static function assertNoMismatch(array $mismatches, int $total): void
    {
        self::assertGreaterThan(0, $total);
        $summary = count($mismatches) . " of {$total} disagree with upstream";
        self::assertSame([], array_slice($mismatches, 0, 25), $summary);
    }

    /** @param array<string, array{length: int, sha256: string}> $manifest */
    private function assertFilesMatch(string $dir, array $manifest): void
    {
        $mismatches = [];
        foreach ($manifest as $file => $expected) {
            $actual = $this->outcome((string) file_get_contents(self::DIR . "/{$dir}/{$file}"), $expected);
            if ($actual !== $expected) {
                $mismatches[] = $file . ': ' . json_encode($actual);
            }
        }
        self::assertNoMismatch($mismatches, count($manifest));
    }

    public function testTheUpstreamCorpusDecodesAsUpstreamExpects(): void
    {
        /** @var array<string, array{length: int, sha256: string}> $manifest */
        $manifest = self::json('upstream.json');
        self::assertCount(45, $manifest);
        $this->assertFilesMatch('upstream', $manifest);
    }

    public function testUpstreamSynthVectorsDecodeOrFailAsUpstreamExpects(): void
    {
        $mismatches = [];
        $vectors = self::json('synth.json');
        foreach ($vectors as $name => $vector) {
            /** @var array{compressed: string, outcome: 'error'|array{length: int, sha256: string}} $vector */
            $actual = $this->outcome((string) base64_decode($vector['compressed'], true), $vector['outcome']);
            if ($actual !== $vector['outcome']) {
                $mismatches[] = $name . ': ' . json_encode($actual);
            }
        }
        self::assertNoMismatch($mismatches, count($vectors));
    }

    public function testGeneratedStreamsCoveringDecoderPathsMatchUpstream(): void
    {
        /** @var array<string, array{length: int, sha256: string}> $manifest */
        $manifest = self::json('generated.json');
        $this->assertFilesMatch('generated', $manifest);
    }

    public function testTruncatedAndMutatedStreamsMatchUpstreamsVerdict(): void
    {
        $bases = [];
        $mismatches = [];
        $entries = self::json('mutated.json');
        foreach ($entries as $entry) {
            /** @var array{base: string, op: list<int|string>, outcome: 'error'|array{length: int, sha256: string}} $entry */
            $data = $bases[$entry['base']] ??= (string) file_get_contents(self::DIR . '/' . $entry['base']);
            $op = $entry['op'];
            $mutant = match ($op[0]) {
                'truncate' => substr($data, 0, (int) $op[1]),
                'flip' => substr_replace($data, chr(ord($data[(int) $op[1]]) ^ (1 << (int) $op[2])), (int) $op[1], 1),
                'set' => substr_replace($data, chr((int) $op[2]), (int) $op[1], 1),
                default => self::fail('unknown mutation ' . json_encode($op)),
            };
            $actual = $this->outcome($mutant, $entry['outcome']);
            if ($actual !== $entry['outcome']) {
                $mismatches[] = $entry['base'] . ' ' . json_encode($op) . ': ' . json_encode($actual);
            }
        }
        self::assertNoMismatch($mismatches, count($entries));
    }
}
