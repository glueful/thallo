<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Controllers;

use Glueful\Events\EventService;
use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;
use Glueful\Lemma\Render\Http\TemplateSaveBody;
use Glueful\Lemma\Render\Templates\TemplateCatalog;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\Templates\TemplateUpdated;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * DB-edited templates admin API (spec §5–§6). Triple-gated at the route layer
 * (capability → auth → lemma_permission:templates.manage). Save = live: syntactic path
 * check → theme check (RenderThemeValidator via the PreviewThemeValidator binding) →
 * policy lint (422 with ALL line-numbered violations) → transactional save →
 * TemplateUpdated (also on delete/restore — every mutation that changes what renders).
 */
final class TemplatesAdminController
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateLinter $linter,
        private readonly TemplateCatalog $catalog,
        private readonly PreviewThemeValidator $themeValidator,
        private readonly ThemeLocator $activeTheme,
        private readonly EventService $events,
    ) {
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'List resolvable templates (filesystem + DB) for a theme', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Merged listing with per-path origin (db|theme|default).')]
    public function index(Request $request): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        return Response::success(['theme' => $theme, 'templates' => $this->catalog->list($theme)]);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'Current template source (DB override or filesystem)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Source + origin; filesystem sources are the copy-from-disk start.')]
    #[ApiResponse(404, description: 'Unknown theme, invalid path, or nothing at this path.')]
    public function show(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $db = $this->templates->findCurrentSource($theme, $path);
        if ($db !== null) {
            return Response::success([
                'path' => $path,
                'theme' => $theme,
                'origin' => 'db',
                'source' => $db['source'],
                'version_uuid' => $db['version_uuid'],
            ]);
        }
        $file = $this->catalog->readFile($theme, $path);
        if ($file === null) {
            return Response::error('Not Found', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'origin' => $file['origin'],
            'source' => $file['source'],
            'version_uuid' => null,
        ]);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Save a template override (create or update; DB-only paths allowed)',
        tags: ['Lemma Templates'],
    )]
    #[\Glueful\Routing\Attributes\ApiRequestBody(
        schema: TemplateSaveBody::class,
        description: 'The full Twig source to save as the new current version.',
    )]
    #[ApiResponse(200, description: 'Saved and live; response carries the new version uuid.')]
    #[ApiResponse(404, description: 'Unknown theme.')]
    #[ApiResponse(422, description: 'Invalid path/source, or policy violations ({line, message} list).')]
    public function save(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        if ($this->invalidPath($path)) {
            return Response::error(
                'Invalid template path (slash-separated [A-Za-z0-9._-] segments, ending .twig).',
                422,
            );
        }
        $body = (array) json_decode((string) $request->getContent(), true);
        $source = $body['source'] ?? null;
        if (!is_string($source)) {
            return Response::error('source must be a string.', 422);
        }
        $violations = $this->linter->lint($source, $path);
        if ($violations !== []) {
            return new Response([
                'success' => false,
                'message' => 'Template policy violations.',
                'errors' => $violations,
            ], 422);
        }
        $ids = $this->templates->save($theme, $path, $source, $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Template saved.');
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Delete the override (deactivate — history preserved), fall back to filesystem',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Override deactivated.')]
    #[ApiResponse(404, description: 'No active override at this path.')]
    public function delete(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        if (!$this->templates->deactivate($theme, $path)) {
            return Response::error('No active override at this path.', 404);
        }
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success(['path' => $path, 'theme' => $theme], 'Override removed.');
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'Version history (newest first; survives delete)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Versions: {uuid, created_by, created_at, current}.')]
    #[ApiResponse(404, description: 'This path has never been saved.')]
    public function versions(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        if ($this->templates->find($theme, $path) === null) {
            return Response::error('This path has no saved versions.', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'versions' => $this->templates->versions($theme, $path),
        ]);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'One version\'s source', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'The immutable stored source.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    public function showVersion(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        return Response::success(['path' => $path, 'theme' => $theme] + $version);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Restore a version (append-as-new-current; reactivates a deleted override)',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Restored; response carries the NEW version uuid.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    #[ApiResponse(422, description: 'The stored version violates the CURRENT policy ({line, message} list).')]
    public function restore(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        // Re-lint against TODAY'S policy (spec §4): old versions can predate a policy
        // tightening or have been mutated around the API — restore must not put a
        // template live that a fresh save would reject. Same 422 payload as save().
        $violations = $this->linter->lint($version['source'], $path);
        if ($violations !== []) {
            return new Response([
                'success' => false,
                'message' => 'Template policy violations.',
                'errors' => $violations,
            ], 422);
        }
        $ids = $this->templates->save($theme, $path, $version['source'], $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Version restored.');
    }

    /** @return string|null resolved theme; null = invalid (caller 404s) */
    private function theme(Request $request): ?string
    {
        $theme = (string) $request->query->get('theme', '');
        if ($theme === '') {
            return $this->activeTheme->activePaths()['name'];
        }
        return $this->themeValidator->isValidTheme($theme) ? $theme : null;
    }

    /**
     * Syntactic only (spec §5): slash-separated segments, each [A-Za-z0-9._-]+ and
     * not "."/"..", ending .twig. Conservative and URL-SAFE by construction — the
     * admin client injects {path} into URLs RAW (slash-preserving), so ?, #, spaces,
     * and every other URL-significant character must be unrepresentable, not merely
     * discouraged. The charset also excludes \, :, and scheme syntax. Empty segments
     * cover leading slashes and "//". DB-only paths are FINE.
     */
    private function invalidPath(string $path): bool
    {
        if ($path === '' || !str_ends_with($path, '.twig')) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1
            ) {
                return true;
            }
        }
        return false;
    }

    private function userUuid(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user', []);
        return isset($user['uuid']) && is_string($user['uuid']) ? $user['uuid'] : null;
    }
}
