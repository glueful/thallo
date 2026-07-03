<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Sanitizes rich-editor HTML to the allowlisted TipTap vocabulary (sanitizer spec
 * §1–§2). The output is safe to render raw. Idempotent — sanitizing already-clean
 * content is a no-op, so save-time + render-time double application costs nothing.
 */
interface RichHtmlSanitizer
{
    public function sanitize(string $html): string;
}
