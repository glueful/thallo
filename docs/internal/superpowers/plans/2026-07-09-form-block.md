# Form Block Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a generic `form` block (contact preset in v1) whose submissions are stored, best-effort emailed, spam-guarded, and triaged/exported in the admin — using a sealed-descriptor model so the exact form the visitor saw is the exact schema the server validates.

**Architecture:** The block renders through the render pack's `blocks/form.twig`, which seals a descriptor (AES-GCM `EncryptionService`) via an app-bound `FormSealer` reached through a `packages/thallo-contracts` interface. Submissions POST to a public app endpoint that opens the descriptor, runs a guard chain, validates against the sealed fields, stores a normalized snapshot, and best-effort emails. An admin Submissions area lists/filters/exports them. All backend code lives in `app/Content/Forms/`.

**Tech Stack:** PHP 8.3+ (Glueful framework), Twig theme templates, Vue 3 + Nuxt UI admin SPA + `@pinia/colada`, PHPUnit + Vitest.

**Reference spec:** `docs/superpowers/specs/2026-07-09-form-block-design.md`

## Global Constraints

- Work on `dev` directly. No feature branches.
- **HOLD all commits** until explicit go-ahead. Do not commit per task; batch at logical groupings when told. (Commit steps below are grouping markers, not licence to commit early.)
- No AI/Anthropic attribution anywhere (commits, PR bodies, comments).
- Never stage/commit `CLAUDE.md`.
- Descriptor sealing uses `Glueful\Encryption\EncryptionService::encrypt(string, aad: 'form.descriptor')` / `decrypt`.
- Descriptor expiry = `issued_at + max(forms.descriptor_max_age (default 1209600), render_cache_ttl + forms.descriptor_buffer (default 3600))`.
- `redirect_url` is **internal/relative only**, validated at seal AND post-decrypt.
- Validation runs **only** against sealed `fields[]` — never visible field names.
- Silent spam rejects return **generic success** in BOTH AJAX and PRG paths; rejection reason recorded server-side only.
- Store **normalized** values (checkbox→bool, select→canonical, tel/email trimmed), never raw request bags.
- Un-routable form (no valid recipient and no valid `forms.default_recipient`) → sealer emits NO descriptor → block renders disabled notice → endpoint unreachable.
- Backend PHP: `declare(strict_types=1)`, `final class`, constructor DI, `use` imports (no inline FQCNs). Run `composer phpcs` before considering a backend task done.
- Verify backend: `composer test:phpunit -- --filter=<Name>`. Verify admin: `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint`.

---

## File Structure

**Contracts (`packages/thallo-contracts/src/`):**
- `Content/FormSealer.php` — interface the render pack calls to seal a descriptor.

**Render pack (`packages/thallo-render/`):**
- `themes/default/templates/blocks/form.twig` — the rendered form (create).
- `src/RenderContextExtension.php` — add the single `form_render(block)` Twig function (modify).
- `themes/default/assets/blocks.css` — `.thallo-block-form` styles (modify).
- `themes/default/assets/blocks.js` — progressive-enhancement submit (modify).

**App backend (`app/Content/Forms/`):**
- `FieldDef.php` — normalized field-definition value object.
- `FormDescriptor.php` — descriptor value object (seal payload shape).
- `FormFieldDerivation.php` — derive `FieldDef[]` from a `form` block's `data`.
- `FormSourceIdentity.php` — resolve `source_identity` with fallback order.
- `DefaultFormSealer.php` — implements `FormSealer`: derive + resolve recipient + seal/open.
- `FormSubmission.php` — stored-submission value object.
- `FormSubmissionRepository.php` — persistence + queries + status + delete + export.
- `Spam/FormSubmissionGuard.php` — guard interface + `GuardResult`.
- `Spam/DefaultFormGuard.php` — honeypot + time-trap + rate-limit.
- `FormNotifier.php` — best-effort email seam.
- `FormValueNormalizer.php` — normalize submitted values against `FieldDef[]`.

**App HTTP (`app/`):**
- `Content/Blocks/StarterBlockTypes.php` — add `form` block type (modify).
- `Http/Controllers/FormSubmitController.php` — `POST /_forms/submit`.
- `Http/Controllers/FormSubmissionsController.php` — admin list/detail/read/delete/export.
- `Http/DTOs/FormSubmitData.php` — request DTO for a submit.
- `routes/forms.php` — public submit route (create).
- `routes/admin.php` — admin submissions routes (modify).
- `database/migrations/012_CreateFormSubmissionsTable.php` — table (create).
- `config/forms.php` — config defaults (create).

**Admin SPA (`admin/src/`):**
- `queries/formSubmissions.ts` — query/mutation layer (create).
- `pages/submissions/index.vue` — Submissions page (create).
- `router` / nav config — register route + unread badge (modify).
- `__tests__/formSubmissions*.spec.ts` — tests (create).

---

## Task 1: FormSealer contract + descriptor sealing/opening

**Files:**
- Create: `packages/thallo-contracts/src/Content/FormSealer.php`
- Create: `app/Content/Forms/FieldDef.php`
- Create: `app/Content/Forms/FormDescriptor.php`
- Create: `app/Content/Forms/SealedForm.php`
- Create: `app/Content/Forms/FormSourceIdentity.php`
- Create: `app/Content/Forms/DefaultFormSealer.php`
- Create: `config/forms.php`
- Test: `tests/Integration/Forms/FormSealerTest.php`

**Interfaces:**
- Produces:
  - `FieldDef(string $key, string $label, string $type, bool $required, ?string $placeholder, ?string $help, array $options)` with `->toArray()` / `::fromArray()`. `type ∈ {text,email,tel,textarea,select,checkbox}`.
  - `FormDescriptor(int $v, string $formKey, string $formName, array $fields /* FieldDef[] */, string $recipient, string $successMessage, ?string $redirectUrl, string $honeypotField, int $minSeconds, int $spamVersion, int $issuedAt)` with `->toArray()` / `::fromArray()`.
  - `SealedForm(string $token, FormDescriptor $descriptor)` — the one-pass render result (public readonly props `token`, `descriptor`).
  - `interface FormSealer { public function describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug): ?SealedForm; public function open(string $token): ?FormDescriptor; }` — `describe` derives+seals once and returns null when un-routable/underivable; `open` returns null when tampered/expired.
  - `FormSourceIdentity::resolve(?array $entry, ?string $regionSlug, ?string $currentPath): string`.
- Consumes: `Glueful\Encryption\EncryptionService`, `App\Content\Forms\FormFieldDerivation` (Task 2 — see note in Step 3).

> **Sequencing note:** `DefaultFormSealer::describe()` derives fields via `FormFieldDerivation` (Task 2). To keep Task 1 self-contained and testable, Task 1 implements `describe()`/`open()` against an **injected** derivation callable; Task 2 delivers the real `FormFieldDerivation`. Task 1's test constructs the sealer with a tiny in-test fake derivation returning a fixed `FieldDef[]`. Task 2 swaps in the real one.

- [ ] **Step 1: Write `config/forms.php`**

```php
<?php

declare(strict_types=1);

return [
    // Descriptor lifetime; the sealer uses max(this, render_cache_ttl + buffer).
    'descriptor_max_age' => (int) env('FORMS_DESCRIPTOR_MAX_AGE', 1209600), // 14 days
    'descriptor_buffer'  => (int) env('FORMS_DESCRIPTOR_BUFFER', 3600),     // 1 hour
    // Time-trap floor (seconds). A submit faster than this is treated as a bot.
    'min_seconds'        => (int) env('FORMS_MIN_SECONDS', 2),
    // Per form_key + IP rate limit.
    'rate_limit'         => ['max' => (int) env('FORMS_RATE_MAX', 5), 'window' => (int) env('FORMS_RATE_WINDOW', 60)],
    // Fallback recipient when a block leaves recipient empty. Empty => forms with
    // no block recipient are un-routable (sealer refuses).
    'default_recipient'  => (string) env('FORMS_DEFAULT_RECIPIENT', ''),
];
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Content\Forms\DefaultFormSealer;
use App\Content\Forms\FieldDef;
use App\Content\Forms\FormDescriptor;
use Glueful\Encryption\EncryptionService;
use Tests\Support\AppTestCase;

final class FormSealerTest extends AppTestCase
{
    private function sealer(int $maxAge = 1209600): DefaultFormSealer
    {
        $enc = $this->getService(EncryptionService::class);
        // In-test derivation: fixed one-field form so Task 1 needn't depend on Task 2.
        $derive = fn (array $data): array => [new FieldDef('email', 'Email', 'email', true, null, null, [])];
        return new DefaultFormSealer($enc, $derive, cacheTtl: 3600, maxAge: $maxAge, buffer: 3600, defaultRecipient: '', minSeconds: 2);
    }

    public function testDescribeAndOpenRoundTrip(): void
    {
        $block = ['id' => 'abc123', 'type' => 'form', 'data' => ['recipient' => 'owner@site.test', 'form_name' => 'Contact']];
        $sf = $this->sealer()->describe($block, entry: ['uuid' => 'e-1'], currentPath: '/contact', regionSlug: null);
        self::assertNotNull($sf);
        self::assertIsString($sf->token);
        // The render path reads the descriptor DIRECTLY off the SealedForm — no re-open.
        self::assertSame('owner@site.test', $sf->descriptor->recipient);
        self::assertSame(hash('sha256', 'entry:e-1|abc123'), $sf->descriptor->formKey);
        self::assertSame(2, $sf->descriptor->minSeconds); // time-trap armed at seal time

        // The submit path re-opens the token and gets the same descriptor.
        $d = $this->sealer()->open($sf->token);
        self::assertInstanceOf(FormDescriptor::class, $d);
        self::assertSame('owner@site.test', $d->recipient);
        self::assertCount(1, $d->fields);
    }

    public function testTamperedTokenOpensToNull(): void
    {
        $token = $this->sealer()->describe(['id' => 'x', 'type' => 'form', 'data' => ['recipient' => 'a@b.test']], null, '/c', null)->token;
        self::assertNull($this->sealer()->open(substr($token, 0, -2) . 'zz'));
    }

    public function testExpiredDescriptorOpensToNull(): void
    {
        // maxAge negative => already expired at issue.
        $token = $this->sealer(maxAge: -100000)->describe(['id' => 'x', 'type' => 'form', 'data' => ['recipient' => 'a@b.test']], null, '/c', null)->token;
        self::assertNull($this->sealer(maxAge: -100000)->open($token));
    }

    public function testUnroutableFormRefusesToDescribe(): void
    {
        // No block recipient, no default recipient => null (no SealedForm, no descriptor).
        self::assertNull($this->sealer()->describe(['id' => 'x', 'type' => 'form', 'data' => []], null, '/c', null));
    }

    public function testSourceIdentityFallbackOrder(): void
    {
        $s = $this->sealer();
        $entry = ['uuid' => 'e-9'];
        $kEntry = $s->describe(['id' => 'blk', 'type' => 'form', 'data' => ['recipient' => 'a@b.test']], $entry, '/p', null)->descriptor->formKey;
        $kRegion = $s->describe(['id' => 'blk', 'type' => 'form', 'data' => ['recipient' => 'a@b.test']], null, '/p', 'header')->descriptor->formKey;
        // Same block id, different source context => different form_key.
        self::assertNotSame($kEntry, $kRegion);
    }
}
```

- [ ] **Step 3: Run it, expect failure**

Run: `composer test:phpunit -- --filter=FormSealerTest`
Expected: FAIL — classes don't exist.

- [ ] **Step 4: Implement `FieldDef`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

/** Normalized form field — the single source of truth for render/validation/storage. */
final class FieldDef
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'];

    /** @param list<string> $options */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly ?string $placeholder,
        public readonly ?string $help,
        public readonly array $options,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type,
            'required' => $this->required, 'placeholder' => $this->placeholder,
            'help' => $this->help, 'options' => $this->options];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['key'], (string) $a['label'], (string) $a['type'],
            (bool) ($a['required'] ?? false),
            isset($a['placeholder']) ? (string) $a['placeholder'] : null,
            isset($a['help']) ? (string) $a['help'] : null,
            array_values(array_map('strval', (array) ($a['options'] ?? []))),
        );
    }
}
```

- [ ] **Step 5: Implement `FormSourceIdentity`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

/** Source-scoped identity for form_key (spec §5): first match wins, deterministic tail. */
final class FormSourceIdentity
{
    /** @param array<string,mixed>|null $entry */
    public static function resolve(?array $entry, ?string $regionSlug, ?string $currentPath): string
    {
        if (is_array($entry) && is_string($entry['uuid'] ?? null) && $entry['uuid'] !== '') {
            return 'entry:' . $entry['uuid'];
        }
        if (is_string($regionSlug) && $regionSlug !== '') {
            return 'region:' . $regionSlug;
        }
        if (is_string($currentPath) && $currentPath !== '') {
            return 'route:' . $currentPath;
        }
        return 'theme:path:/'; // deterministic final fallback (spec §5)
    }
}
```

- [ ] **Step 6: Implement `FormDescriptor`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

/** The sealed payload (spec §4). form_key groups submissions; recipient never leaves the seal. */
final class FormDescriptor
{
    public const VERSION = 1;

    /** @param list<FieldDef> $fields */
    public function __construct(
        public readonly int $v,
        public readonly string $formKey,
        public readonly string $formName,
        public readonly array $fields,
        public readonly string $recipient,
        public readonly string $successMessage,
        public readonly ?string $redirectUrl,
        public readonly string $honeypotField,
        public readonly int $minSeconds,
        public readonly int $spamVersion,
        public readonly int $issuedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['v' => $this->v, 'form_key' => $this->formKey, 'form_name' => $this->formName,
            'fields' => array_map(static fn (FieldDef $f) => $f->toArray(), $this->fields),
            'recipient' => $this->recipient, 'success_message' => $this->successMessage,
            'redirect_url' => $this->redirectUrl, 'honeypot_field' => $this->honeypotField,
            'min_seconds' => $this->minSeconds, 'spam_version' => $this->spamVersion,
            'issued_at' => $this->issuedAt];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['v'], (string) $a['form_key'], (string) $a['form_name'],
            array_map(static fn ($f) => FieldDef::fromArray((array) $f), (array) $a['fields']),
            (string) $a['recipient'], (string) $a['success_message'],
            isset($a['redirect_url']) && $a['redirect_url'] !== null ? (string) $a['redirect_url'] : null,
            (string) $a['honeypot_field'], (int) $a['min_seconds'], (int) $a['spam_version'],
            (int) $a['issued_at'],
        );
    }
}
```

- [ ] **Step 7: Implement `FormSealer` interface (contracts)**

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Content;

/**
 * Seals a `form` block into an encrypted+authenticated descriptor token (spec §4).
 * The render pack calls seal() while rendering; the submit endpoint calls open().
 * A null seal() means the form is un-routable/underivable — render a disabled notice.
 */
interface FormSealer
{
    /**
     * Derive + seal a `form` block in ONE pass, returning a SealedForm {token, descriptor}
     * so the renderer reads fields/honeypot/key from the SAME result — never re-opening the
     * encrypted token in the render path. Null when un-routable/underivable (disabled notice).
     *
     * @param array<string,mixed> $block  The block instance {id,type,data}.
     * @param array<string,mixed>|null $entry
     */
    public function describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug): ?object; // SealedForm

    /** Open a token at submit time. Null when tampered, malformed, or expired. */
    public function open(string $token): ?object; // FormDescriptor (app VO; contracts stays app-free)
}
```

- [ ] **Step 8: Implement `SealedForm` + `DefaultFormSealer`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

/** One-pass render result: the sealed token plus the descriptor it sealed (spec §4/§6). */
final class SealedForm
{
    public function __construct(
        public readonly string $token,
        public readonly FormDescriptor $descriptor,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

use Glueful\Encryption\EncryptionService;
use Thallo\Contracts\Content\FormSealer;

final class DefaultFormSealer implements FormSealer
{
    private const AAD = 'form.descriptor';

    /** @param callable(array<string,mixed>):list<FieldDef> $derive */
    public function __construct(
        private readonly EncryptionService $encryption,
        private $derive,
        private readonly int $cacheTtl,
        private readonly int $maxAge,
        private readonly int $buffer,
        private readonly string $defaultRecipient,
        private readonly int $minSeconds,
    ) {
    }

    public function describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug): ?SealedForm
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $recipient = $this->resolveRecipient($data);
        if ($recipient === null) {
            return null; // un-routable → no descriptor (spec §4)
        }
        $fields = ($this->derive)($data);
        if ($fields === []) {
            return null;
        }
        $redirect = $this->safeRedirect(is_string($data['redirect_url'] ?? null) ? $data['redirect_url'] : null);
        $blockId = is_string($block['id'] ?? null) ? $block['id'] : 'anon';
        $source = FormSourceIdentity::resolve($entry, $regionSlug, $currentPath);
        $issued = time();

        $descriptor = new FormDescriptor(
            v: FormDescriptor::VERSION,
            formKey: hash('sha256', $source . '|' . $blockId),
            formName: is_string($data['form_name'] ?? null) && $data['form_name'] !== '' ? $data['form_name'] : 'Form',
            fields: $fields,
            recipient: $recipient,
            successMessage: is_string($data['success_message'] ?? null) && $data['success_message'] !== ''
                ? $data['success_message'] : 'Thanks — your message has been sent.',
            redirectUrl: $redirect,
            honeypotField: 'website_' . substr(hash('sha256', $blockId . $source), 0, 8),
            minSeconds: $this->minSeconds, // real config dependency — time-trap armed at seal time
            spamVersion: 1,
            issuedAt: $issued,
        );

        $token = $this->encryption->encrypt(
            (string) json_encode($descriptor->toArray() + ['exp' => $issued + $this->lifetime()]),
            aad: self::AAD,
        );
        return new SealedForm($token, $descriptor); // render reads the descriptor here — no re-open
    }

    public function open(string $token): ?object
    {
        if ($token === '' || !$this->encryption->isEncrypted($token)) {
            return null;
        }
        try {
            $json = $this->encryption->decrypt($token, aad: self::AAD);
        } catch (\Throwable) {
            return null; // tamper / wrong key
        }
        $a = json_decode($json, true);
        if (!is_array($a) || (int) ($a['exp'] ?? 0) < time()) {
            return null; // malformed or expired
        }
        return FormDescriptor::fromArray($a);
    }

    private function resolveRecipient(array $data): ?string
    {
        $candidate = is_string($data['recipient'] ?? null) && $data['recipient'] !== ''
            ? $data['recipient'] : $this->defaultRecipient;
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }

    /**
     * ROOT-RELATIVE internal URLs only (spec §6, P1) — a single leading slash, not
     * protocol-relative ("//host"). Rejects schemes, hosts, bare relatives
     * ("contact/thanks"), query-only ("?thanks=1") and fragment-only ("#thanks").
     * This is deliberately the strictest safe set; never an open redirect.
     */
    private function safeRedirect(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#\A/(?!/)[^\s]*\z#', $url) === 1) {
            return $url;
        }
        return null;
    }

    private function lifetime(): int
    {
        return max($this->maxAge, $this->cacheTtl + $this->buffer);
    }
}
```

> **`minSeconds` is a real dependency** (not patched later): it is a constructor param, written straight into the descriptor, so the time-trap is armed on every sealed form from Task 1 onward. The binding (Step 9) passes `config('forms.min_seconds')`; the test factory passes `2` and `FormSealerTest` asserts it.

- [ ] **Step 9: Register `FormSealer` binding in `ThalloServiceProvider`.** Bind `FormSealer::class` → factory building `DefaultFormSealer` with `EncryptionService`, the real `FormFieldDerivation::derive(...)` (Task 2), `config('render...ttl', 3600)`, and `config('forms.*')`. Until Task 2 lands, bind with the in-test-style closure returning `[]` guarded so seal returns null (keeps the app booting).

- [ ] **Step 10: Run tests + phpcs**

Run: `composer test:phpunit -- --filter=FormSealerTest && composer phpcs`
Expected: PASS, no style errors.

- [ ] **Step 11: Commit marker** (HOLD — do not commit yet): `feat(forms): sealed form descriptor + FormSealer contract`

---

## Task 2: `form` block schema + contact preset + field derivation

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`
- Create: `app/Content/Forms/FormFieldDerivation.php`
- Test: `tests/Integration/Forms/FormFieldDerivationTest.php`

**Interfaces:**
- Consumes: `FieldDef` (Task 1).
- Produces: `FormFieldDerivation::derive(array $data): list<FieldDef>` — the callable `DefaultFormSealer` uses.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Content\Forms\FormFieldDerivation;
use PHPUnit\Framework\TestCase;

final class FormFieldDerivationTest extends TestCase
{
    public function testCoreContactFieldsAlwaysPresent(): void
    {
        $fields = FormFieldDerivation::derive([]);
        self::assertSame(['name', 'email', 'message'], array_map(fn ($f) => $f->key, $fields));
        self::assertSame('email', $fields[1]->type);
        self::assertTrue($fields[1]->required);
        self::assertSame('textarea', $fields[2]->type);
    }

    public function testTogglesAddOptionalFields(): void
    {
        $fields = FormFieldDerivation::derive([
            'include_subject' => true, 'include_phone' => true, 'include_consent' => true,
            'consent_text' => 'I agree',
        ]);
        $keys = array_map(fn ($f) => $f->key, $fields);
        self::assertSame(['name', 'subject', 'email', 'phone', 'message', 'consent'], $keys);
        $consent = end($fields);
        self::assertSame('checkbox', $consent->type);
        self::assertSame('I agree', $consent->label);
        self::assertTrue($consent->required);
    }

    public function testLabelOverridesApply(): void
    {
        $fields = FormFieldDerivation::derive(['name_label' => 'Your name', 'email_placeholder' => 'you@co']);
        self::assertSame('Your name', $fields[0]->label);
        self::assertSame('you@co', $fields[1]->placeholder);
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `composer test:phpunit -- --filter=FormFieldDerivationTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `FormFieldDerivation`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

/**
 * Derives the normalized field list (spec §3) from the contact-preset block config.
 * v1 has no field-builder: the field set is fixed core + toggled optionals, but the
 * OUTPUT is the same normalized shape a future builder will edit directly.
 */
final class FormFieldDerivation
{
    /** @param array<string,mixed> $data @return list<FieldDef> */
    public static function derive(array $data): array
    {
        $s = static fn (string $k, string $d): string => is_string($data[$k] ?? null) && $data[$k] !== '' ? $data[$k] : $d;
        $on = static fn (string $k): bool => (bool) ($data[$k] ?? false);

        $fields = [];
        $fields[] = new FieldDef('name', $s('name_label', 'Name'), 'text', true, $s('name_placeholder', ''), null, []);
        if ($on('include_subject')) {
            $fields[] = new FieldDef('subject', $s('subject_label', 'Subject'), 'text', false, $s('subject_placeholder', ''), null, []);
        }
        $fields[] = new FieldDef('email', $s('email_label', 'Email'), 'email', true, $s('email_placeholder', ''), null, []);
        if ($on('include_phone')) {
            $fields[] = new FieldDef('phone', $s('phone_label', 'Phone'), 'tel', $on('phone_required'), $s('phone_placeholder', ''), null, []);
        }
        $fields[] = new FieldDef('message', $s('message_label', 'Message'), 'textarea', true, $s('message_placeholder', ''), null, []);
        if ($on('include_consent')) {
            $fields[] = new FieldDef('consent', $s('consent_text', 'I agree to be contacted'), 'checkbox', true, null, null, []);
        }
        return $fields;
    }
}
```

- [ ] **Step 4: Add the `form` block type to `StarterBlockTypes::definitions()`**

Insert a new entry (Content category), following the array shape of existing entries:

```php
['slug' => 'form', 'label' => 'Form', 'icon' => 'i-lucide-mail',
    'category' => 'Content',
    'description' => 'A contact form: collects submissions, emails a recipient, and stores them in the admin.',
    'schema' => [
        ['name' => 'form_name', 'type' => 'string'],
        ['name' => 'recipient', 'type' => 'string', 'group' => 'Delivery'],
        ['name' => 'success_message', 'type' => 'text', 'group' => 'Delivery'],
        ['name' => 'redirect_url', 'type' => 'string', 'group' => 'Delivery'],
        ['name' => 'submit_label', 'type' => 'string', 'group' => 'Form'],
        ['name' => 'heading', 'type' => 'string', 'group' => 'Form'],
        ['name' => 'intro', 'type' => 'text', 'group' => 'Form'],
        ['name' => 'name_label', 'type' => 'string', 'group' => 'Fields'],
        ['name' => 'email_label', 'type' => 'string', 'group' => 'Fields'],
        ['name' => 'message_label', 'type' => 'string', 'group' => 'Fields'],
        ['name' => 'include_subject', 'type' => 'boolean', 'group' => 'Fields'],
        ['name' => 'subject_label', 'type' => 'string', 'group' => 'Fields'],
        ['name' => 'include_phone', 'type' => 'boolean', 'group' => 'Fields'],
        ['name' => 'phone_label', 'type' => 'string', 'group' => 'Fields'],
        ['name' => 'phone_required', 'type' => 'boolean', 'group' => 'Fields'],
        ['name' => 'include_consent', 'type' => 'boolean', 'group' => 'Fields'],
        ['name' => 'consent_text', 'type' => 'string', 'group' => 'Fields'],
    ]],
```

- [ ] **Step 5: Wire the real derivation into the `FormSealer` binding** (from Task 1 Step 9): replace the placeholder closure with `FormFieldDerivation::derive(...)`.

- [ ] **Step 6: Run tests**

Run: `composer test:phpunit -- --filter='FormFieldDerivation|FormSealer' && composer phpcs`
Expected: PASS.

- [ ] **Step 7: Seed the block type into the dev DB**

Run: `php glueful thallo:blocks:seed` — **seed** creates the new `form` row (`sync` is additive-schema-only for *existing* starter types and would not insert a new one). Verify: the `form` type appears active in Settings → Block types.

- [ ] **Step 8: Commit marker** (HOLD): `feat(forms): form block type + contact-preset field derivation`

---

## Task 3: `form.twig` render + `form_render()` Twig function + disabled notice + styles

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php`
- Create: `packages/thallo-render/themes/default/templates/blocks/form.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css`
- Modify: `packages/thallo-render/themes/default/assets/blocks.js`
- Test: `tests/Integration/Render/FormBlockRenderTest.php`

**Interfaces:**
- Consumes: `FormSealer::describe()` (Task 1) via a nullable constructor dependency on `RenderContextExtension`.
- Produces: **one** Twig function `form_render(block)` → a render-ready array `{token, fields, honeypot, key, heading, intro, submit_label, success_message}` or `null`. It derives+seals **once** (via `describe()`) and returns everything the template needs, so there is no re-opening of the encrypted token in the render path and no extra helper functions on the sandbox surface. Gated to `block.type === 'form'` (returns `null` otherwise). `entry`/`current_path`/`region_slug` come from the block render context.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Render;

use Tests\Support\AppTestCase;

final class FormBlockRenderTest extends AppTestCase
{
    public function testRoutableFormRendersSealedInputAndNoJsAction(): void
    {
        $html = $this->renderBlocks([[
            'id' => 'f1', 'type' => 'form',
            'data' => ['recipient' => 'owner@site.test', 'form_name' => 'Contact'],
        ]], currentPath: '/contact');

        self::assertStringContainsString('name="_form"', $html);
        self::assertStringContainsString('action="/_forms/submit"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('type="email"', $html); // derived email field
        self::assertStringNotContainsString('owner@site.test', $html); // recipient never in markup
    }

    public function testUnroutableFormRendersDisabledNotice(): void
    {
        $html = $this->renderBlocks([[
            'id' => 'f2', 'type' => 'form', 'data' => [], // no recipient, no default
        ]], currentPath: '/c');

        self::assertStringContainsString('thallo-block-form--disabled', $html);
        self::assertStringNotContainsString('name="_form"', $html);
    }

    public function testFormRenderIsGatedToFormBlocks(): void
    {
        // Defense in depth: even called directly, form_render only seals `form` blocks.
        $ext = $this->getService(\Thallo\Render\RenderContextExtension::class);
        self::assertNull($ext->formRender(['current_path' => '/c'], ['id' => 'x', 'type' => 'image', 'data' => ['recipient' => 'a@b.test']]));
    }
}
```

(`renderBlocks()` helper: render an array through the `blocks()` Twig function against the default theme — mirror the existing render-test harness in `tests/Integration/Render/RenderPipelineTest.php`.)

- [ ] **Step 2: Run it, expect failure**

Run: `composer test:phpunit -- --filter=FormBlockRenderTest`
Expected: FAIL — no `form.twig`, no `form_render` function.

- [ ] **Step 3: Add the single `form_render` Twig function to `RenderContextExtension`**

- Add `private readonly ?FormSealer $formSealer = null` to the constructor (nullable so the extension still builds when forms are unavailable).
- Register **one** function in `getFunctions()`:

```php
new TwigFunction('form_render', $this->formRender(...), ['needs_context' => true]),
```

- Implement — derive+seal ONCE via `describe()`, read the descriptor straight off the `SealedForm`, return a render array. No re-open; gated to `form` blocks:

```php
/**
 * Render payload for a `form` block (spec §4/§6): one derive+seal pass. Null =>
 * not a form block, un-routable, or forms unavailable => the template renders the
 * disabled notice. needs_context to read entry/current_path/region_slug.
 *
 * @param array<string,mixed> $context
 * @param array<string,mixed> $block
 * @return array<string,mixed>|null
 */
public function formRender(array $context, array $block): ?array
{
    if ($this->formSealer === null || ($block['type'] ?? null) !== 'form') {
        return null; // gated: only the form block may seal a descriptor
    }
    $entry = is_array($context['entry'] ?? null) ? $context['entry'] : null;
    $path = is_string($context['current_path'] ?? null) ? $context['current_path'] : null;
    $region = is_string($context['region_slug'] ?? null) ? $context['region_slug'] : null;
    $sealed = $this->formSealer->describe($block, $entry, $path, $region);
    if ($sealed === null) {
        return null; // un-routable / underivable → disabled notice
    }
    $d = $sealed->descriptor;
    $data = is_array($block['data'] ?? null) ? $block['data'] : [];
    return [
        'token' => $sealed->token,
        'key' => $d->formKey,
        'honeypot' => $d->honeypotField,
        'fields' => array_map(static fn (FieldDef $f) => $f->toArray(), $d->fields),
        'heading' => $data['heading'] ?? null,
        'intro' => $data['intro'] ?? null,
        'submit_label' => is_string($data['submit_label'] ?? null) && $data['submit_label'] !== '' ? $data['submit_label'] : 'Send',
        'success_message' => $d->successMessage,
    ];
}
```

- Update `RenderServiceProvider` factory to pass the container's `FormSealer` (nullable — resolve via `has()`/try) into `RenderContextExtension`.
- Add **only** `'form_render'` to the `TemplatePolicy` allowed function list. (No `form_descriptor`/`form_fields`/`form_honeypot`/`form_key` — the render array is the single, narrow surface.)

- [ ] **Step 4: Write `form.twig`**

```twig
{# form block (form-block spec): sealed-descriptor contact form. form_render()
   derives+seals ONCE and returns everything the template needs; null (not a form,
   un-routable, or forms unavailable) renders the disabled notice. The descriptor
   hides recipient/config and is the ONLY validation source server-side. #}
{% set f = form_render(block) %}
{% if f is null %}
  <div class="thallo-block thallo-block-form thallo-block-form--disabled">
    <p>This form isn’t configured yet — set a recipient email to activate it.</p>
  </div>
{% else %}
  <form class="thallo-block thallo-block-form" method="post" action="/_forms/submit"
        data-thallo-form data-form-key="{{ f.key }}">
    {% if f.heading %}<h2 class="thallo-block-form__heading">{{ f.heading }}</h2>{% endif %}
    {% if f.intro %}<p class="thallo-block-form__intro">{{ f.intro }}</p>{% endif %}
    <input type="hidden" name="_form" value="{{ f.token }}">
    <input type="hidden" name="_return" value="{{ current_path|default('/') }}">
    <input type="hidden" name="_t" value="{{ 'now'|date('U') }}">
    {# Honeypot: present in markup by necessity — value is behavioral, not secret. #}
    <div class="thallo-block-form__hp" aria-hidden="true">
      <label>{{ f.honeypot }}<input type="text" name="{{ f.honeypot }}" tabindex="-1" autocomplete="off"></label>
    </div>
    {% for field in f.fields %}
      <div class="thallo-block-form__field">
        <label for="ff-{{ field.key }}">{{ field.label }}{% if field.required %} <span aria-hidden="true">*</span>{% endif %}</label>
        {% if field.type == 'textarea' %}
          <textarea id="ff-{{ field.key }}" name="{{ field.key }}" {% if field.required %}required{% endif %} placeholder="{{ field.placeholder }}"></textarea>
        {% elseif field.type == 'checkbox' %}
          <input id="ff-{{ field.key }}" type="checkbox" name="{{ field.key }}" {% if field.required %}required{% endif %}>
        {% else %}
          <input id="ff-{{ field.key }}" type="{{ field.type }}" name="{{ field.key }}" {% if field.required %}required{% endif %} placeholder="{{ field.placeholder }}">
        {% endif %}
      </div>
    {% endfor %}
    <div class="thallo-block-form__result" role="status" aria-live="polite"></div>
    <button type="submit" class="thallo-block-form__submit">{{ f.submit_label }}</button>
  </form>
{% endif %}
```

- [ ] **Step 5: Add styles to `blocks.css`**

```css
.thallo-block-form { display: grid; gap: 1rem; max-width: 34rem; }
.thallo-block-form__field { display: grid; gap: 0.35rem; }
.thallo-block-form__field input, .thallo-block-form__field textarea {
  width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--line); border-radius: var(--radius);
  background: var(--bg); color: var(--ink); font: inherit;
}
.thallo-block-form__field textarea { min-height: 8rem; resize: vertical; }
.thallo-block-form__submit {
  justify-self: start; padding: 0.7rem 1.2rem; border: 0; border-radius: var(--radius);
  background: var(--accent); color: var(--accent-ink); font-weight: 600; cursor: pointer;
}
.thallo-block-form__hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.thallo-block-form__result:empty { display: none; }
.thallo-block-form__result { padding: 0.6rem 0.75rem; border-radius: var(--radius); }
.thallo-block-form--disabled { padding: 1rem; border: 1px dashed var(--line); border-radius: var(--radius); color: var(--muted); }
```

- [ ] **Step 6: Add progressive-enhancement submit to `blocks.js`** (intercept `[data-thallo-form]`, POST via fetch, render inline result — see Task 6 for the JSON contract). Keep the no-JS action intact.

```js
document.querySelectorAll('form[data-thallo-form]').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const result = form.querySelector('.thallo-block-form__result');
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const json = await res.json();
      if (json.ok) { form.reset(); result.textContent = json.message || 'Thanks — your message has been sent.'; }
      else { result.textContent = json.error || 'Please check your entries and try again.'; }
    } catch { result.textContent = 'Something went wrong. Please try again.'; }
  });
});
```

- [ ] **Step 7: Run tests**

Run: `composer test:phpunit -- --filter=FormBlockRenderTest && composer phpcs`
Expected: PASS.

- [ ] **Step 8: Clear render cache to see it live:** `php glueful render:cache:clear`.

- [ ] **Step 9: Commit marker** (HOLD): `feat(forms): form.twig render + single form_render twig function`

---

## Task 4: `form_submissions` migration + repository + value normalization

**Files:**
- Create: `database/migrations/012_CreateFormSubmissionsTable.php`
- Create: `app/Content/Forms/FormSubmission.php`
- Create: `app/Content/Forms/FormSubmissionRepository.php`
- Create: `app/Content/Forms/FormValueNormalizer.php`
- Test: `tests/Integration/Forms/FormSubmissionRepositoryTest.php`
- Test: `tests/Integration/Forms/FormValueNormalizerTest.php`

**Interfaces:**
- Consumes: `FieldDef` (Task 1).
- Produces:
  - `FormValueNormalizer::normalize(array $fields /* FieldDef[] */, array $raw): array{values: array<string,mixed>, errors: array<string,string>}`.
  - `FormSubmissionRepository::store(FormSubmission $s): string` (returns uuid); `list(array $filter): array`; `find(string $uuid): ?FormSubmission`; `markRead(string $uuid): void`; `delete(string $uuid): void`; `unreadCount(): int`; `export(array $filter): iterable`.

- [ ] **Step 1: Write the failing normalizer test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Content\Forms\FieldDef;
use App\Content\Forms\FormValueNormalizer;
use PHPUnit\Framework\TestCase;

final class FormValueNormalizerTest extends TestCase
{
    /** @return list<FieldDef> */
    private function fields(): array
    {
        return [
            new FieldDef('email', 'Email', 'email', true, null, null, []),
            new FieldDef('message', 'Message', 'textarea', true, null, null, []),
            new FieldDef('consent', 'I agree', 'checkbox', true, null, null, []),
        ];
    }

    public function testValidatesAndNormalizes(): void
    {
        $out = FormValueNormalizer::normalize($this->fields(), [
            'email' => '  Owner@Site.test ', 'message' => 'hi', 'consent' => 'on',
            'evil' => 'ignored', // unknown key dropped
        ]);
        self::assertSame([], $out['errors']);
        self::assertSame('Owner@Site.test', $out['values']['email']); // trimmed
        self::assertTrue($out['values']['consent']);                   // checkbox → bool
        self::assertArrayNotHasKey('evil', $out['values']);            // not against sealed fields
    }

    public function testRequiredAndFormatErrors(): void
    {
        $out = FormValueNormalizer::normalize($this->fields(), ['email' => 'nope', 'message' => '']);
        self::assertArrayHasKey('email', $out['errors']); // bad format
        self::assertArrayHasKey('message', $out['errors']); // required empty
        self::assertArrayHasKey('consent', $out['errors']); // required checkbox unchecked
    }
}
```

- [ ] **Step 2: Run it, expect failure.** `composer test:phpunit -- --filter=FormValueNormalizerTest` → FAIL.

- [ ] **Step 3: Implement `FormValueNormalizer`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms;

final class FormValueNormalizer
{
    /**
     * @param list<FieldDef> $fields @param array<string,mixed> $raw
     * @return array{values: array<string,mixed>, errors: array<string,string>}
     */
    public static function normalize(array $fields, array $raw): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $f) {
            $in = $raw[$f->key] ?? null;
            if ($f->type === 'checkbox') {
                $checked = $in !== null && $in !== '' && $in !== '0' && $in !== false;
                if ($f->required && !$checked) { $errors[$f->key] = 'Required.'; }
                $values[$f->key] = $checked;
                continue;
            }
            $val = is_string($in) ? trim($in) : '';
            if ($val === '') {
                if ($f->required) { $errors[$f->key] = 'Required.'; }
                $values[$f->key] = '';
                continue;
            }
            if ($f->type === 'email' && filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$f->key] = 'Enter a valid email address.';
            }
            if ($f->type === 'select' && $f->options !== [] && !in_array($val, $f->options, true)) {
                $errors[$f->key] = 'Choose a valid option.';
            }
            if (mb_strlen($val) > 5000) { $errors[$f->key] = 'Too long.'; }
            $values[$f->key] = $val;
        }
        return ['values' => $values, 'errors' => $errors];
    }
}
```

- [ ] **Step 4: Run it, expect PASS.** `composer test:phpunit -- --filter=FormValueNormalizerTest`.

- [ ] **Step 5: Write the migration** (mirror `database/migrations/006_CreateEntryRoutesTable.php` for the SchemaBuilder shape)

```php
<?php

declare(strict_types=1);

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

return new class {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('form_submissions')) {
            return;
        }
        $schema->createTable('form_submissions', function ($table): void {
            $table->uuid('uuid')->primary();
            $table->string('form_key', 191);
            $table->string('form_name', 255);
            $table->string('source_url', 1024)->nullable();
            $table->json('fields_snapshot');
            $table->json('values');
            $table->integer('descriptor_version')->default(1);
            $table->string('status', 16)->default('unread');
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('submitted_at');
            $table->index('form_key');
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('form_submissions');
    }
};
```

- [ ] **Step 6: Apply the migration to the dev/test DBs**

Run: `php glueful migrate:run` (dev) and rebuild the test DB per the harness (`composer test:reset-db && composer test:migrate` if that is the project convention).

- [ ] **Step 7: Write the repository test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Content\Forms\FormSubmission;
use App\Content\Forms\FormSubmissionRepository;
use Tests\Support\AppTestCase;

final class FormSubmissionRepositoryTest extends AppTestCase
{
    private function repo(): FormSubmissionRepository
    {
        return $this->getService(FormSubmissionRepository::class);
    }

    private function make(string $key = 'k1', string $status = 'unread'): FormSubmission
    {
        return new FormSubmission(
            uuid: '', formKey: $key, formName: 'Contact', sourceUrl: '/contact',
            fieldsSnapshot: [['key' => 'email', 'label' => 'Email', 'type' => 'email']],
            values: ['email' => 'a@b.test'], descriptorVersion: 1, status: $status,
            ip: '127.0.0.1', userAgent: 'test', submittedAt: '2026-07-09 10:00:00',
        );
    }

    public function testStoreListAndUnreadCount(): void
    {
        $uuid = $this->repo()->store($this->make());
        self::assertNotSame('', $uuid);
        self::assertGreaterThanOrEqual(1, $this->repo()->unreadCount());
        $rows = $this->repo()->list(['form_key' => 'k1']);
        self::assertNotEmpty($rows);
    }

    public function testMarkReadAndDelete(): void
    {
        $uuid = $this->repo()->store($this->make());
        $this->repo()->markRead($uuid);
        self::assertSame('read', $this->repo()->find($uuid)->status);
        $this->repo()->delete($uuid);
        self::assertNull($this->repo()->find($uuid));
    }
}
```

- [ ] **Step 8: Run it, expect failure.** `composer test:phpunit -- --filter=FormSubmissionRepositoryTest` → FAIL.

- [ ] **Step 9: Implement `FormSubmission` VO and `FormSubmissionRepository`** (use `$this->db->table('form_submissions')` like `BlockTypeRepository`; JSON-encode `fields_snapshot`/`values`; generate uuid via the framework's uuid helper used elsewhere in `app/Content`). Provide `store/list/find/markRead/delete/unreadCount/export`. `list($filter)` supports `form_key`, `status`, ordered `submitted_at DESC`. `export($filter)` yields rows for CSV.

- [ ] **Step 10: Run tests + phpcs.** `composer test:phpunit -- --filter='FormSubmissionRepository|FormValueNormalizer' && composer phpcs` → PASS.

- [ ] **Step 11: Commit marker** (HOLD): `feat(forms): submissions table, repository, value normalizer`

---

## Task 5: `FormSubmissionGuard` contract + `DefaultFormGuard`

**Files:**
- Create: `app/Content/Forms/Spam/FormSubmissionGuard.php`
- Create: `app/Content/Forms/Spam/GuardResult.php`
- Create: `app/Content/Forms/Spam/DefaultFormGuard.php`
- Test: `tests/Integration/Forms/DefaultFormGuardTest.php`

**Interfaces:**
- Consumes: `FormDescriptor` (Task 1), a rate-limiter (the framework rate-limit store used by `->rateLimit()`; if not directly injectable, implement the rate check against the cache store like other per-key counters in the app).
- Produces:
  - `GuardResult` — `::pass()`, `::reject(string $reason)`, `->passed(): bool`, `->reason(): ?string`.
  - `interface FormSubmissionGuard { public function check(Request $r, FormDescriptor $d): GuardResult; }`.
  - `DefaultFormGuard` composes honeypot + time-trap + rate-limit.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Content\Forms\FieldDef;
use App\Content\Forms\FormDescriptor;
use App\Content\Forms\Spam\DefaultFormGuard;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\AppTestCase;

final class DefaultFormGuardTest extends AppTestCase
{
    private function descriptor(): FormDescriptor
    {
        return new FormDescriptor(1, 'k1', 'Contact', [new FieldDef('email', 'Email', 'email', true, null, null, [])],
            'a@b.test', 'ok', null, honeypotField: 'website_x', minSeconds: 2, spamVersion: 1, issuedAt: time());
    }

    private function guard(): DefaultFormGuard
    {
        return $this->getService(DefaultFormGuard::class);
    }

    public function testHoneypotFilledIsRejected(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['website_x' => 'bot', '_t' => (string) (time() - 10)]);
        self::assertFalse($this->guard()->check($req, $this->descriptor())->passed());
        self::assertSame('honeypot', $this->guard()->check($req, $this->descriptor())->reason());
    }

    public function testTooFastIsRejected(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['_t' => (string) time()]); // 0s elapsed < 2
        self::assertSame('time_trap', $this->guard()->check($req, $this->descriptor())->reason());
    }

    public function testCleanSubmitPasses(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['_t' => (string) (time() - 10)]);
        $req->server->set('REMOTE_ADDR', '10.0.0.99'); // unique IP so rate limit is clean
        self::assertTrue($this->guard()->check($req, $this->descriptor())->passed());
    }
}
```

- [ ] **Step 2: Run it, expect failure.** `composer test:phpunit -- --filter=DefaultFormGuardTest` → FAIL.

- [ ] **Step 3: Implement `GuardResult`, `FormSubmissionGuard`, `DefaultFormGuard`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms\Spam;

final class GuardResult
{
    private function __construct(private readonly bool $passed, private readonly ?string $reason) {}
    public static function pass(): self { return new self(true, null); }
    public static function reject(string $reason): self { return new self(false, $reason); }
    public function passed(): bool { return $this->passed; }
    public function reason(): ?string { return $this->reason; }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Content\Forms\Spam;

use App\Content\Forms\FormDescriptor;
use Symfony\Component\HttpFoundation\Request;

interface FormSubmissionGuard
{
    public function check(Request $request, FormDescriptor $descriptor): GuardResult;
}
```

`DefaultFormGuard`: honeypot (`$request->request->get($descriptor->honeypotField)` non-empty → `reject('honeypot')`); time-trap (`time() - (int) $request->request->get('_t') < $descriptor->minSeconds` → `reject('time_trap')`); rate-limit (increment a cache counter keyed `form:{formKey}:{ip}` with `forms.rate_limit`; over limit → `reject('rate_limit')`). Else `pass()`. Inject the cache store + config; read `min_seconds` from the descriptor (authoritative) not config.

- [ ] **Step 4: Run tests + phpcs.** `composer test:phpunit -- --filter=DefaultFormGuardTest && composer phpcs` → PASS.

- [ ] **Step 5: Commit marker** (HOLD): `feat(forms): submission guard chain (honeypot/time-trap/rate-limit)`

---

## Task 6: `POST /_forms/submit` endpoint (unified AJAX/PRG)

**Files:**
- Create: `app/Http/Controllers/FormSubmitController.php`
- Create: `routes/forms.php`
- Test: `tests/Integration/Forms/FormSubmitEndpointTest.php`

**Interfaces:**
- Consumes: `FormSealer` (open), `DefaultFormGuard`, `FormValueNormalizer`, `FormSubmissionRepository`, `FormNotifier` (Task 7 — inject as a nullable/no-op until Task 7; endpoint calls `$notifier?->notify(...)`).
- Produces: `POST /_forms/submit` returning JSON (`Accept: application/json`) or a PRG redirect.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use Tests\Support\AppTestCase;

final class FormSubmitEndpointTest extends AppTestCase
{
    public function testValidSubmitStoresAndReturnsJsonSuccess(): void
    {
        $token = $this->sealContactForm('owner@site.test');       // helper seals a real descriptor
        $res = $this->postForm(['_form' => $token, '_t' => (string) (time() - 5),
            'name' => 'Ada', 'email' => 'ada@x.test', 'message' => 'hello'], json: true);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->json($res)['ok']);
        self::assertSame(1, $this->countSubmissions('ada@x.test'));
    }

    public function testValidationErrorReturnsFieldErrorsJson(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm(['_form' => $token, '_t' => (string) (time() - 5),
            'name' => '', 'email' => 'bad', 'message' => ''], json: true);
        $body = $this->json($res);
        self::assertFalse($body['ok']);
        self::assertArrayHasKey('email', $body['errors']);
    }

    public function testSpamRejectReturnsGenericSuccessAndStoresNothing(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm(['_form' => $token, '_t' => (string) time(), // too fast
            'name' => 'X', 'email' => 'x@y.test', 'message' => 'hi'], json: true);
        self::assertTrue($this->json($res)['ok']);          // generic success — bots learn nothing
        self::assertSame(0, $this->countSubmissions('x@y.test'));
    }

    public function testNoJsPrgRedirectsBackWithSuccessFlag(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm(['_form' => $token, '_return' => '/contact', '_t' => (string) (time() - 5),
            'name' => 'A', 'email' => 'a@x.test', 'message' => 'hi'], json: false);
        self::assertSame(303, $res->getStatusCode());
        self::assertStringContainsString('/contact?form_ok=', (string) $res->headers->get('Location'));
    }

    public function testExpiredDescriptorReturnsReloadMessage(): void
    {
        $res = $this->postForm(['_form' => $this->expiredToken(), '_t' => (string) (time() - 5)], json: true);
        self::assertFalse($this->json($res)['ok']);
        self::assertStringContainsString('expired', strtolower($this->json($res)['error']));
    }
}
```

(Helpers `sealContactForm/postForm/json/countSubmissions/expiredToken` live in the test or `AppTestCase`; `postForm` sets `Accept: application/json` when `json:true`.)

- [ ] **Step 2: Run it, expect failure.** `composer test:phpunit -- --filter=FormSubmitEndpointTest` → FAIL.

- [ ] **Step 3: Implement `FormSubmitController`**

Flow (spec §7):
1. `$d = $sealer->open($request->request->get('_form'))`. Null → respond expired/invalid (`respond($request, ok:false, error:'This form expired — reload the page and try again.')`).
2. `$guard->check($request, $d)`. Rejected → record reason (log/counter) and respond **generic success** (`respond($request, ok:true, descriptor:$d)`), store nothing.
3. `['values'=>$values,'errors'=>$errors] = FormValueNormalizer::normalize($d->fields, $request->request->all())`. Errors non-empty → respond field errors (AJAX) / PRG back with generic failure flag (no-JS).
4. Build `FormSubmission` (snapshot `fields` labels/types, normalized `values`, `form_key`, `form_name`, `source_url` from `_return`/Referer, ip, ua, `submitted_at`), `store()`.
5. `$notifier?->notify($d, $values, $sourceUrl)` inside try/catch — never fatal.
6. `respond($request, ok:true, descriptor:$d)`.

`respond()` unifies AJAX vs PRG (spec §7a):

```php
private function respond(Request $request, bool $ok, ?FormDescriptor $descriptor, ?array $errors = null, ?string $error = null): Response
{
    $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');
    if ($wantsJson) {
        return $ok
            ? Response::success(['ok' => true, 'message' => $descriptor?->successMessage])
            : Response::success(['ok' => false, 'errors' => $errors ?? [], 'error' => $error ?? 'Please check your entries and try again.']);
    }
    // No-JS PRG. Redirect target: descriptor.redirect_url on success (already safe-url), else _return.
    $return = $this->safeReturn($request->request->get('_return'));
    if ($ok && $descriptor?->redirectUrl !== null) { return Response::redirect($descriptor->redirectUrl, 303); }
    $flag = $ok ? 'form_ok' : 'form_err';
    $key = $descriptor?->formKey ?? '';
    return Response::redirect($return . (str_contains($return, '?') ? '&' : '?') . $flag . '=' . urlencode($key), 303);
}
```

`safeReturn()` applies the same internal-only rule as the sealer's `safeRedirect` (default `/`). Guard reasons are NEVER placed in the no-JS redirect — only the generic `form_err` flag.

- [ ] **Step 4: Write `routes/forms.php`**

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\FormSubmitController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public form submission (form-block spec §7). Reserved '/_forms' prefix (like '/_preview').
// Rate-limited; the sealed descriptor is the authorization + schema. No page cache (POST).
$router->post('/_forms/submit', [FormSubmitController::class, 'submit'])
    ->middleware('rate_limit')
    ->rateLimit(30, 1, by: 'ip');
```

(RouteManifest auto-discovers `routes/*.php`; do NOT `loadRoutesFrom()` it.)

- [ ] **Step 5: Run tests + phpcs.** `composer test:phpunit -- --filter=FormSubmitEndpointTest && composer phpcs` → PASS.

- [ ] **Step 6: Commit marker** (HOLD): `feat(forms): public submit endpoint with unified AJAX/PRG semantics`

---

## Task 7: Best-effort `FormNotifier`

**Files:**
- Create: `app/Content/Forms/FormNotifier.php`
- Modify: `app/Http/Controllers/FormSubmitController.php` (inject + call)
- Test: `tests/Integration/Forms/FormNotifierTest.php`

**Interfaces:**
- Consumes: the framework mailer/notification channel if bound (resolve nullable from the container).
- Produces: `FormNotifier::notify(FormDescriptor $d, array $values, ?string $sourceUrl): void` — best-effort; catches all failures; no-ops when no sender is bound.

- [ ] **Step 1: Write the failing test** — assert `notify()` returns without throwing when no mailer is bound, and that when a fake sender is injected it receives the recipient + formatted body. (Use a test double for the sender contract; assert stored submission is unaffected by a throwing sender.)

- [ ] **Step 2: Run it, expect failure.**

- [ ] **Step 3: Implement `FormNotifier`** — format subject (`New {form_name} submission`) + body (label: value lines + source URL); send via the bound sender inside try/catch; log-and-swallow on failure; return early when unbound. Re-validate `$d->recipient` with `filter_var(...EMAIL)` before sending.

- [ ] **Step 4: Wire into `FormSubmitController`** (replace the nullable call from Task 6 with the real injected `FormNotifier`).

- [ ] **Step 5: Run tests + phpcs.** `composer test:phpunit -- --filter='FormNotifier|FormSubmitEndpoint' && composer phpcs` → PASS.

- [ ] **Step 6: Commit marker** (HOLD): `feat(forms): best-effort email notification`

---

## Task 8: Admin Submissions area (backend + Vue page + unread badge)

**Files:**
- Create: `app/Http/Controllers/FormSubmissionsController.php`
- Modify: `routes/admin.php`
- Create: `admin/src/queries/formSubmissions.ts`
- Create: `admin/src/pages/submissions/index.vue`
- Modify: admin nav/router config (register route + unread badge)
- Test: `tests/Integration/Forms/FormSubmissionsAdminTest.php`
- Test: `admin/src/__tests__/formSubmissionsQueries.spec.ts`
- Test: `admin/src/__tests__/submissionsPage.spec.ts`

**Interfaces:**
- Consumes: `FormSubmissionRepository` (Task 4).
- Produces: admin routes `GET /v1/admin/form-submissions`, `GET /v1/admin/form-submissions/{uuid}`, `PATCH .../{uuid}/read`, `DELETE .../{uuid}`, `GET .../export.csv`, `GET .../unread-count`.

- [ ] **Step 1: Write the failing backend test** — seed submissions via the repository; assert list filters by `form_key`/`status`, detail returns one, `read` flips status, `delete` removes, `export.csv` returns `text/csv` with a header row + metadata columns (`submitted_at, form_name, source_url, ip, user_agent` + field values), `unread-count` returns the count. Gate: requires `content.manage`.

- [ ] **Step 2: Run it, expect failure.** `composer test:phpunit -- --filter=FormSubmissionsAdminTest` → FAIL.

- [ ] **Step 3: Implement `FormSubmissionsController`** — plain `final class`, constructor-inject `ApplicationContext` + `FormSubmissionRepository`; methods `index/show/read/destroy/export/unreadCount`; `#[ApiOperation]`/`#[ApiResponse]` attributes like `RegionAdminController`; CSV built from `repository->export($filter)` (union of metadata + all field keys seen). Return `Response::success(...)`; CSV via a streamed `Response` with `Content-Type: text/csv` + `Content-Disposition: attachment`.

- [ ] **Step 4: Register admin routes** in `routes/admin.php` under the existing admin group (prefix `/v1/admin`, `content.manage` middleware), following the region/nav route style.

- [ ] **Step 5: Run backend tests + phpcs.** `composer test:phpunit -- --filter=FormSubmissionsAdminTest && composer phpcs` → PASS.

- [ ] **Step 6: Write the admin query-layer test** (`formSubmissionsQueries.spec.ts`) — mirror `navigationQueries.spec.ts`: assert `fetchSubmissions` hits `/v1/admin/form-submissions` with filters, `markRead` PATCHes, `deleteSubmission` DELETEs, `exportUrl` builds the CSV URL, `unreadCount` GETs.

- [ ] **Step 7: Implement `admin/src/queries/formSubmissions.ts`** — `useSubmissions(filter)`, `useSubmission(uuid)`, `useSubmissionMutations()` (`markRead`, `remove`), `useUnreadCount()`, `submissionsExportUrl(filter)`; typed `SubmissionSummary`/`SubmissionDetail`; `onSettled: invalidate ['form-submissions']`. Follow `queries/navigation.ts` patterns.

- [ ] **Step 8: Run query test.** `pnpm --dir admin test -- --run formSubmissionsQueries` → PASS.

- [ ] **Step 9: Write the page test** (`submissionsPage.spec.ts`) — mirror `navigationPage.spec.ts` harness (real @nuxt/ui, RouterLink stub). Assert: list renders rows, form filter changes the query, clicking a row opens detail + marks read, delete calls the mutation, an Export button links to the CSV URL, unread rows are visually flagged.

- [ ] **Step 10: Implement `admin/src/pages/submissions/index.vue`** — `UDashboardPanel` master-detail (list + detail pane), form filter `USelect`, read/unread styling, delete with confirm (reuse the pattern from `navigation/index.vue`), Export button (`<a :href="submissionsExportUrl(filter)">`). Register the route (file-based routing) and add a sidebar nav item with an **unread-count badge** bound to `useUnreadCount()`.

- [ ] **Step 11: Run admin gate.** `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint` → all green.

- [ ] **Step 12: Commit marker** (HOLD): `feat(forms): admin Submissions area with CSV export`

---

## Final verification (before requesting go-ahead to commit)

- [ ] Backend full: `composer test:phpunit` (all green) + `composer phpcs`.
- [ ] Admin full: `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint`.
- [ ] Manual smoke: add a `form` block to a page with a recipient, load the page (`render:cache:clear` first), submit with and without JS, confirm a stored submission + best-effort email attempt + the admin Submissions row, unread badge, and CSV export.
- [ ] Confirm CHANGELOG `[Unreleased]` updated for the feature.
- [ ] Report status and await explicit go-ahead before committing (batch commits at the task groupings above).

---

## Self-review notes

- **Spec coverage:** §1 arch → Tasks 1/3/6; §2 block+preset → Task 2; §3 field model → Tasks 1/2; §4 descriptor (seal/open, expiry `max()`, integrity-not-secrecy, un-routable refuse) → Task 1 + Task 3 (disabled notice); §5 form_key + fallback order → Task 1; §6 render + redirect safe-url → Tasks 1/3/6; §7 endpoint + §7a unified semantics → Task 6; §8 guard → Task 5; §9/§9a storage + normalization → Task 4; §10 email → Task 7; §11 admin + CSV → Task 8; §12 config → Task 1; §13 testing → each task's tests. All covered.
- **Type consistency:** `FieldDef`, `FormDescriptor`, `FormSealer::seal/open`, `FormSubmissionRepository` method names, and the `respond()`/guard `reason()` shapes are used consistently across Tasks 1–8.
- **Deferred to spec §14 (not in this plan):** field-builder UI, forms registry (Approach B), CAPTCHA, file uploads.
