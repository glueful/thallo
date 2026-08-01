# Lemma Preview Tokens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A narrow preview door: the admin API mints a short-lived, HMAC-signed token bound to one entry+locale; `GET /v1/preview/{token}` verifies it and returns that entry's **current draft** (or a specific pinned version for historical preview) — with **no** "preview mode" flag on the public delivery API.

**Architecture:** A `PreviewToken` value object encodes `{entry_uuid, locale, version_uuid?, exp}` and is HMAC-signed with the app key (`APP_KEY`). The admin endpoint `POST /v1/admin/entries/{uuid}/preview/{locale}` (permission-gated) mints one with a minutes-scale TTL. The public endpoint `GET /v1/preview/{token}` is unauthenticated **by design** — the token *is* the capability — but is rate-limited; it verifies signature + expiry, then reads the draft via the foundation's `EntryRepository::findDraft` (or a pinned version via `VersionRepository::findVersionByUuid`) and returns it. Drafts are reachable **only** through this narrow, signed, expiring door — the public delivery repository physically cannot see drafts (foundation §2), and there is no draft-exposing flag anywhere on `/v1/content`.

**Tech Stack:** PHP 8.3, Glueful ^1.56.0, HMAC over `APP_KEY` (reuse `Glueful\Support\SignedUrl` if its API fits, else `hash_hmac('sha256', ...)` + `hash_equals` directly — the same primitives the framework's `QueuePayloadSigner`/`WebhookSignature` use), the foundation's `EntryRepository`/`VersionRepository`/`ContentTypeRepository`. Builds on the foundation; independent of the Delivery/Pipeline plans (can be built any time after the foundation).

**Source of truth:** [`../V1_DESIGN.md`](../V1_DESIGN.md) §6 (preview). 

**Scope boundary:** mint + verify + serve-draft. **Not** here: a rendered preview UI (that's the frontend/SPA), live-reload, or comment/annotation overlays. Preview returns the same JSON shape as a draft read.

---

## Conventions
Inherit the foundation conventions (`LemmaTestCase`, PSR-12 `composer phpcs` gate, `Glueful\Http\Response`, the `lemma_permission` middleware, route files auto-discovered by `RouteManifest` — do **not** `loadRoutesFrom` in the provider). The app key is read via `config($context, 'app.key', ...)` / `app.key_base64` — **confirm the exact app-key accessor** against `src/` at first use (the framework uses `APP_KEY`; the encryption service reads it — mirror that). Tokens must fail closed: any signature/exp/shape problem → 403/410, never a partial read.

---

## File structure
```
config/lemma.php                          # MODIFY: + preview.ttl_seconds (default 600)
app/Content/Preview/
  PreviewToken.php                        # VO: encode/decode + HMAC sign/verify (APP_KEY), TTL, entry+locale binding
  PreviewTokenException.php               # invalid signature / expired / malformed -> mapped to 403/410
  PreviewMinter.php                       # mints a token for (entry, locale, ?version) with TTL
  PreviewReader.php                       # verify + resolve draft (or pinned version) -> payload
app/Content/Http/Controllers/
  PreviewController.php                   # POST mint (admin, gated) ; GET /v1/preview/{token} (public, rate-limited)
routes/
  lemma_preview.php                       # NEW route file (auto-discovered)
  lemma_admin.php                         # MODIFY: + POST /entries/{uuid}/preview/{locale}
tests/
  Unit/Content/PreviewTokenTest.php       # sign/verify/expiry/tamper (pure unit)
  Integration/Http/PreviewApiTest.php     # mint (gated) + serve-draft + reject expired/tampered
  Integration/PreviewFlowTest.php         # admin mints -> GET /v1/preview/{token} returns the draft (kernel-level)
```

---

### Task 1: `PreviewToken` — sign / verify / expiry / binding (pure unit)

**Files:** Create `app/Content/Preview/PreviewToken.php`, `app/Content/Preview/PreviewTokenException.php`; Test `tests/Unit/Content/PreviewTokenTest.php`.

- [ ] **Step 1: Write the failing unit test** (no DB; pass a fixed key): `PreviewToken::mint(entry, locale, version, expiresAt, key)` produces an opaque string; `PreviewToken::verify(token, key, now)` round-trips to the same `{entry, locale, version}`; a **tampered** token (flip a char) → `PreviewTokenException` (invalid signature); an **expired** token (`exp < now`) → `PreviewTokenException` (expired, distinct so the controller can 410); a token signed with a **different key** → invalid. Use `hash_equals` semantics (constant-time) — assert verification rejects a forged signature.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** Payload = `base64url(json{e,l,v,exp})` + `'.'` + `base64url(hash_hmac('sha256', payloadPart, $key, true))`. `verify` recomputes the HMAC over the payload part and `hash_equals`-compares (reject on mismatch), then checks `exp` against `now` (reject expired), then returns the decoded `{entry, locale, version}`. Distinguish "invalid signature/shape" from "expired" via two exception kinds/codes. No app context needed (key passed in).

```php
<?php
declare(strict_types=1);
namespace App\Content\Preview;

final class PreviewToken
{
    private function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
        public readonly ?string $versionUuid,
        public readonly int $expiresAt,
    ) {}

    public static function mint(string $entryUuid, string $locale, ?string $versionUuid, int $expiresAt, string $key): string
    {
        $payload = self::b64(json_encode([
            'e' => $entryUuid, 'l' => $locale, 'v' => $versionUuid, 'exp' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
        $sig = self::b64(hash_hmac('sha256', $payload, $key, true));
        return $payload . '.' . $sig;
    }

    public static function verify(string $token, string $key, int $now): self
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw PreviewTokenException::malformed();
        }
        [$payload, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $payload, $key, true));
        if (!hash_equals($expected, $sig)) {
            throw PreviewTokenException::invalidSignature();
        }
        $data = json_decode(self::unb64($payload), true);
        if (!is_array($data) || !isset($data['e'], $data['l'], $data['exp'])) {
            throw PreviewTokenException::malformed();
        }
        if ((int) $data['exp'] < $now) {
            throw PreviewTokenException::expired();
        }
        return new self(
            (string) $data['e'], (string) $data['l'],
            isset($data['v']) && is_string($data['v']) ? $data['v'] : null,
            (int) $data['exp'],
        );
    }

    private static function b64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }
}
```

- [ ] **Step 4: Run → pass. Step 5: Commit** `Add HMAC-signed PreviewToken (sign/verify/expiry/binding)`.

---

### Task 2: `PreviewMinter` + `PreviewReader`

**Files:** Create `app/Content/Preview/PreviewMinter.php`, `app/Content/Preview/PreviewReader.php`; Modify `config/lemma.php` (+`preview.ttl_seconds`=600); Test `tests/Integration/Http/PreviewApiTest.php` (the reader half).

- [ ] **Step 1: Write the failing integration test** — seed an entry + draft (foundation repos); `PreviewMinter::mint(entryUuid, 'en', null)` returns a token; `PreviewReader::read(token)` returns the **draft** fields; minting with a `version_uuid` returns that pinned version's fields instead; a token for a non-existent entry → 404-class result; an expired token → expired exception.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - `PreviewMinter(ApplicationContext)`: reads the app key (confirm accessor) + `preview.ttl_seconds`; `mint(entry, locale, ?version): string` = `PreviewToken::mint(..., time() + ttl, $key)`. **Does not** check the draft exists at mint time (the reader does) — minting is cheap and the token is bound, not authoritative.
  - `PreviewReader(ApplicationContext, EntryRepository, VersionRepository)`: `read(string $token): array` → `PreviewToken::verify($token, $key, time())` (propagates expired/invalid), then if `versionUuid` is set → `VersionRepository::findVersionByUuid` (404-class if missing/not matching entry+locale), else → `EntryRepository::findDraft(entry, locale)` (404-class if no draft). Returns the hydrated fields + identity. **Never** falls back to anything published-or-not on a verification failure.
- [ ] **Step 4: Run → pass. Step 5: Commit** `Add PreviewMinter + PreviewReader (draft + pinned-version preview)`.

---

### Task 3: `PreviewController` + routes (admin mint gated; public GET rate-limited, fail-closed)

**Files:** Create `app/Content/Http/Controllers/PreviewController.php`, `routes/lemma_preview.php`; Modify `routes/lemma_admin.php`; Test (controller cases folded into `PreviewApiTest`).

- [ ] **Step 1: Write the failing test** — admin mint endpoint returns `{token, expires_at, expires_in}` and is gated by `lemma_permission:lemma.entries.read` (or `.write`); the public GET returns the draft for a valid token (200), returns **403** for a tampered/invalid token and **410 Gone** for an expired one, and is **not** behind `auth` (the token is the capability).
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - `PreviewController::mint(Request, string $uuid, string $locale)` → resolve actor via `auth.user`; optional `version_uuid` from body for historical preview; `PreviewMinter::mint(...)`; return `Response::success(['token' => ..., 'expires_at' => date('c', $exp), 'expires_in' => $ttl])`.
  - `PreviewController::show(Request, string $token)` → `try { $payload = $reader->read($token); } catch (expired) { return Response::error('Preview link expired', 410); } catch (invalid|malformed) { return Response::forbidden('Invalid preview token'); }` → `Response::success(['preview' => $payload])`.
  - `routes/lemma_admin.php` (+ inside the existing `/v1/admin` group): `POST /entries/{uuid}/preview/{locale}` → `lemma_permission:lemma.entries.read`.
  - `routes/lemma_preview.php` (NEW, auto-discovered): a `/v1/preview` group with **no `auth`** middleware — only `rate_limit` (e.g. `->rateLimit(60, 1, by: 'ip')` since there's no user): `GET /preview/{token}` → `PreviewController::show`. (Confirm a route param can carry the dotted/base64url token, or accept it as a query/`?t=` param if the router rejects `.` in a path segment — **verify the router's path-segment charset** and choose accordingly; a `?t=<token>` query is a safe fallback.)
  - Register `PreviewController`/`PreviewMinter`/`PreviewReader` in `LemmaServiceProvider::services()` (autowire).
- [ ] **Step 4: Run → pass. Step 5: Commit** `Add preview API (admin mint + public token read)`.

---

### Task 4: Wire + end-to-end preview flow + full suite

**Files:** Test `tests/Integration/PreviewFlowTest.php`.

- [ ] **Step 1: Write the failing kernel-level test** — authenticated admin `POST /v1/admin/entries/{uuid}/preview/en` → get a token → `GET /v1/preview/{token}` (no auth) returns the draft fields; tamper the token → 403; (optionally, with a clock shim or a 0-second TTL) expired → 410. Reuse the harness kernel helpers from the foundation/delivery flow tests.
- [ ] **Step 2:** ensure the routes are live (`route:debug` shows `/v1/preview/{token}` unauthenticated + rate-limited and the admin mint route gated). Run FULL `composer test` (green) + `composer phpcs` (clean).
- [ ] **Step 3: Commit** `Wire preview tokens; end-to-end preview flow test`.

---

## Self-review
- **Spec coverage (§6 preview):** short-lived HMAC token bound to entry+locale, minted by admin → Tasks 1,2,3; serves the current draft or a specific pinned version → Task 2; the public read is a separate narrow door (no `auth`, token-as-capability, rate-limited), and there is **no preview-mode flag on `/v1/content`** → Task 3 (structural — preview is its own route file/controller, the delivery repo can't see drafts).
- **Security:** fail-closed everywhere — invalid signature → 403, expired → 410, malformed → 403; `hash_equals` constant-time compare; the token binds the exact entry+locale (a token for entry A can't read entry B); minutes-scale TTL; the public endpoint is rate-limited by IP. Drafts remain unreachable except through a valid, unexpired, correctly-signed token.
- **Verify-points:** the app-key accessor (T2); whether the router accepts a base64url/dotted token in a path segment vs needing `?t=` (T3); the kernel-level mint→read round-trip helpers (T4).
- **Independence:** depends only on the foundation (drafts/versions); can be built before or after the Delivery/Pipeline plans.
