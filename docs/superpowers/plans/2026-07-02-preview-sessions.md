# Preview Sessions (Preview v2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Being in preview" outlives the token URL — `/_preview/{token}` starts a signed-cookie session with full-site navigation in preview chrome, the tokened draft overlaid on its canonical URL, and an optional token-signed theme override with correctly-scoped assets.

**Architecture:** The token IS the session credential (cookie value, re-verified per request — no new crypto, no server state). A contracts `PreviewSession` VO + `PreviewSessionVerifier` verify ONCE in a new `PreviewSessionMiddleware` (before `RenderPageCache`, independent of cache settings) into a request attribute shared by the cache bypass, the controller chrome, the single-draft overlay (`resolvePath(path, ?PreviewSession)` + `PreviewReader::readVerified`), and the theme machinery (request-local Twig environments, never the memo; token-scoped `/_preview-assets/{token}/…` with reset-before-render asset-base semantics). Theme validation stays render-owned via the `PreviewThemeValidator` contract.

**Tech Stack:** PHP 8.3 (contracts + core + lemma-render), Twig, Symfony HttpFoundation cookies/BinaryFileResponse, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-02-preview-sessions-design.md`

## Global Constraints

- **Commit only when authorized.** Two STAGE groupings below; stop at each. No attribution trailers. Never stage CLAUDE.md.
- Cookie: `lemma_preview={token}` — `HttpOnly`, `SameSite=Lax`, `Path=/`, **`Secure` iff `$request->isSecure()`**, `Max-Age` = remaining TTL. Set ONLY on a successfully verified `/_preview/{token}`; session dies with the token (no sliding).
- **Session state is NOT cache state**: `PreviewSessionMiddleware` (render pack) verifies the cookie via the contract and sets request attribute **`lemma_preview_session`** (the VO); junk/expired cookies leave it absent. `RenderPageCache` only checks the attribute (present → passthrough, no read no store). Sessions must survive `cache_enabled=false`.
- Verifier returns the **`PreviewSession` VO** `{token, entry, locale, version, theme, expiresAt}` — null on ANY failure; consumers never re-verify. `resolvePath(string $path, ?PreviewSession $previewSession = null)` swaps in the DRAFT only when the resolved entry matches `{entry, locale}` (via `PreviewReader::readVerified` — no re-verification; `read()` stays for the JSON door).
- Single-draft overlay: every other page shows PUBLISHED content in chrome (banner + Exit link, `no-store`, `noindex`, `Cache-Tag` stripped). In-session 404/410 render FRESH — `RenderErrorCache` is neither read nor filled by session responses.
- Theme: mint accepts optional `theme`, **validated via the `PreviewThemeValidator` contract ONLY if bound** (supplied theme + no validator → 422; unknown theme → 422); signed into the token as an ADDITIVE claim (`t`) — old tokens verify. Themed sessions render through a **request-local Twig environment, never assigned to the memoized `$this->twig`**; vanished themes fall back to the boot theme (logged). `asset()` emits `/_preview-assets/{token}/…` via `setAssetBase(?string)` with **reset-to-null before EVERY render** (the tag-collector discipline — exception-leak tested).
- `/_preview/exit` (before `{token}`) clears the cookie → 302 `/`. `/_preview-assets/{token}/{path}` verifies the token, reads the SIGNED theme, applies `asset()`'s path rules, serves only that theme's `assets/`, `no-store`, tagged `Default` (OpenAPI deny-list covers it).
- phpcs via real exit code; boundaries; suite env `RENDER_LISTING_TYPES=blog,post`.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `packages/lemma-contracts/src/Delivery/PreviewSession.php` | Create | the verified-claims VO (+ token) |
| `packages/lemma-contracts/src/Delivery/PreviewSessionVerifier.php` | Create | signature+expiry seam |
| `packages/lemma-contracts/src/Delivery/PreviewThemeValidator.php` | Create | render-owned theme validation seam |
| `app/Content/Preview/PreviewToken.php` | Modify | additive `t` (theme) claim |
| `app/Content/Preview/PreviewMinter.php` | Modify | `mint(..., ?string $theme = null)` |
| `app/Content/Preview/PreviewReader.php` | Modify | `readVerified(PreviewSession)` |
| `app/Content/Preview/EnginePreviewSessionVerifier.php` | Create | core verifier impl |
| `app/Content/Http/DTOs/MintPreviewData.php` | Modify | optional `theme` |
| `app/Content/Http/Controllers/PreviewController.php` | Modify | theme validation + signing; factory-registered |
| `app/Providers/LemmaServiceProvider.php` | Modify | verifier binding; PreviewController factory |
| `packages/lemma-render/src/Http/Middleware/PreviewSessionMiddleware.php` | Create | cookie → request attribute |
| `packages/lemma-render/src/RenderThemeValidator.php` | Create | PreviewThemeValidator impl (ladder semantics) |
| `packages/lemma-render/src/Http/Middleware/RenderPageCache.php` | Modify | attribute passthrough |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` | Modify | Set-Cookie, chrome, overlay, theme env, assets action, exit |
| `packages/lemma-render/src/RenderContextExtension.php` | Modify | `setAssetBase(?string)` + asset() override |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` | Modify | new services/factories/wiring |
| `packages/lemma-render/routes/public-routes.php` | Modify | exit/assets routes; session middleware on render routes |
| `packages/lemma-render/themes/default/templates/layout.twig` | Modify | banner Exit link |
| `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` + `app/Content/Delivery/EnginePublicRouteResolver.php` | Modify | `resolvePath(path, ?PreviewSession)` overlay |
| `tests/Integration/Render/PreviewSessionTest.php` | Create | the whole surface |
| README/CHANGELOG/V2/NEXT | Modify | docs + tracker flips |

Codebase facts:
- `PreviewToken` wire format: `b64url(json{e,l,v,exp}).b64url(hmac)`; verify checks signature FIRST, tolerates absent optional claims — add `'t' => $theme` at mint and `isset($data['t'])` at verify (old tokens: claim absent → null). `ResolvesPreviewKey` derives the key.
- `TwigFactory(ThemeLocator, RenderContextExtension, string $cacheDir)` derives the compile-cache subdir from the locator's RESOLVED name — request-local `new TwigFactory(new ThemeLocator($theme, $basePath . '/themes'), $this->extension, $basePath . '/storage/cache/twig')` is safe and isolated. `ThemeLocator` throws `ThemeConfigError` on invalid theme.json and falls back to the pack default when the app dir is missing (the vanished-theme fallback rides this, wrapped in try/catch → boot theme + log).
- `RenderController::render()` already resets the tag collector first — the asset-base reset joins that line. `render()` gains `?Environment $twig = null` and `?string $assetBase = null` params (private method; the memo `$this->twig` is used only when `$twig` is null and is NEVER written from session paths).
- `PreviewController` moves from `'autowire' => true` to a factory so the nullable `?PreviewThemeValidator` is passed via `$container->has(...)` (direct construction with null tests the unbound-422 path).
- Routes: middleware order = declaration order; render routes get `->middleware([PreviewSessionMiddleware::class, RenderPageCache::class])` (session detection first). Static `_preview-assets`/`_preview` first segments beat the catch-all.
- Cookie assertions in kernel tests: `$response->headers->getCookies()` (Symfony); requests carry cookies via `Request::create($uri, 'GET', [], ['lemma_preview' => $token])`.
- Suite themes dir: `getBasePath() . '/themes'` — themed tests create `themes/altprev/{theme.json,templates/...}` in setUp and remove in tearDown (recursive rm).
- Test seeds: copy `PreviewThemeTest`'s `seedDraftEntry` + `SeedsPublishedContent` (blog `/blog/hello` published; drafts via `EntryRepository`).

---

### Task 1: Token theme claim + VO + verifier + readVerified + mint validation

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/PreviewSession.php`, `packages/lemma-contracts/src/Delivery/PreviewSessionVerifier.php`, `packages/lemma-contracts/src/Delivery/PreviewThemeValidator.php`, `app/Content/Preview/EnginePreviewSessionVerifier.php`, `packages/lemma-render/src/RenderThemeValidator.php`
- Modify: `app/Content/Preview/PreviewToken.php`, `app/Content/Preview/PreviewMinter.php`, `app/Content/Preview/PreviewReader.php`, `app/Content/Http/DTOs/MintPreviewData.php`, `app/Content/Http/Controllers/PreviewController.php`, `app/Providers/LemmaServiceProvider.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Test: `tests/Integration/Render/PreviewSessionTest.php` (created here)

**Interfaces:**
- Produces: `PreviewSession` VO (`__construct(string $token, string $entry, string $locale, ?string $version, ?string $theme, int $expiresAt)` — all promoted readonly); `PreviewSessionVerifier::verify(string $token): ?PreviewSession`; `PreviewThemeValidator::isValidTheme(string $name): bool`; `PreviewToken` gains `public readonly ?string $theme` + `mint(..., ?string $theme = null)`; `PreviewMinter::mint(string $entry, string $locale, ?string $version = null, ?string $theme = null)`; `PreviewReader::readVerified(PreviewSession $session): array` (same shape as `read()`); mint endpoint: optional `theme` body field, 422 on unknown-or-unvalidatable.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/PreviewSessionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\DTOs\MintPreviewData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Symfony\Component\HttpFoundation\Request;

/**
 * Preview sessions (preview-sessions spec §1–§7): the token-as-cookie session, the
 * verifier VO, the single-draft overlay, session chrome, the cache-bust guard, and
 * per-preview themes with token-scoped assets.
 */
final class PreviewSessionTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $alt = $this->appContext()->getBasePath() . '/themes/altprev';
        if (is_dir($alt)) {
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($alt, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $f
            ) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($alt);
        }
        parent::tearDown();
    }

    private function verifier(): PreviewSessionVerifier
    {
        return $this->container()->get(PreviewSessionVerifier::class);
    }

    /** Seed a blog entry with a DRAFT (never published); returns its uuid. */
    private function seedDraftEntry(string $title = 'Draft words'): string
    {
        $types = new ContentTypeRepository($this->connection());
        if ($types->findBySlug('blog') === null) {
            $this->seedBilingualPublishedEntry();
        }
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }

    // ---- Task 1: verifier + token theme claim + mint validation ----------------------

    public function testVerifierReturnsSessionVoAndFailsClosed(): void
    {
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $s = $this->verifier()->verify($token);
        self::assertNotNull($s);
        self::assertSame($token, $s->token);   // the VO carries the ORIGINAL token
        self::assertSame($entry, $s->entry);
        self::assertSame('en', $s->locale);
        self::assertNull($s->theme);           // old-format/no-theme claim
        self::assertGreaterThan(time(), $s->expiresAt);

        self::assertNull($this->verifier()->verify('garbage'));
        self::assertNull($this->verifier()->verify($token . 'x')); // broken signature
    }

    public function testThemeClaimRoundTripsAndReadVerifiedSkipsReverification(): void
    {
        $entry = $this->seedDraftEntry('Themed draft');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        $s = $this->verifier()->verify($token);
        self::assertNotNull($s);
        self::assertSame('altprev', $s->theme);

        $read = $this->container()->get(PreviewReader::class)->readVerified($s);
        self::assertSame($entry, $read['entry_uuid']);
        self::assertSame('Themed draft', $read['fields']['title']);
    }

    public function testMintValidatesThemeThroughTheContract(): void
    {
        $entry = $this->seedDraftEntry();
        $this->makeAltTheme();

        // Bound validator (render pack) + real theme → 200 with theme signed in.
        $ok = $this->mintDirect($entry, 'altprev', boundValidator: true);
        self::assertSame(200, $ok->getStatusCode());
        $token = (string) json_decode((string) $ok->getContent(), true)['data']['token'];
        self::assertSame('altprev', $this->verifier()->verify($token)?->theme);

        // Unknown theme → 422.
        self::assertSame(422, $this->mintDirect($entry, 'nope', boundValidator: true)->getStatusCode());
        // Theme supplied but NO validator bound (render pack absent) → 422 (removability).
        self::assertSame(422, $this->mintDirect($entry, 'altprev', boundValidator: false)->getStatusCode());
        // No theme at all → 200 regardless of the validator.
        self::assertSame(200, $this->mintDirect($entry, null, boundValidator: false)->getStatusCode());
    }

    /** Direct construction so the validator can be present or absent per case. */
    private function mintDirect(string $entry, ?string $theme, bool $boundValidator): \Glueful\Http\Response
    {
        $validator = $boundValidator
            ? $this->container()->get(\Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator::class)
            : null;
        $controller = new PreviewController(
            $this->container()->get(PreviewMinter::class),
            $this->container()->get(PreviewReader::class),
            new ContentLocaleService($this->appContext(), new FakeLocaleManager()),
            $this->appContext(),
            $validator,
        );
        return $controller->mint(
            new MintPreviewData(theme: $theme),
            Request::create('/'),
            $entry,
            'en',
        );
    }

    /** A real alt theme in the app themes dir (removed in tearDown). */
    private function makeAltTheme(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/altprev';
        @mkdir($base . '/templates', 0755, true);
        @mkdir($base . '/assets', 0755, true);
        file_put_contents($base . '/theme.json', json_encode(['name' => 'altprev']));
        file_put_contents(
            $base . '/templates/entry.twig',
            "{% extends 'layout.twig' %}{% block content %}ALTPREV:{{ entry.fields.title }}{% endblock %}",
        );
        file_put_contents($base . '/assets/alt.css', '/* alt theme css */');
    }
}
```

(`MintPreviewData(theme: ...)` implies the DTO gains the field — Step 3. If its
constructor param order differs, use named args as shown.)

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php
```

Expected: ERRORS — `PreviewSessionVerifier` unknown, `mint()` has no theme param.

- [ ] **Step 3: Implement the contracts + core pieces**

`packages/lemma-contracts/src/Delivery/PreviewSession.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Verified preview-session claims + the ORIGINAL token (the render layer builds
 * /_preview-assets/{token}/… URLs from it). Produced only by PreviewSessionVerifier —
 * holding an instance MEANS the signature and expiry were checked. Immutable.
 */
final class PreviewSession
{
    public function __construct(
        public readonly string $token,
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $version,
        public readonly ?string $theme,
        public readonly int $expiresAt,
    ) {
    }
}
```

`packages/lemma-contracts/src/Delivery/PreviewSessionVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Cheap signature + expiry verification for preview tokens (no DB). Null on ANY
 * failure — malformed, bad signature, expired. Session detection middleware, the
 * render controller, and the preview-asset route share ONE verification per request
 * through the returned VO; route semantics never enter the cache layer.
 */
interface PreviewSessionVerifier
{
    public function verify(string $token): ?PreviewSession;
}
```

`packages/lemma-contracts/src/Delivery/PreviewThemeValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Theme-name validation for per-preview themes (preview-sessions spec §5). Implemented
 * by the render pack with the REAL theme-ladder semantics; core's mint endpoint
 * consults it only if bound — a supplied theme with no validator bound is invalid
 * (the render pack is absent, so no theme could ever render).
 */
interface PreviewThemeValidator
{
    public function isValidTheme(string $name): bool;
}
```

`PreviewToken`: add `public readonly ?string $theme` to the constructor (last), add
`'t' => $theme` to the mint payload and a `?string $theme = null` param (after `$key`
would break call sites — put it BEFORE `$key`? No: keep BC by appending after `$key`:
`mint(string $entryUuid, string $locale, ?string $versionUuid, int $expiresAt, string $key, ?string $theme = null)`),
and in `verify()` construct with
`isset($data['t']) && is_string($data['t']) ? $data['t'] : null` (additive — old
tokens lack `t`).

`PreviewMinter::mint` gains `?string $theme = null` (append) and threads it through.

`app/Content/Preview/EnginePreviewSessionVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Lemma\Contracts\Delivery\PreviewSession;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;

/**
 * Verifier over PreviewToken::verify with the shared key derivation — the cheap
 * (no-DB) check the session middleware and asset route run per request. Null on any
 * token problem; it NEVER throws (fail-quiet is correct here: an invalid cookie just
 * means "no session", unlike the JSON door's explicit 403/410 mapping).
 */
final class EnginePreviewSessionVerifier implements PreviewSessionVerifier
{
    use ResolvesPreviewKey;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function verify(string $token): ?PreviewSession
    {
        try {
            $payload = PreviewToken::verify($token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException) {
            return null;
        }
        return new PreviewSession(
            $token,
            $payload->entryUuid,
            $payload->locale,
            $payload->versionUuid,
            $payload->theme,
            $payload->expiresAt,
        );
    }
}
```

`PreviewReader::readVerified` (claims trusted — no re-verification; delegate to the
existing private readers via a synthesized `PreviewToken`-shaped dispatch):

```php
    /**
     * Read the draft/pinned version for an ALREADY-VERIFIED session (preview-sessions
     * spec §2) — same result shape as read(), no signature work. The token-based
     * read() stays for the JSON door.
     *
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    public function readVerified(\Glueful\Lemma\Contracts\Delivery\PreviewSession $session): array
    {
        $payload = PreviewToken::fromVerifiedClaims(
            $session->entry,
            $session->locale,
            $session->version,
            $session->expiresAt,
            $session->theme,
        );
        return $payload->versionUuid !== null
            ? $this->readVersion($payload)
            : $this->readDraft($payload);
    }
```

…which needs a named constructor on `PreviewToken` (the class's ctor is private):

```php
    /** For ALREADY-VERIFIED claims only (PreviewReader::readVerified) — never wire input. */
    public static function fromVerifiedClaims(
        string $entryUuid,
        string $locale,
        ?string $versionUuid,
        int $expiresAt,
        ?string $theme = null,
    ): self {
        return new self($entryUuid, $locale, $versionUuid, $expiresAt, $theme);
    }
```

`MintPreviewData`: add `#[Rule('string')] public readonly ?string $theme = null,`
(mirroring `version_uuid`'s style; keep it after `version_uuid`).

`PreviewController`: constructor gains `private readonly ?PreviewThemeValidator $themeValidator = null,`
(import the contract). In `mint()`, before minting:

```php
        // Per-preview theme (preview-sessions spec §5): validated through the
        // render-owned contract ONLY if bound — a theme with no validator (render
        // pack absent) is as invalid as an unknown one.
        if ($input->theme !== null && $input->theme !== '') {
            if ($this->themeValidator === null || !$this->themeValidator->isValidTheme($input->theme)) {
                return Response::validation(['theme' => 'Unknown theme (or rendered delivery is unavailable).']);
            }
        }
        $theme = $input->theme !== null && $input->theme !== '' ? $input->theme : null;
```

…and thread `$theme` into `$this->minter->mint($uuid, $locale, $input->version_uuid, $theme)`.

`packages/lemma-render/src/RenderThemeValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;

/**
 * The render pack's theme validation (preview-sessions spec §5) — the SAME ladder
 * semantics ThemeLocator resolves with: 'default' (the pack theme) is always valid;
 * anything else must be an app themes/{name} directory with a valid theme.json.
 */
final class RenderThemeValidator implements PreviewThemeValidator
{
    public function __construct(private readonly string $appThemesDir)
    {
    }

    public function isValidTheme(string $name): bool
    {
        if ($name === 'default') {
            return true;
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', $name) !== 1) {
            return false; // path-safe names only — this value ends up in filesystem paths
        }
        $dir = rtrim($this->appThemesDir, '/') . '/' . $name;
        if (!is_dir($dir . '/templates') || !is_file($dir . '/theme.json')) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($dir . '/theme.json'), true);
        return is_array($decoded) && is_string($decoded['name'] ?? null) && $decoded['name'] !== '';
    }
}
```

Wiring: `LemmaServiceProvider` registers `PreviewSessionVerifier::class => ['class' => EnginePreviewSessionVerifier::class, 'shared' => true, 'autowire' => true]` (import both) and converts `PreviewController` to a factory:

```php
            PreviewController::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewController'],
            ],
```

```php
    public static function makePreviewController(ContainerInterface $container): PreviewController
    {
        return new PreviewController(
            $container->get(PreviewMinter::class),
            $container->get(PreviewReader::class),
            $container->get(ContentLocaleService::class),
            $container->get(ApplicationContext::class),
            $container->has(PreviewThemeValidator::class)
                ? $container->get(PreviewThemeValidator::class)
                : null,
        );
    }
```

`LemmaRenderServiceProvider` registers the validator (import `PreviewThemeValidator` + `RenderThemeValidator`):

```php
            PreviewThemeValidator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderThemeValidator'],
            ],
```

```php
    public static function makeRenderThemeValidator(ContainerInterface $container): RenderThemeValidator
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderThemeValidator($context->getBasePath() . '/themes');
    }
```

- [ ] **Step 4: Run the Task 1 tests + neighbours**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php
vendor/bin/phpunit tests/Integration/PreviewFlowTest.php tests/Integration/Http/PreviewApiTest.php tests/Integration/Render/PreviewThemeTest.php
```

Expected: PASS (old-token compatibility means every existing preview test stays green;
`PreviewApiTest`'s direct construction gains the new nullable param only if PHP
complains — it shouldn't, the param has a default). No staging yet.

---

### Task 2: Session middleware + cache passthrough + Set-Cookie + exit

**Files:**
- Create: `packages/lemma-render/src/Http/Middleware/PreviewSessionMiddleware.php`
- Modify: `packages/lemma-render/src/Http/Middleware/RenderPageCache.php`, `packages/lemma-render/src/Http/Controllers/RenderController.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`, `packages/lemma-render/routes/public-routes.php`
- Test: `tests/Integration/Render/PreviewSessionTest.php`

**Interfaces:**
- Produces: request attribute **`lemma_preview_session`** (`PreviewSession` VO) set by the middleware; `RenderController::preview()` sets the cookie on success; `exit()` clears it. Task 3 reads the same attribute for chrome/overlay.

- [ ] **Step 1: Write the failing tests**

Add to `PreviewSessionTest.php` (imports: `use Glueful\Lemma\Render\Http\Middleware\PreviewSessionMiddleware;`, `use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;`, `use Symfony\Component\HttpFoundation\Response as SymfonyResponse;`):

```php
    // ---- Task 2: session cookie + cache guard -----------------------------------------

    private function sessionRequest(string $uri, string $token): Request
    {
        return Request::create($uri, 'GET', [], ['lemma_preview' => $token]);
    }

    public function testPreviewSetsSessionCookieWithRemainingTtl(): void
    {
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $cookies = $res->headers->getCookies();
        $cookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'lemma_preview') {
                $cookie = $c;
            }
        }
        self::assertNotNull($cookie);
        self::assertSame($token, $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertGreaterThan(time(), $cookie->getExpiresTime());
        self::assertFalse($cookie->isSecure()); // plain-HTTP test request

        // A FAILED preview must not start a session.
        $bad = $this->handle(Request::create('/_preview/garbage', 'GET'));
        self::assertSame([], array_filter(
            $bad->headers->getCookies(),
            static fn($c) => $c->getName() === 'lemma_preview',
        ));
    }

    public function testValidCookieBypassesCacheAndJunkCookieDoesNot(): void
    {
        $this->seedBilingualPublishedEntry();
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        // Prime the cache, then plant a sentinel.
        $this->handle(Request::create('/blog/hello', 'GET'));
        $cache = $this->container()->get(CacheStore::class);
        $key = 'render:default:/blog/hello';
        $cached = $cache->get($key);
        self::assertIsArray($cached);
        $cached['body'] = 'SENTINEL-CACHED';
        $cache->set($key, $cached, 3600);

        // JUNK cookie: normal cached behavior — the sentinel IS served (cache-bust guard).
        $junk = $this->handle($this->sessionRequest('/blog/hello', 'not-a-token'));
        self::assertSame('SENTINEL-CACHED', (string) $junk->getContent());

        // VALID cookie: neither served from cache NOR stored — sentinel untouched.
        $live = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertStringNotContainsString('SENTINEL-CACHED', (string) $live->getContent());
        self::assertSame('SENTINEL-CACHED', $cache->get($key)['body']); // no overwrite
    }

    public function testExitClearsTheSessionCookie(): void
    {
        $res = $this->handle(Request::create('/_preview/exit', 'GET'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/', $res->headers->get('Location'));
        $cleared = null;
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getName() === 'lemma_preview') {
                $cleared = $c;
            }
        }
        self::assertNotNull($cleared);
        self::assertLessThan(time(), $cleared->getExpiresTime()); // expired = cleared
    }

    public function testSessionSurvivesCacheDisabled(): void
    {
        // Session state is NOT cache state (spec §4): with cache_enabled=false the
        // middleware still detects the session. Middleware-direct (route latch).
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $middleware = new PreviewSessionMiddleware($this->verifier());
        $request = $this->sessionRequest('/blog/hello', $token);
        $middleware->handle($request, static fn ($r) => new SymfonyResponse('ok'));
        $session = $request->attributes->get('lemma_preview_session');
        self::assertNotNull($session);
        self::assertSame($entry, $session->entry);

        // And a DISABLED RenderPageCache passes session requests through untouched.
        $cacheOff = new RenderPageCache($this->container()->get(CacheStore::class), 'default', false, 3600);
        $out = $cacheOff->handle($request, static fn ($r) => new SymfonyResponse('rendered'));
        self::assertSame('rendered', (string) $out->getContent());
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php
```

Expected: Task 2 tests FAIL (no middleware class, no cookie, no exit route).

- [ ] **Step 3: Implement**

`packages/lemma-render/src/Http/Middleware/PreviewSessionMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Middleware;

use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session detection for preview sessions (preview-sessions spec §4) — deliberately
 * SEPARATE from RenderPageCache: session state is not cache state, and sessions must
 * survive cache_enabled=false. A lemma_preview cookie that VERIFIES becomes the
 * `lemma_preview_session` request attribute (the PreviewSession VO — one verification
 * per request, shared by the cache bypass, the controller chrome, and the overlay);
 * junk/expired cookies are silently ignored, so random cookies cannot cache-bust.
 */
final class PreviewSessionMiddleware implements RouteMiddleware
{
    public const ATTRIBUTE = 'lemma_preview_session';

    public function __construct(private readonly PreviewSessionVerifier $verifier)
    {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        $token = (string) $request->cookies->get('lemma_preview', '');
        if ($token !== '') {
            $session = $this->verifier->verify($token);
            if ($session !== null) {
                $request->attributes->set(self::ATTRIBUTE, $session);
            }
        }
        return $next($request);
    }
}
```

`RenderPageCache::handle` — FIRST lines of the method body (before the enabled check,
for clarity; both orders are correct):

```php
        // Verified preview sessions bypass the page cache wholesale (spec §4): no
        // read, no store. Verification happened in PreviewSessionMiddleware — this
        // layer only honors the attribute and never parses cookies itself.
        if ($request->attributes->has(PreviewSessionMiddleware::ATTRIBUTE)) {
            return $next($request);
        }
```

(import `PreviewSessionMiddleware` — same namespace, no `use` needed.)

`RenderController::preview()` — set the cookie on SUCCESS only. The controller gains
`private readonly ?PreviewSessionVerifier $sessionVerifier = null,` (after
`$facetReader`; import the contract) and `preview()` appends before returning the
200 path (inside the `kind === 'content'` else-branch, after chrome headers):

```php
            $session = $this->sessionVerifier?->verify($token);
            if ($session !== null) {
                $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie(
                    'lemma_preview',
                    $token,
                    $session->expiresAt,          // Max-Age = remaining TTL
                    '/',
                    null,
                    $request->isSecure(),         // Secure iff HTTPS (bearer credential)
                    true,                          // HttpOnly
                    false,
                    \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX,
                ));
            }
```

Add the exit action:

```php
    /** Ends the preview session (preview-sessions spec §1): clear the cookie, go home. */
    public function exit(): Response
    {
        $response = new Response('', 302, ['Location' => '/']);
        $response->headers->clearCookie('lemma_preview', '/');
        return $response;
    }
```

Routes (`public-routes.php`): register `GET /_preview/exit` BEFORE `/_preview/{token}`:

```php
$router->get('/_preview/exit', [RenderController::class, 'exit']);
```

…and attach the session middleware BEFORE the page cache on the three cached routes:

```php
$router->get('/', [RenderController::class, 'home'])
    ->middleware([PreviewSessionMiddleware::class, RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware([PreviewSessionMiddleware::class, RenderPageCache::class]);
```

(import `PreviewSessionMiddleware` in the routes file.)

Provider: register `PreviewSessionMiddleware::class` (`shared` + factory passing the
container verifier — `$container->get(PreviewSessionVerifier::class)`; the binding is
core-side and always present in-app) and extend `makeRenderController` with the soft
verifier arg (`$container->has(...) ? get : null`).

- [ ] **Step 4: Run + STAGE** *(grouping 1 — session core; commit only when authorized)*

```bash
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/PreviewFlowTest.php tests/Integration/Http/PreviewApiTest.php
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
git add packages/lemma-contracts/src/Delivery/PreviewSession.php \
        packages/lemma-contracts/src/Delivery/PreviewSessionVerifier.php \
        packages/lemma-contracts/src/Delivery/PreviewThemeValidator.php \
        app/Content/Preview app/Content/Http/DTOs/MintPreviewData.php \
        app/Content/Http/Controllers/PreviewController.php \
        app/Providers/LemmaServiceProvider.php \
        packages/lemma-render tests/Integration/Render/PreviewSessionTest.php
```

Expected: PASS / `PHPCS_EXIT=0` / boundaries OK. STOP — when authorized:

```bash
git commit -m "feat(preview): preview sessions — token-as-cookie with verified detection

PreviewSession VO + PreviewSessionVerifier contract (core impl over
PreviewToken); additive theme claim; readVerified; PreviewThemeValidator
render-owned mint validation; PreviewSessionMiddleware (before RenderPageCache,
independent of cache settings) sets the verified request attribute; cache
passthrough on the attribute only; Set-Cookie (Secure on HTTPS) on successful
/_preview; /_preview/exit."
```

---

### Task 3: Single-draft overlay + session chrome

**Files:**
- Modify: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`, `app/Content/Delivery/EnginePublicRouteResolver.php`, `packages/lemma-render/src/Http/Controllers/RenderController.php`, `packages/lemma-render/themes/default/templates/layout.twig`
- Test: `tests/Integration/Render/PreviewSessionTest.php`

**Interfaces:**
- Consumes: the `lemma_preview_session` attribute (Task 2); `PreviewReader::readVerified` (Task 1).
- Produces: `resolvePath(string $path, ?PreviewSession $previewSession = null)`; in-session responses carry chrome; the tokened entry's canonical URL serves the draft.

- [ ] **Step 1: Write the failing tests**

Add to `PreviewSessionTest.php` (import `use App\Content\Repositories\RouteRepository;`):

```php
    // ---- Task 3: overlay + chrome ------------------------------------------------------

    /** A draft WITH a published base + route, so it has a canonical URL to overlay. */
    private function seedRoutedEntryWithDraft(): array
    {
        $entry = $this->seedBilingualPublishedEntry(); // published "Hello" at /blog/hello
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entries->saveDraft($entry, 'en', ['title' => 'Draft override'], 1, 1, 'user00000001');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        return [$entry, $token];
    }

    public function testSessionOverlaysTheDraftAtItsCanonicalUrl(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        // Without the session: published content, cached.
        $published = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringContainsString('<h1>Hello</h1>', (string) $published->getContent());

        // With the session: the DRAFT at the same URL, full chrome.
        $res = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Draft override', $html);
        self::assertStringContainsString('preview-banner', $html);
        self::assertStringContainsString('/_preview/exit', $html); // Exit link
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        self::assertSame('noindex', $res->headers->get('X-Robots-Tag'));
        self::assertNull($res->headers->get('Cache-Tag'));
    }

    public function testSessionShowsPublishedContentInChromeElsewhere(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        $listing = $this->handle($this->sessionRequest('/blog', $token));
        self::assertSame(200, $listing->getStatusCode());
        $html = (string) $listing->getContent();
        self::assertStringContainsString('Hello', $html);          // PUBLISHED title, not the draft
        self::assertStringNotContainsString('Draft override', $html);
        self::assertStringContainsString('preview-banner', $html); // …but in chrome
        self::assertStringContainsString('no-store', (string) $listing->headers->get('Cache-Control'));
        // And nothing entered the page cache.
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:/blog'));
    }

    public function testInSessionNotFoundRendersFreshWithChrome(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        $res = $this->handle($this->sessionRequest('/no/such-page', $token));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('preview-banner', (string) $res->getContent());
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // The SHARED fixed 404 body was neither read nor filled by the session.
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:404'));
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php
```

Expected: Task 3 tests FAIL (published content without chrome; draft not overlaid).

- [ ] **Step 3: Implement**

Contract: `resolvePath(string $path, ?PreviewSession $previewSession = null): array`
(import the VO in the interface file) with the docblock line: "`$previewSession` is an
ALREADY-VERIFIED session — when the path resolves to its `{entry, locale}`, the draft
is returned (`kind: content`, `preview: true`); the resolver never re-verifies."

`EnginePublicRouteResolver::resolvePath` gains the param; in the entry-resolution
success path (the `'kind' => 'content'` return in `resolvePath`), before returning:

```php
        // Single-draft overlay (preview-sessions spec §3): the session's OWN entry
        // shows its draft at its canonical URL; everything else stays published.
        if (
            $previewSession !== null
            && $previewSession->entry === (string) $row['entry_uuid']
            && $previewSession->locale === (string) $row['locale']
        ) {
            return $this->previewContent($previewSession, $typeRow);
        }
```

…with a shared private that also lets `resolvePreview()` delegate (extract the shaping
from `resolvePreview` so both paths share it):

```php
    /**
     * Draft content for a VERIFIED session against a known type row — the shared
     * shaping between resolvePreview() and the session overlay. readVerified skips
     * re-verification; schema_version pins to the CURRENT type version (the
     * single-projection rule).
     *
     * @param array<string,mixed> $typeRow
     * @return array<string,mixed>
     */
    private function previewContent(PreviewSession $session, array $typeRow): array
    {
        try {
            $read = $this->preview->readVerified($session);
        } catch (PreviewNotFoundException) {
            return $this->notFound(); // draft/version vanished after minting
        }
        // …then the EXISTING resolvePreview body from "$typeUuid = …" through the
        // return, with $read as above (move that code here; resolvePreview() becomes:
        // verify via $this->sessionVerifier-equivalent — it keeps PreviewReader::read()
        // for the token path OR converts to: $session = verifier→…; simplest: keep
        // resolvePreview() reading via read($token) and have it call THIS method after
        // building a PreviewSession from its own read — NO: keep it minimal — move the
        // shaping lines (type lookup → synthesized row → shape → return) into
        // previewContent() taking ($read, $typeRow) instead, and call it from both).
    }
```

**Concrete refactor (do exactly this, replacing the sketch above):** extract the tail
of `resolvePreview()` — from the `$typeRow = $this->types->findByUuid(...)` lookup? No:
`previewContent(array $read): array` takes the READER RESULT, does the entry/type
lookups, synthesizes the row (current-schema-version pin), shapes, and returns the
`kind: content / preview: true` array — i.e. everything in `resolvePreview()` after the
`try/catch`. `resolvePreview()` becomes `try { $read = $this->preview->read($token); }
catch (...) { log + notFound } return $this->previewContent($read);`. The overlay call
site becomes:

```php
        if (
            $previewSession !== null
            && $previewSession->entry === (string) $row['entry_uuid']
            && $previewSession->locale === (string) $row['locale']
        ) {
            try {
                return $this->previewContent($this->preview->readVerified($previewSession));
            } catch (PreviewNotFoundException) {
                // Draft vanished mid-session: fall through to the published render.
            }
        }
```

`RenderController`: `page()` and `home()` read the attribute and thread it through —

```php
        $session = $request->attributes->get(PreviewSessionMiddleware::ATTRIBUTE);
        $result = $this->resolver->resolvePath('/' . ltrim($path, '/'), $session);
```

…and every `page()`/`home()` response gets chrome when `$session !== null`: extract
the preview header logic into a helper and use it from `preview()` too —

```php
    /** Preview/session chrome (spec §3): no-store, noindex, no surrogate tags. */
    private function sessionChrome(Response $response): Response
    {
        $response->headers->remove('Cache-Tag');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }
```

In-session template context: pass `'preview' => true, 'preview_exit' => '/_preview/exit'`
into the `$extra` of every render on the session path (entry arm via `renderEntry`'s
result — simplest: `page()` post-processes: when `$session !== null`, re-rendering is
wrong; INSTEAD thread a `$sessionExtra = $session !== null ? ['preview' => true,
'preview_exit' => '/_preview/exit'] : []` into the arms that call `render()` —
`renderCollection`, `renderTerms`, `renderEntry`, and the 404/410 arms gain an
`array $extra = []` parameter merged into their `render()` calls). In-session 404/410:
branch AROUND `RenderErrorCache` —

```php
            'gone' => $session !== null
                ? $this->render('error.twig', $this->defaultLocale(), null, 410, $sessionExtra)
                : $this->errors->themed410(...existing...),
```

(and the same shape for the `default` 404 arm).

`layout.twig` banner gains the Exit link:

```twig
    {% if preview|default(false) %}
      <div class="preview-banner">
        Preview — unpublished content
        {% if preview_exit|default(null) %}<a href="{{ preview_exit }}">Exit preview</a>{% endif %}
      </div>
    {% endif %}
```

- [ ] **Step 4: Run the render + preview suites**

```bash
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/PreviewFlowTest.php
```

Expected: PASS (incl. every pre-existing render test — non-session paths are
byte-unchanged). No staging yet.

---

### Task 4: Per-preview theme + `/_preview-assets/{token}/…`

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`, `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/routes/public-routes.php`
- Test: `tests/Integration/Render/PreviewSessionTest.php`

**Interfaces:**
- Consumes: `PreviewSession->theme` / `->token` (Task 1); `makeAltTheme()` fixture (Task 1); the request attribute (Task 2).
- Produces: request-local themed rendering (memo untouched); `RenderContextExtension::setAssetBase(?string)`; `RenderController::previewAsset(Request, string $token, string $path): Response`.

- [ ] **Step 1: Write the failing tests**

Add to `PreviewSessionTest.php`:

```php
    // ---- Task 4: per-preview theme + assets -------------------------------------------

    public function testThemedSessionRendersAltThemeWithoutPoisoningTheBootTheme(): void
    {
        $this->makeAltTheme();
        [, ] = $this->seedRoutedEntryWithDraft();
        $entry2 = $this->seedDraftEntry('Alt themed');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry2, 'en', null, 'altprev');

        // Themed preview renders the ALT template…
        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('ALTPREV:Alt themed', (string) $res->getContent());
        // …with token-scoped asset URLs.
        self::assertStringContainsString('/_preview-assets/' . $token . '/', (string) $res->getContent());

        // The memoized boot environment is NOT poisoned: a plain request right after
        // renders the boot theme with normal asset URLs.
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringNotContainsString('ALTPREV:', (string) $plain->getContent());
        self::assertStringContainsString('/theme-assets/', (string) $plain->getContent());
        self::assertStringNotContainsString('/_preview-assets/', (string) $plain->getContent());
    }

    public function testPreviewAssetRouteServesOnlyTheSignedThemeSafely(): void
    {
        $this->makeAltTheme();
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        $ok = $this->handle(Request::create('/_preview-assets/' . $token . '/alt.css', 'GET'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));

        // Traversal, junk tokens, and theme-less tokens all 404.
        self::assertSame(
            404,
            $this->handle(Request::create('/_preview-assets/' . $token . '/../theme.json', 'GET'))->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->handle(Request::create('/_preview-assets/garbage/alt.css', 'GET'))->getStatusCode(),
        );
        $plainToken = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        self::assertSame(
            404,
            $this->handle(Request::create('/_preview-assets/' . $plainToken . '/alt.css', 'GET'))->getStatusCode(),
        );
    }

    public function testAssetBaseResetsAfterAThemedRenderExceptionAndVanishedThemeFallsBack(): void
    {
        $this->makeAltTheme();
        $entry = $this->seedDraftEntry('Vanish');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        // Break the alt theme's template so the themed render throws internally
        // (error.twig fallback renders) — then a NORMAL render must emit normal asset
        // URLs (the reset-before-render guard).
        $base = $this->appContext()->getBasePath() . '/themes/altprev/templates';
        file_put_contents($base . '/entry.twig', '{{ undefined_fn() }}');
        $this->handle(Request::create('/_preview/' . $token, 'GET')); // 500-ish, ignored
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringNotContainsString('/_preview-assets/', (string) $plain->getContent());

        // Vanished theme: remove the dir entirely → session falls back to the BOOT theme.
        foreach (glob($this->appContext()->getBasePath() . '/themes/altprev/templates/*') ?: [] as $f) {
            unlink($f);
        }
        // (tearDown removes the rest; deleting templates/ makes ThemeLocator fall back.)
        $fallback = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $fallback->getStatusCode());
        self::assertStringContainsString('Vanish', (string) $fallback->getContent()); // boot entry.twig
        self::assertStringNotContainsString('ALTPREV:', (string) $fallback->getContent());
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php
```

Expected: Task 4 tests FAIL (boot theme renders; no `/_preview-assets` route).

- [ ] **Step 3: Implement**

`RenderContextExtension`: add `private ?string $assetBase = null;`, and:

```php
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
```

…and `asset()`'s return becomes:

```php
        return ($this->assetBase ?? '/theme-assets') . '/' . $rel;
```

`RenderController::render()`: signature gains `?Environment $twig = null` and
`?string $assetBase = null` (after `$extra`); the reset block becomes:

```php
        $this->extension->resetTags();
        $this->extension->setAssetBase($assetBase); // null on every non-themed render
        $this->extension->setLocale($locale);
```

…and every internal `$this->twig()` use inside `render()` switches to
`$env = $twig ?? $this->twig();` (the memo is written ONLY by `twig()` for the boot
theme — themed environments are locals).

Theme machinery in `preview()` (and the session paths of `page()`/`home()` when
`$session->theme !== null`):

```php
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
            $factory = new TwigFactory(
                new ThemeLocator($session->theme, $base . '/themes'),
                $this->extension,
                $base . '/storage/cache/twig',
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
```

Thread `[$env, $assetBase]` through the session/preview render calls (the `$extra`
plumbing from Task 3 extends with the two new `render()` args; template-exists checks
on themed paths use `$env`'s loader). NOTE: `ThemeLocator` falls back to the pack
default when the app theme dir is missing — the "vanished" case may return a working
default-theme locator rather than throw; either way the assertion is "boot-family
rendering, no ALTPREV" and both branches satisfy it.

The asset action + route:

```php
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
        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($file);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
```

Route (before the catch-all; `Response` union: the action returns Symfony responses —
match the render controller's existing mixed return usage):

```php
$router->get('/_preview-assets/{token}/{path}', [RenderController::class, 'previewAsset'])
    ->where('path', '.+');
```

(`RenderController` return type for `previewAsset` should be
`\Symfony\Component\HttpFoundation\Response` — adjust the signature accordingly.)

- [ ] **Step 4: Run the suites**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS. No staging yet.

---

### Task 5: Docs + OpenAPI check + full verification + STAGE

**Files:**
- Modify: `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md`; regenerate `docs/openapi.json`

- [ ] **Step 1: README** — extend the "Preview in the theme" section:

```markdown
Opening a preview also starts a short-lived **preview session** (a signed cookie that
expires with the token): navigation stays in preview chrome (banner with an Exit
link, `no-store`, `noindex`, never cached), your draft appears at its own URL, and
every other page shows published content. `GET /_preview/exit` ends the session.
Minting accepts an optional `theme` (validated against installed themes, signed into
the token): the whole session renders through that theme, with assets served from the
token-scoped `/_preview-assets/{token}/…` route. Sessions work with the page cache
disabled; junk cookies never bypass the cache.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]` (prepend under `### Added`)**

```markdown
- **Preview sessions (preview v2)**: `/_preview/{token}` now starts a signed-cookie
  session (Secure on HTTPS; dies with the token) — full-site navigation in preview
  chrome with an Exit link, the tokened draft overlaid at its canonical URL
  (single-draft scope: everything else stays published), listing/archive/term pages
  navigable uncached, and in-session 404s rendered fresh. New contracts:
  `PreviewSession` VO + `PreviewSessionVerifier` (one verification per request via
  `PreviewSessionMiddleware` — sessions survive `cache_enabled=false`) and
  `PreviewThemeValidator` (render-owned mint validation). Optional per-preview
  `theme` is signed into the token; themed sessions render through request-local
  Twig environments with token-scoped `/_preview-assets/{token}/…` assets.
```

- [ ] **Step 3: Tracker flips + OpenAPI** — `docs/V2_DESIGN.md` §6 has no remaining
preview line (preview-through-theme already ✅) — instead append to that ✅ line:
"; preview SESSIONS (full-site nav, listing preview, per-preview themes) shipped
2026-07-02 (`docs/superpowers/specs/2026-07-02-preview-sessions-design.md`)".
`docs/NEXT.md`: same appended sentence on the preview bullet. Regenerate + verify:

```bash
php glueful generate:openapi -f --clean
python3 -c "
import json; spec = json.load(open('docs/openapi.json'))
assert not any(p.startswith('/_preview') for p in spec['paths']), 'preview routes leaked'
mint = spec['paths']['/v1/admin/entries/{uuid}/preview/{locale}']['post']
body = mint['requestBody']['content']['application/json']['schema']['properties']
assert 'theme' in body, 'theme missing from mint request schema'
print('openapi OK,', len(spec['paths']), 'paths')
"
cd admin && pnpm gen:api && pnpm type-check && cd ..
```

- [ ] **Step 4: Full verification + STAGE** *(grouping 2; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add packages/lemma-contracts app/Content packages/lemma-render \
        app/Providers/LemmaServiceProvider.php \
        docs/openapi.json admin/src \
        tests/Integration/Render/PreviewSessionTest.php \
        CHANGELOG.md docs/V2_DESIGN.md docs/NEXT.md
```

Expected: green (same pre-existing single skip). STOP — when authorized:

```bash
git commit -m "feat(render): full-site preview navigation, per-preview themes, preview assets

Single-draft overlay via resolvePath(path, ?PreviewSession); session chrome on
every in-session response with fresh 404s (RenderErrorCache untouched by
sessions); request-local themed Twig environments (memoized boot env never
poisoned); asset-base override with reset-before-render; token-scoped
/_preview-assets serving only the signed theme."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 cookie attrs/Secure/exit → Task 2 (+ failed-preview-sets-no-cookie assertion); §2 VO/verifier/readVerified/resolvePath-signature → Tasks 1+3 (the `fromVerifiedClaims` named constructor keeps `PreviewToken`'s private-ctor invariant while letting the reader skip re-verification); §3 overlay/chrome/fresh-404s → Task 3 (incl. the fixed-body-untouched assertion and the draft-vanished-mid-session fall-through to published); §4 middleware split/attribute/cache-bust guard/cache-disabled survival → Task 2 (sentinel test both directions; middleware-direct test for cache-off); §5 theme claim/validator seam/422s/request-local env/asset route+reset → Tasks 1+4 (the memo-poisoning and exception-leak tests are exactly the two review notes); §6 surfaces all mapped; §7 test list fully mapped incl. OpenAPI (Task 5) and old-token compatibility (Task 1's null-theme assertions + the untouched JSON-door suites).
- **Type consistency:** `PreviewSession` ctor order (token, entry, locale, version, theme, expiresAt) matches the verifier construction and every property access; `mint(entry, locale, ?version, ?theme)` matches minter/controller/test call sites; the middleware `ATTRIBUTE` constant is the single attribute-name source (RenderPageCache + RenderController read it); `render(template, locale, entry, status, extra, ?twig, ?assetBase)` matches all threaded call sites.
- **Judgement calls, stated:** the `themedEnv` fallback note (ThemeLocator may fall back to the pack default rather than throw for a missing dir — the test asserts boot-family rendering either way); Task 3's `$extra`-threading touches four arms (listed) — mechanical but wide, which is why Task 3 is its own reviewer gate; `previewAsset` returns a Symfony response directly (BinaryFileResponse cannot be the Glueful envelope — matches the render controller's raw-response convention).
