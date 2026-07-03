<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Resolves the ONE in-place-editable rich field of a block type, or null when
 * the type is not prose-shaped (edit-in-place spec §1–§2). Consumed soft-bound
 * by the render pack's safe_html marking; implemented by the content engine
 * over block-type schemas. The convention (exactly one `text`/`format: rich`
 * field) is NOT a stable identity contract — when `editor_mode` metadata
 * lands, implementations consult it first.
 */
interface BlockEditableFieldResolver
{
    public function editableRichField(string $typeSlug): ?string;
}
