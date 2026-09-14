<?php

declare(strict_types=1);

namespace Thallo\Contracts\Preview;

/**
 * The fragment path of the canvas apply (visual builder spec §3.5): after an apply is accepted,
 * the renderer derives the affected blocks from the operations, resolves the minimal render
 * roots and renders them in isolation, so the stage swaps fragments instead of refreshing.
 * `null` means the whole-page path — the caller refreshes the stage from accepted state — and
 * is the answer whenever anything about the request cannot be proven safe.
 */
interface PreviewFragmentRenderer
{
    /**
     * @param string $token the preview token the apply carried (the render resolves it)
     * @param list<array<string,mixed>> $operations the committed operations since the base revision
     * @param array<string,mixed>|null $before the accepted working copy before this apply; null = none
     * @param array<string,mixed> $after the validated fields this apply accepted
     * @param list<string> $blockFields the content type's blocks-typed field names (the block roots)
     * @param list<string>|null $debugChanged the client's own affected-block list (development only)
     * @return array<string,string>|null block id => the block's annotated markup; null = whole page
     */
    public function render(
        string $token,
        array $operations,
        ?array $before,
        array $after,
        array $blockFields,
        ?array $debugChanged = null,
    ): ?array;
}
