<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;
use Glueful\Lemma\Contracts\Navigation\MenuReader;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The theme-facing template functions. The extension is the per-render context object:
 * the controller sets the current locale before rendering (request-scoped in classic
 * PHP — deliberately not global static state).
 *
 *   menu(slug)      → MenuReader tree for the current locale; [] when navigation is
 *                     absent or disabled (render has NO hard dependency on it)
 *   path(entryUuid) → the entry's live public path; null unless published (a template
 *                     can never emit a dead link)
 *   asset(rel)      → /theme-assets/{rel}; rejects absolute URLs, .., leading /, and
 *                     backslashes with a template-facing error naming the value
 *   facets(type, field) → facet counts (preview spec §5); the result's cache tags go
 *                     into the render-scoped collector (reset by the controller before
 *                     EVERY render; drained after success) so facet pages purge
 *                     event-driven
 */
final class RenderContextExtension extends AbstractExtension
{
    private string $locale;

    /** @var array<string,string> render-scoped surrogate tags (see resetTags/drainTags) */
    private array $collectedTags = [];

    /** Render-scoped asset-base override (see setAssetBase). */
    private ?string $assetBase = null;

    /** @var array<string,bool> block types already logged this process (log ONCE per type) */
    private array $loggedBlockMisses = [];

    public function __construct(
        private readonly ?MenuReader $menus,
        private readonly EntryTargetResolver $targets,
        string $defaultLocale = 'en',
        private readonly ?FacetCountsReader $facetReader = null,
        // Provider-injected (block-builder spec §6) — NEVER read from Twig context.
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
        $this->locale = $defaultLocale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu', $this->menu(...)),
            new TwigFunction('path', $this->path(...)),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('facets', $this->facets(...)),
            new TwigFunction('blocks', $this->blocks(...), [
                'needs_environment' => true,
                'needs_context' => true,
                'is_safe' => ['html'],
            ]),
        ];
    }

    /**
     * Render an ordered blocks list through blocks/{type}.twig (block-builder spec §6).
     * Context per block: {block, data, entry, index} — `entry` is the CALLER's entry
     * (needs_context), read-only ambient state. Missing templates: prod = HTML comment,
     * debug = visible placeholder; logged once per type per process. Malformed items
     * and path-unsafe type slugs are skipped with the same once-per-type logging — a
     * template never explodes over data. The block-type REGISTRY is never consulted
     * here: rendering is a pure template convention.
     *
     * @param array<string,mixed> $context
     */
    public function blocks(Environment $env, array $context, mixed $list): string
    {
        if (!is_array($list) || !array_is_list($list)) {
            return '';
        }
        $entry = $context['entry'] ?? null;
        $html = [];
        foreach ($list as $index => $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : null;
            if ($type === null || preg_match('/\A[a-z][a-z0-9_-]*\z/', $type) !== 1) {
                $this->logBlockMiss($type ?? '(malformed)', 'malformed block instance');
                continue;
            }
            $template = "blocks/{$type}.twig";
            if (!$env->getLoader()->exists($template)) {
                $this->logBlockMiss($type, "no template at {$template}");
                $html[] = $this->debug
                    ? '<div style="border:1px dashed red;padding:.5rem">Missing block template: '
                        . htmlspecialchars($template, ENT_QUOTES) . '</div>'
                    : '<!-- lemma: no template for block "' . htmlspecialchars($type, ENT_QUOTES) . '" -->';
                continue;
            }
            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            $html[] = $env->render($template, [
                'block' => ['id' => $item['id'] ?? null, 'type' => $type, 'data' => $data],
                'data' => $data,
                'entry' => $entry,
                'index' => $index,
            ]);
        }
        return implode('', $html);
    }

    private function logBlockMiss(string $type, string $reason): void
    {
        if (isset($this->loggedBlockMisses[$type])) {
            return;
        }
        $this->loggedBlockMisses[$type] = true;
        $this->logger?->warning("lemma-render: blocks(): {$reason}", ['type' => $type]);
    }

    /**
     * Facet counts for templates (preview spec §5): returns ITEMS to Twig; the result's
     * cache_tags go into the render-scoped collector so the controller can merge them
     * into the page's Cache-Tag. No reader bound (or any gate failing) → [] — a
     * template never explodes over facets.
     *
     * @return list<array{uuid: string, slug: ?string, count: int}>
     */
    public function facets(string $type, string $field, int $limit = 100): array
    {
        if ($this->facetReader === null) {
            return [];
        }
        $result = $this->facetReader->counts($type, $field, $this->locale, $limit);
        $this->collectTags($result['cache_tags']);
        return $result['items'];
    }

    /** @param list<string> $tags */
    private function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->collectedTags[$tag] = $tag;
        }
    }

    /** Reset the render-scoped collector — the controller calls this BEFORE every render. */
    public function resetTags(): void
    {
        $this->collectedTags = [];
    }

    /**
     * Per-render asset-base override (preview-sessions spec §5): themed previews emit
     * /_preview-assets/{token}/… so theme B's markup never loads theme A's assets.
     * Same reset discipline as the tag collector — the controller nulls it BEFORE
     * every render, so a mid-render exception cannot leak preview URLs onward.
     */
    public function setAssetBase(?string $base): void
    {
        $this->assetBase = $base;
    }

    /** @return list<string> drained (and cleared) tags collected during the render */
    public function drainTags(): array
    {
        $tags = array_values($this->collectedTags);
        $this->collectedTags = [];
        return $tags;
    }

    /** @return list<array{label:string,url:string,entry:?string,children:list<mixed>}> */
    public function menu(string $slug): array
    {
        return $this->menus?->menu($slug, $this->locale) ?? [];
    }

    public function path(string $entryUuid): ?string
    {
        return $this->targets->resolve($entryUuid, $this->locale)['path'];
    }

    public function asset(string $rel): string
    {
        $bad = $rel === ''
            || str_starts_with($rel, '/')
            || str_contains($rel, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel) === 1
            || in_array('..', explode('/', $rel), true);
        if ($bad) {
            throw new RuntimeError(sprintf(
                'asset(): "%s" is not a safe theme-relative path (no absolute URLs, "..", leading "/" or "\\").',
                $rel,
            ));
        }
        return ($this->assetBase ?? '/theme-assets') . '/' . $rel;
    }
}
