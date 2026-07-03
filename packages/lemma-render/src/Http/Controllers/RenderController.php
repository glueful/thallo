<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response as ApiResponse;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;
use Glueful\Lemma\Contracts\Delivery\PreviewSession;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Glueful\Lemma\Render\HomepageConfigError;
use Glueful\Lemma\Render\Http\Middleware\PreviewSessionMiddleware;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\RenderErrorCache;
use Glueful\Lemma\Render\ReservedPaths;
use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;
use Glueful\Lemma\Render\Templates\RenderTemplateLoader;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function config;

/**
 * The render pipeline: reserved-path guard → PublicRouteResolver → template hierarchy →
 * HTML. Raw Symfony responses (never the JSON envelope) EXCEPT reserved paths, which get
 * the framework's standard JSON 404 (byte-compatible with a render-less install). Render
 * exceptions try error.twig once, then a plain-text 500 — never a render loop.
 */
final class RenderController
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicRouteResolver $resolver,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
        private readonly ReservedPaths $reserved,
        private readonly RenderErrorCache $errors,
        private readonly LoggerInterface $logger,
        private readonly ?FacetCountsReader $facetReader = null,
        private readonly ?PreviewSessionVerifier $sessionVerifier = null,
        private readonly ?TemplateRepository $templates = null,
        private readonly ?TemplateLinter $templateLinter = null,
    ) {
    }

    /**
     * Preview-only block annotation intent (visual-canvas spec §2): ASSIGNED at
     * the top of every rendering entry point (home/page: session-present;
     * preview(): always true) and applied to the extension in render()'s reset
     * block — assignment-not-set means the shared singleton never leaks
     * annotation across requests.
     */
    private bool $annotateBlocks = false;

    public function home(Request $request): Response
    {
        $session = $this->session($request);
        $this->annotateBlocks = $session !== null;
        $homepageEntry = (string) config($this->context, 'lemma_render.homepage_entry', '');
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');
        $entry = null;
        $typeSlug = '';

        if ($homepageEntry !== '') {
            $result = $this->resolver->resolveEntry($homepageEntry);
            if ($result['kind'] !== 'content') {
                return $this->homepageConfigFailure($homepageEntry);
            }
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
        }

        // Homepage ALWAYS renders index.twig (spec §4) — the entry, when configured,
        // arrives as context; routed pages use the entry hierarchy instead.
        [$env, $assetBase] = $this->themedEnv($session);
        $response = $this->render('index.twig', $locale, $entry, 200, $this->sessionExtra($session), $env, $assetBase);
        if ($entry !== null) {
            $this->tagResponse($response, $entry, $typeSlug);
        }
        return $session !== null ? $this->sessionChrome($response) : $response;
    }

    public function page(Request $request, string $path): Response
    {
        if ($this->reserved->isReserved($path)) {
            // Byte-compatible with the router's own 404 (shape + content type); API
            // clients under /v1 etc. never receive themed HTML.
            return ApiResponse::error('Not Found', 404);
        }

        $session = $this->session($request);
        $this->annotateBlocks = $session !== null;
        $extra = $this->sessionExtra($session);
        [$env, $assetBase] = $this->themedEnv($session);
        $result = $this->resolver->resolvePath('/' . ltrim($path, '/'), $session);

        $response = match ($result['kind']) {
            'redirect' => new Response('', $result['redirect']['status'], [
                'Location' => $result['redirect']['location'],
            ]),
            // In-session 404/410 render FRESH with the chrome (preview-sessions spec
            // §3): session surfaces never read or fill the SHARED fixed bodies.
            'gone' => $session !== null
                ? $this->render('error.twig', $this->defaultLocale(), null, 410, $extra, $env, $assetBase)
                : $this->errors->themed410(
                    fn (): Response => $this->render('error.twig', $this->defaultLocale(), null, 410),
                ),
            'content' => $this->renderEntry($result, $extra, $env, $assetBase),
            'listing', 'archive' => $this->renderCollection(
                $result,
                '/' . ltrim($path, '/'),
                $extra,
                $env,
                $assetBase,
            ),
            'terms' => $this->renderTerms($result, $extra, $env, $assetBase),
            default => $session !== null
                ? $this->render('404.twig', $this->defaultLocale(), null, 404, $extra, $env, $assetBase)
                : $this->errors->themed404(
                    fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
                ),
        };

        return $session !== null ? $this->sessionChrome($response) : $response;
    }

    /** The verified preview session from the detection middleware, if any. */
    private function session(Request $request): ?PreviewSession
    {
        $session = $request->attributes->get(PreviewSessionMiddleware::ATTRIBUTE);
        return $session instanceof PreviewSession ? $session : null;
    }

    /** @return array<string,mixed> banner context for in-session renders */
    private function sessionExtra(?PreviewSession $session): array
    {
        return $session === null
            ? []
            : ['preview' => true, 'preview_exit' => '/_preview/exit'];
    }

    /** Session/preview chrome (preview-sessions spec §3): no-store, noindex, no tags. */
    private function sessionChrome(Response $response): Response
    {
        $response->headers->remove('Cache-Tag');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        // Preview-session render, entry point TWO (visual-canvas spec §2 P1):
        // cookie-backed session pages get the bridge like the direct token route.
        return $this->withPreviewBridge($response);
    }

    /**
     * Request-local Twig for a themed session (preview-sessions spec §5) — NEVER
     * assigned to the memoized boot environment. Vanished/broken themes fall back to
     * the boot theme (the content exists; a themed-preview 404 would be wrong) and log.
     *
     * @return array{0: ?Environment, 1: ?string} [env, assetBase] — [null, null] = boot
     */
    private function themedEnv(?PreviewSession $session): array
    {
        if ($session === null || $session->theme === null) {
            return [null, null];
        }
        $base = $this->context->getBasePath();
        try {
            $locator = new ThemeLocator($session->theme, $base . '/themes');
            // DB overrides for the LOCATOR-RESOLVED theme (spec §3): a vanished theme
            // that fell back to `default` must not carry the vanished theme's overrides.
            $db = $this->templates !== null && $this->templateLinter !== null
                ? new DatabaseTemplateLoader(
                    $this->templates,
                    $this->templateLinter,
                    $locator->activePaths()['name'],
                )
                : null;
            $factory = new TwigFactory(
                $locator,
                $this->extension,
                $base . '/storage/cache/twig',
                $db,
            );
            return [$factory->environment(), '/_preview-assets/' . $session->token];
        } catch (\Throwable $e) {
            $this->logger->warning('lemma-render: preview theme unavailable, boot theme used', [
                'theme' => $session->theme,
                'error' => $e->getMessage(),
            ]);
            return [null, null];
        }
    }

    /**
     * Preview-through-theme (preview spec §2–§3): a content render with different
     * headers and context — kind 'content' + preview flag, never a separate kind. The
     * fixed-key RenderErrorCache is NOT consulted for failures (a preview 404 renders
     * fresh; it must never serve or fill the shared body). Responses are no-store +
     * noindex and carry no Cache-Tag.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Rendered draft preview (HTML, not an API endpoint)',
        // Tagged Default explicitly: the OpenAPI deny-list drops the render pack's
        // HTML routes by that tag, and the generator would otherwise path-derive an
        // unexcludable "' Preview'" tag from the /_preview segment.
        tags: ['Default'],
    )]
    public function preview(Request $request, string $token): Response
    {
        // Preview-session render, entry point ONE (visual-canvas spec §2 P1): this
        // route does NOT pass PreviewSessionMiddleware — the canvas iframe's first
        // load lands here, so annotation keys off controller knowledge, not the
        // middleware attribute.
        $this->annotateBlocks = true;
        // Verified up front: the session drives BOTH the cookie and the per-preview
        // theme (spec §5) — a themed token renders through a request-local environment.
        $session = $this->sessionVerifier?->verify($token);
        [$env, $assetBase] = $this->themedEnv($session);
        $result = $this->resolver->resolvePreview($token);

        if ($result['kind'] !== 'content') {
            $response = $this->render(
                '404.twig',
                $this->defaultLocale(),
                null,
                404,
                ['preview' => true],
                $env,
                $assetBase,
            );
        } else {
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
            $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';
            $template = $candidate !== '' && ($env ?? $this->twig())->getLoader()->exists($candidate)
                ? $candidate
                : 'entry.twig';
            $response = $this->render($template, $locale, $entry, 200, ['preview' => true], $env, $assetBase);
        }

        $response->headers->remove('Cache-Tag'); // no-store pages carry no surrogate tags
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        // Start the preview SESSION (preview-sessions spec §1) — only on a VERIFIED
        // token: the cookie is the token itself, dies with its TTL, Secure iff HTTPS.
        if ($session !== null && $result['kind'] === 'content') {
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie(
                'lemma_preview',
                $token,
                $session->expiresAt,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX,
            ));
        }
        return $this->withPreviewBridge($response);
    }

    /**
     * Preview support stylesheet (visual-canvas spec §3): the layout-inert
     * annotation wrapper rule + canvas ring styles. Token-free static content.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview support stylesheet (not an API endpoint)',
        tags: ['Default'],
    )]
    public function previewCss(): Response
    {
        return $this->previewSupportAsset('preview.css', 'text/css; charset=UTF-8');
    }

    /**
     * The canvas bridge script (visual-canvas spec §3): silent until a
     * nonce-carrying canvas-hello; token-free static content.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview canvas bridge script (not an API endpoint)',
        tags: ['Default'],
    )]
    public function previewBridgeJs(): Response
    {
        return $this->previewSupportAsset('preview-bridge.js', 'application/javascript; charset=UTF-8');
    }

    private function previewSupportAsset(string $file, string $contentType): Response
    {
        $path = dirname(__DIR__, 3) . '/assets/preview/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Inject the canvas bridge into preview HTML (visual-canvas spec §3): applied
     * to BOTH preview response paths — the direct token entrypoint and cookie
     * sessions. Non-HTML responses (redirects, assets) pass through untouched;
     * missing </body> appends at end-of-document — NEVER fails the render.
     */
    private function withPreviewBridge(Response $response): Response
    {
        $type = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($type, 'text/html')) {
            return $response;
        }
        // Version by mtime: the assets are served with max-age=86400, so without
        // a cache-busting query every bridge/CSS change ships a DAY late to any
        // browser that already previewed. Query strings don't affect routing.
        $assetDir = dirname(__DIR__, 3) . '/assets/preview/';
        $cssV = (int) @filemtime($assetDir . 'preview.css');
        $jsV = (int) @filemtime($assetDir . 'preview-bridge.js');
        $inject = '<link rel="stylesheet" href="/_preview.css?v=' . $cssV . '">'
            . '<script src="/_preview-bridge.js?v=' . $jsV . '" defer></script>';
        $html = (string) $response->getContent();
        $response->setContent(
            str_contains($html, '</body>')
                ? (string) preg_replace('#</body>#', $inject . '</body>', $html, 1)
                : $html . $inject
        );
        return $response;
    }

    /** Ends the preview session (preview-sessions spec §1): clear the cookie, go home. */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'End the preview session (HTML redirect, not an API endpoint)',
        // Tagged Default explicitly (like preview/previewAsset): the OpenAPI deny-list
        // drops the render pack's HTML routes by that tag; the generator would
        // otherwise path-derive an unexcludable tag from the /_preview segment.
        tags: ['Default'],
    )]
    public function exit(): Response
    {
        $response = new Response('', 302, ['Location' => '/']);
        $response->headers->clearCookie('lemma_preview', '/');
        return $response;
    }

    /**
     * Token-scoped preview assets (preview-sessions spec §5): the token's SIGNED theme
     * is the only theme served; asset() path rules apply; no-store. Tagged Default so
     * the OpenAPI deny-list drops it like the other HTML-surface routes.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview theme assets (not an API endpoint)',
        tags: ['Default'],
    )]
    public function previewAsset(Request $request, string $token, string $path): Response
    {
        $session = $this->sessionVerifier?->verify($token);
        if ($session === null || $session->theme === null) {
            return ApiResponse::error('Not Found', 404);
        }
        $bad = $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1
            || in_array('..', explode('/', $path), true);
        if ($bad) {
            return ApiResponse::error('Not Found', 404);
        }
        try {
            $assets = (new ThemeLocator($session->theme, $this->context->getBasePath() . '/themes'))
                ->activePaths()['assets'];
        } catch (\Throwable) {
            return ApiResponse::error('Not Found', 404);
        }
        $file = $assets . '/' . $path;
        if (!is_file($file)) {
            return ApiResponse::error('Not Found', 404);
        }
        $response = new BinaryFileResponse($file);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * @param array{locale: ?string, type: ?string, content: ?array} $result may also
     *        carry `cache_tags` (expansion-target surrogate tags) — merged into the
     *        response header, never into template context
     * @param array<string,mixed> $extra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderEntry(
        array $result,
        array $extra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        $entry = $result['content'];
        $locale = (string) $result['locale'];
        // Template hierarchy: entry/{type-slug}.twig → entry.twig (the resolver's `type`
        // field carries the content-type slug for exactly this selection).
        $typeSlug = (string) ($result['type'] ?? '');
        $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';

        $template = $candidate !== '' && ($env ?? $this->twig())->getLoader()->exists($candidate)
            ? $candidate
            : 'entry.twig';
        $response = $this->render($template, $locale, $entry, 200, $extra, $env, $assetBase);
        $this->tagResponse($response, $entry ?? [], $typeSlug);
        $this->mergeCacheTags($response, array_values(array_map('strval', (array) ($result['cache_tags'] ?? []))));
        return $response;
    }

    /**
     * Listing/archive pages (listing spec §4). Template family follows the kind
     * (listing/{type}.twig → listing.twig; archive/{type}.twig → archive.twig); the
     * context ships ready pagination paths so themes never build page URLs; the
     * Cache-Tag ALWAYS carries the broad lemma:type:{type} — page contents change when
     * one new entry publishes, so per-item tags alone cannot keep cached pages fresh.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $sessionExtra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderCollection(
        array $result,
        string $path,
        array $sessionExtra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        $family = $result['kind'] === 'archive' ? 'archive' : 'listing';
        $typeSlug = (string) $result['type'];
        $locale = (string) $result['locale'];
        /** @var array<string,mixed> $listing */
        $listing = $result['listing'];

        $candidate = "{$family}/{$typeSlug}.twig";
        $template = ($env ?? $this->twig())->getLoader()->exists($candidate) ? $candidate : "{$family}.twig";

        $page = (int) $listing['page'];
        $totalPages = (int) $listing['total_pages'];
        // The base path strips a trailing /page/{n}; page 2's prev is the BARE base
        // (canonical — /page/1 301s).
        $base = $page > 1 ? (string) preg_replace('#/page/\d+$#', '', $path) : $path;
        $pagination = [
            'page' => $page,
            'per_page' => (int) $listing['per_page'],
            'total' => (int) $listing['total'],
            'total_pages' => $totalPages,
            'prev_path' => $page <= 1 ? null : ($page === 2 ? $base : $base . '/page/' . ($page - 1)),
            'next_path' => $page < $totalPages ? $base . '/page/' . ($page + 1) : null,
        ];

        $extra = $sessionExtra + [
            'items' => $listing['items'],
            'pagination' => $pagination,
            'type' => $typeSlug,
        ];
        if ($result['kind'] === 'archive') {
            $extra['term'] = $result['term'];
            $extra['field'] = $result['field'];
        }

        $response = $this->render($template, $locale, null, 200, $extra, $env, $assetBase);
        $this->tagCollection($response, $result);
        $this->mergeCacheTags($response, array_values(array_map('strval', (array) ($result['cache_tags'] ?? []))));
        return $response;
    }

    /**
     * Surrogate tags for a collection page: per-item entry tags + the BROAD type tag
     * (the correctness mechanism — see renderCollection); archives add the term's entry
     * tag and its type's tag so term edits and term-type events purge too.
     *
     * @param array<string,mixed> $result
     */
    private function tagCollection(Response $response, array $result): void
    {
        $typeSlug = (string) $result['type'];
        $tags = [];
        foreach ((array) ($result['listing']['items'] ?? []) as $item) {
            $uuid = is_string($item['uuid'] ?? null) ? $item['uuid'] : '';
            if ($uuid !== '') {
                $tags[] = 'lemma:entry:' . $uuid;
            }
        }
        $termUuid = is_string($result['term']['uuid'] ?? null) ? $result['term']['uuid'] : '';
        if ($termUuid !== '') {
            $tags[] = 'lemma:entry:' . $termUuid;
        }
        $tags[] = 'lemma:type:' . $typeSlug;
        $termType = is_string($result['term_type'] ?? null) ? $result['term_type'] : '';
        if ($termType !== '' && $termType !== $typeSlug) {
            $tags[] = 'lemma:type:' . $termType;
        }
        $this->mergeCacheTags($response, array_values(array_unique($tags)));
    }

    /**
     * Stamp the surrogate Cache-Tag header (same strings the delivery API emits and
     * InvalidateCacheTagsListener invalidates) so the page cache and the CDN can both
     * purge this page on entry/type events.
     *
     * @param array<string,mixed> $entry
     */
    private function tagResponse(Response $response, array $entry, string $typeSlug): void
    {
        $uuid = is_string($entry['uuid'] ?? null) ? $entry['uuid'] : '';
        if ($uuid === '' || $typeSlug === '') {
            return;
        }
        $this->mergeCacheTags($response, ["lemma:entry:{$uuid}", "lemma:type:{$typeSlug}"]);
    }

    /**
     * @param array<string,mixed>|null $entry
     * @param array<string,mixed> $extra additional template context (listing/archive pages)
     * @param Environment|null $twig request-local themed environment (preview sessions);
     *                               null = the memoized boot-theme environment
     * @param string|null $assetBase per-render asset() base (/_preview-assets/{token});
     *                               null = /theme-assets
     */
    private function render(
        string $template,
        string $locale,
        ?array $entry,
        int $status,
        array $extra = [],
        ?Environment $twig = null,
        ?string $assetBase = null,
    ): Response {
        // Reset the render-scoped state BEFORE every render (preview + DB-template
        // specs): the extension instance — and the boot environment's loader — are
        // process-shared; reset-before-render is what stops a failed render's
        // collected tags, a themed preview's asset base, or a stale template override
        // map leaking into the next response.
        $env = $twig ?? $this->twig();
        $loader = $env->getLoader();
        if ($loader instanceof RenderTemplateLoader) {
            $loader->resetForRender(); // reload the override map once per render (spec §3)
        }
        $this->extension->resetTags();
        $this->extension->setAssetBase($assetBase);
        $this->extension->resetBlockDepth();
        $this->extension->resetBlockFrames();
        // Controller-scoped intent, applied per render: every entry point ASSIGNS
        // $annotateBlocks (true only for preview renders), so the shared singleton
        // can never leak annotation into a live response.
        $this->extension->setBlockAnnotations($this->annotateBlocks);
        $this->extension->setLocale($locale);
        $context = [
            'site' => [
                'name' => (string) config($this->context, 'lemma_render.site_name', 'Lemma'),
                'locale' => $locale,
                'locales' => [],
            ],
        ];
        if ($entry !== null) {
            $context['entry'] = $entry;
        }
        $context += $extra;

        try {
            $html = $env->render($template, $context);
        } catch (\Throwable $e) {
            $this->logger->error('lemma-render: template render failed', [
                'template' => $template,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            if ($template === 'error.twig') {
                return new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            return $this->render('error.twig', $locale, null, 500, [], $twig, $assetBase);
        }

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        // Drain on SUCCESS only: tags collected by facets() during this render join the
        // page's Cache-Tag, so facet sidebars purge event-driven like everything else.
        $this->mergeCacheTags($response, $this->extension->drainTags());
        return $response;
    }

    /**
     * Term index pages (term-index spec §2–§3): the resolver classified the path
     * (thin kind); THIS side fetches counts and dispatches on the FacetCountsReader
     * invariant — empty cache_tags means a gate failed (unknown/non-filterable field,
     * non-visible type) and a VALID facet always carries tags, even with zero items.
     * hrefs are built HERE (the reader stays counts + tags): the term's archive path
     * with the same default-locale collapse the other rendered hrefs use.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $sessionExtra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderTerms(
        array $result,
        array $sessionExtra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        // In-session gate failures render FRESH (spec §3) — never the shared fixed body.
        $notFound = $sessionExtra !== []
            ? fn (): Response => $this->render(
                '404.twig',
                $this->defaultLocale(),
                null,
                404,
                $sessionExtra,
                $env,
                $assetBase,
            )
            : fn (): Response => $this->errors->themed404(
                fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
            );
        if ($this->facetReader === null) {
            return $notFound();
        }
        $typeSlug = (string) $result['type'];
        $field = (string) $result['field'];
        $locale = (string) $result['locale'];

        $counts = $this->facetReader->counts($typeSlug, $field, $locale, 500);
        if ($counts['cache_tags'] === []) {
            return $notFound(); // the pinned invariant: empty tags ⇔ gate failure
        }

        $prefix = $locale === $this->defaultLocale() ? '' : '/' . rawurlencode($locale);
        $terms = [];
        foreach ($counts['items'] as $item) {
            $slug = $item['slug'];
            $item['href'] = $slug === null
                ? null
                : $prefix . '/' . rawurlencode($typeSlug) . '/' . rawurlencode($field)
                    . '/' . rawurlencode($slug);
            $terms[] = $item;
        }

        $candidate = "terms/{$typeSlug}.twig";
        $template = ($env ?? $this->twig())->getLoader()->exists($candidate) ? $candidate : 'terms.twig';
        $response = $this->render($template, $locale, null, 200, $sessionExtra + [
            'terms' => $terms,
            'type' => $typeSlug,
            'field' => $field,
        ], $env, $assetBase);
        $this->mergeCacheTags($response, $counts['cache_tags']);
        return $response;
    }

    /**
     * Append-unique Cache-Tag merge: drained facet tags (set at render time) and the
     * caller-side taggers (tagResponse/tagCollection) must compose, so nobody may
     * blind-set the header.
     *
     * @param list<string> $tags
     */
    private function mergeCacheTags(Response $response, array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $existing = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->headers->get('Cache-Tag', '')),
        )));
        $response->headers->set(
            'Cache-Tag',
            implode(', ', array_values(array_unique([...$existing, ...$tags]))),
        );
    }

    private function homepageConfigFailure(string $configured): Response
    {
        $error = new HomepageConfigError(
            "lemma_render.homepage_entry (\"{$configured}\") does not resolve to published, routed content.",
        );
        // Always logged; the message reaches the BODY only in debug mode (never leak in prod).
        $this->logger->error('lemma-render: ' . $error->getMessage());
        $debug = (bool) config($this->context, 'app.debug', false);
        return new Response(
            $debug ? $error->getMessage() : 'Internal Server Error',
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function twig(): Environment
    {
        return $this->twig ??= $this->twigFactory->environment();
    }

    private function defaultLocale(): string
    {
        return (string) config($this->context, 'i18n.default_locale', 'en');
    }
}
