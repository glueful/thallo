# Typed Response DTOs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the framework OpenAPI generator emit Lemma's response shapes accurately via doc-only `ResponseData` DTOs, so `scripts/openapi-finalize-security.php` can be deleted.

**Architecture:** Two phases across two repos. **Phase A (framework)** adds two additive generator fixes (envelope `required` keys; `#[ArrayOf]` honored in response mode) and releases them as a patch. **Phase B (lemma)** adds doc-only `ResponseData` schema-holder DTOs, annotates `#[ApiResponse(2xx, X::class)]`, guards drift with integration tests, then deletes the script. Controllers are NOT changed — DTOs describe the existing raw payloads.

**Tech Stack:** PHP 8.3, PHPUnit 10, framework reflect-only OpenAPI generator (`ClassSchemaReflector` / `RouteReflectionDocGenerator`), Lemma (api-skeleton based).

**Spec:** `docs/superpowers/specs/2026-06-15-typed-response-dtos-design.md`

---

## Repos & file map

**Framework** (`/Users/michaeltawiahsowah/Sites/glueful/framework`):
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` (envelope `required`)
- Modify: `src/Support/Documentation/ClassSchemaReflector.php` (response-mode `#[ArrayOf]`)
- Create: `tests/Support/Fixtures/ResponseData/ResponseFieldFixture.php`, `tests/Support/Fixtures/ResponseData/ResponseArrayOfFixture.php`
- Modify: `tests/Unit/Support/Documentation/ClassSchemaReflectorTest.php`, `RouteReflectionDocGeneratorTest.php`
- Modify: `CHANGELOG.md`

**Lemma** (`/Users/michaeltawiahsowah/Sites/glueful/lemma`), all under `app/`:
- Create: `app/Content/Enums/EntryStatus.php`, `app/Content/Enums/FieldType.php`
- Create: `app/Content/Http/DTOs/Responses/ContentTypes/{FieldSchemaData,ContentTypeData,ContentTypeResultData,ContentTypeListData}.php`
- Create: `app/Content/Http/DTOs/Responses/Entries/{EntryData,DraftData,EntryCreateResultData,EntryResultData,DraftResultData}.php`
- Create: `app/Content/Http/DTOs/Responses/Publication/VersionResultData.php`
- Create: `app/Content/Http/DTOs/Responses/Preview/{PreviewData,PreviewResultData,PreviewMintData}.php`
- Create: `app/Content/Http/DTOs/Responses/Delivery/{DeliveryItemData,DeliveryListData}.php`
- Modify: the 5 controllers in `app/Content/Http/Controllers/` (annotations only)
- Modify: `tests/Support/LemmaTestCase.php` (drift-guard helper), `tests/Integration/Http/*ApiTest.php`
- Modify: `composer.json` (remove the dead `docs:finalize` entry; the script file is already absent)

---

# PHASE A — Framework generator fixes

Work in `/Users/michaeltawiahsowah/Sites/glueful/framework` on branch `dev`.

### Task A1: Mark envelope keys required

**Files:**
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php:388-398`
- Modify: `tests/Unit/Support/Documentation/RouteReflectionDocGeneratorTest.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the failing test.** `RouteReflectionDocGeneratorTest.php` defines its controllers inline (e.g. `SampleAppController`); there is no `ResponseData` fixture directory yet, so define a tiny `ResponseData` inline too. Add the test method:

```php
public function testEnvelopeMarksSuccessMessageDataRequired(): void
{
    $router = $this->makeRouter();
    $router->get('/v1/widget', [EnvelopeSampleController::class, 'show']);

    $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

    $schema = $paths['/v1/widget']['get']['responses']['200']['content']['application/json']['schema'];
    self::assertSame(['success', 'message', 'data'], $schema['required']);
}
```

At the bottom of the test file (next to the existing `SampleAppController`), add an inline `ResponseData` fixture and a controller that documents it:

```php
final class EnvelopeItemFixture implements \Glueful\Http\Contracts\ResponseData
{
    public function __construct(
        public readonly string $id = '',
    ) {
    }
}

final class EnvelopeSampleController
{
    #[ApiResponse(200, EnvelopeItemFixture::class)]
    public function show(): EnvelopeItemFixture
    {
        throw new \LogicException('doc only'); // never executed by the generator
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test -- --filter testEnvelopeMarksSuccessMessageDataRequired`
Expected: FAIL — `required` key absent (Undefined array key "required").

- [ ] **Step 3: Implement.** In `RouteReflectionDocGenerator::wrapInEnvelope()`, add the `required` line:

```php
private function wrapInEnvelope(array $schema): array
{
    return [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'data' => $schema,
        ],
        'required' => ['success', 'message', 'data'],
    ];
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test -- --filter testEnvelopeMarksSuccessMessageDataRequired`
Expected: PASS.

- [ ] **Step 5: Add CHANGELOG entry** under `## [Unreleased]` (create an `### Fixed` subsection):

```markdown
## [Unreleased]

### Fixed
- **OpenAPI: success envelope marks `success`/`message`/`data` as `required`.** The single-object success envelope now matches the flat-pagination envelope, which already marked them. Additive; those keys are always present at runtime.
```

- [ ] **Step 6: Commit.**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/framework
git add src/Support/Documentation/RouteReflectionDocGenerator.php tests/Unit/Support/Documentation/RouteReflectionDocGeneratorTest.php CHANGELOG.md
git commit -m "Mark success envelope keys required in generated OpenAPI"
```

### Task A2: Honor `#[ArrayOf]` in response mode

**Files:**
- Create: `tests/Support/Fixtures/ResponseData/ResponseFieldFixture.php`, `tests/Support/Fixtures/ResponseData/ResponseArrayOfFixture.php`
- Modify: `src/Support/Documentation/ClassSchemaReflector.php:182-243`
- Modify: `tests/Unit/Support/Documentation/ClassSchemaReflectorTest.php`
- Modify: `CHANGELOG.md`

> Note: the existing `FieldDefFixture` lives under `tests/Support/Fixtures/RequestData/` (a `RequestData`). To avoid coupling response tests to request fixtures, create response-specific fixtures under `ResponseData/`.

- [ ] **Step 1: Create two response fixtures.** A simple item type, and a `#[ArrayOf]` array with NO `@var` (so only `#[ArrayOf]` can supply items):

`tests/Support/Fixtures/ResponseData/ResponseFieldFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\ResponseData;

use Glueful\Http\Contracts\ResponseData;

final class ResponseFieldFixture implements ResponseData
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $type = '',
    ) {
    }
}
```

`tests/Support/Fixtures/ResponseData/ResponseArrayOfFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\ResponseData;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ResponseArrayOfFixture implements ResponseData
{
    public function __construct(
        #[ArrayOf(ResponseFieldFixture::class)]
        public readonly array $schema = [],
    ) {
    }
}
```

- [ ] **Step 2: Write the failing test.** Append to `ClassSchemaReflectorTest.php`:

```php
public function testResponseModeHonorsArrayOfForItems(): void
{
    $schema = ClassSchemaReflector::toSchema(
        \Glueful\Tests\Support\Fixtures\ResponseData\ResponseArrayOfFixture::class
    );
    $items = $schema['properties']['schema']['items'];
    self::assertSame('object', $items['type']);
    self::assertArrayHasKey('name', $items['properties']); // a ResponseFieldFixture field
}
```

- [ ] **Step 3: Run it; verify it fails.**

Run: `composer test -- --filter testResponseModeHonorsArrayOfForItems`
Expected: FAIL — response mode currently reads only the `@var` docblock (absent here), so `items` is an empty `{}` with no `type`/`properties`.

- [ ] **Step 4: Implement.** In `ClassSchemaReflector.php`, replace `arraySchema()` and the private `requestArraySchema()` with a single `#[ArrayOf]`-first `arraySchema()` plus a shared `arrayOfSchema()` helper. `requestArraySchema()` is deleted (its logic moves into `arrayOfSchema`). The `@var` docblock path stays as the response-mode fallback only:

```php
private static function arraySchema(\ReflectionProperty $property, array $visited, bool $requestMode = false): array
{
    // #[ArrayOf] is authoritative in BOTH modes when present.
    $attributes = $property->getAttributes(ArrayOf::class);
    if ($attributes !== []) {
        return self::arrayOfSchema($attributes[0]->newInstance(), $visited, $requestMode);
    }

    // No #[ArrayOf]: request mode is mixed; response mode falls back to @var.
    if ($requestMode) {
        return ['type' => 'array', 'items' => new \stdClass()];
    }

    $itemClass = self::itemClassFromDocblock($property);
    if ($itemClass !== null && class_exists($itemClass)) {
        if (is_a($itemClass, \DateTimeInterface::class, true)) {
            return ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']];
        }
        if (is_a($itemClass, \UnitEnum::class, true)) {
            return ['type' => 'array', 'items' => self::enumSchema($itemClass)];
        }
        return ['type' => 'array', 'items' => self::reflect($itemClass, $visited)];
    }
    if ($itemClass !== null) {
        $scalar = self::scalarSchema($itemClass);
        if ($scalar !== null) {
            return ['type' => 'array', 'items' => $scalar];
        }
    }
    return ['type' => 'array', 'items' => new \stdClass()];
}

/**
 * Resolve a `#[ArrayOf]` to an array schema, recursing DTO items in the CURRENT mode.
 *
 * @param  list<string> $visited
 * @return array<string, mixed>
 */
private static function arrayOfSchema(ArrayOf $arrayOf, array $visited, bool $requestMode): array
{
    if ($arrayOf->isScalar()) {
        $scalar = self::scalarSchema($arrayOf->type);
        return ['type' => 'array', 'items' => $scalar ?? new \stdClass()];
    }
    $dtoClass = $arrayOf->dtoClass();
    if ($dtoClass === null) {
        return ['type' => 'array', 'items' => new \stdClass()];
    }
    return ['type' => 'array', 'items' => self::reflect($dtoClass, $visited, $requestMode)];
}
```

- [ ] **Step 5: Run the new test + the existing request-mode test; verify both pass.**

Run: `composer test -- --filter "testResponseModeHonorsArrayOfForItems|RequestMode"`
Expected: PASS for both (request-mode behavior is preserved: `#[ArrayOf]` scalar→scalar, DTO→reflect in request mode, no `@var`).

- [ ] **Step 6: Add CHANGELOG entry** under the same `### Fixed` block:

```markdown
- **OpenAPI: `#[ArrayOf]` is honored in response mode.** `ClassSchemaReflector` now resolves array `items` from `#[ArrayOf]` for `ResponseData` DTOs too (previously request-mode only), falling back to the `@var Foo[]` docblock. `#[ArrayOf]` is now the consistent array element-type source for both request and response DTOs.
```

- [ ] **Step 7: Run the full doc-generator suite + deterministic static analysis.**

Run: `composer test -- --filter Documentation && vendor/bin/phpstan analyse src/Support/Documentation --no-progress`
Expected: all green; no PHPStan errors in the Documentation namespace. (Scoped + deterministic — uses the configured level from `phpstan.neon`, independent of git merge-base, unlike `analyse:changed`.)

- [ ] **Step 8: Commit.**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/framework
git add src/Support/Documentation/ClassSchemaReflector.php tests/Support/Fixtures/ResponseData/ResponseFieldFixture.php tests/Support/Fixtures/ResponseData/ResponseArrayOfFixture.php tests/Unit/Support/Documentation/ClassSchemaReflectorTest.php CHANGELOG.md
git commit -m "Honor #[ArrayOf] in response-mode schema reflection"
```

---

## CHECKPOINT 1 — Release framework & make it available to Lemma

> Manual, user-driven (not a coding task). **Version decision rule** (do not invent a number — re-check at release time): if `[Unreleased]` contains ONLY these two additive fixes and the framework is still at released `1.58.0`, ship a **patch** (reuse the parent minor's codename per the release rules). If other unreleased work is already pending in `[Unreleased]`, **fold these fixes into that pending release** instead of a standalone bump.

- [ ] Run the **release skill** to ship the framework release containing both fixes (Version.php, CHANGELOG `[Unreleased]`→`[<version>]`, ROADMAP, docs). Do not push/tag without explicit confirmation.
- [ ] Make the fixes available to Lemma — either:
  - **For development now:** add a local path repository (or symlink) so Lemma's `glueful/framework` resolves to the local checkout (see the aegis↔framework cross-repo dev setup), OR
  - **After release:** bump Lemma's `glueful/framework` pin to the released version containing the fixes, then `composer update glueful/framework`.
- [ ] Confirm in Lemma: `php -r 'require "vendor/autoload.php"; $r=new ReflectionMethod(\Glueful\Support\Documentation\ClassSchemaReflector::class,"arrayOfSchema"); echo "arrayOfSchema present\n";'` succeeds (proves the new framework code is installed).

---

# PHASE B — Lemma response DTOs

Work in `/Users/michaeltawiahsowah/Sites/glueful/lemma` on branch `dev`. All DTOs implement the marker `Glueful\Http\Contracts\ResponseData` and are **doc-only** (never constructed; controllers are unchanged). PHPCS forbids blank lines between constructor params — keep DTOs compact.

### Task B1: Enums + the drift-guard helper

**Files:**
- Create: `app/Content/Enums/EntryStatus.php`, `app/Content/Enums/FieldType.php`
- Modify: `tests/Support/LemmaTestCase.php`

- [ ] **Step 1: Create `EntryStatus`** (mirrors the `entries.status` enum column):

```php
<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum EntryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleted = 'deleted';
}
```

- [ ] **Step 2: Create `FieldType`** (mirrors `FieldDefinition::TYPES` — the source of truth; note `enum`/`json` are valid and there is no `integer`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum FieldType: string
{
    case StringType = 'string';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Datetime = 'datetime';
    case EnumType = 'enum';
    case Reference = 'reference';
    case Asset = 'asset';
    case Json = 'json';
}
```

- [ ] **Step 3: Add the drift-guard helper** to `LemmaTestCase` (an `$exact` flag: model rows match exactly; `schema[]` field items match as a subset because `ContentTypeSchema::toArray()` array_filters falsy keys):

```php
/**
 * Assert a runtime `data` payload's keys match a doc-only ResponseData DTO's
 * constructor params. With $exact=false, the payload keys must be a SUBSET of the
 * DTO params (for shapes that omit falsy keys). Never recurses into freeform fields.
 *
 * @param array<string,mixed>           $data
 * @param class-string<\Glueful\Http\Contracts\ResponseData> $dtoClass
 */
protected static function assertDataMatchesDtoShape(array $data, string $dtoClass, bool $exact = true): void
{
    $params = array_map(
        static fn (\ReflectionParameter $p): string => $p->getName(),
        (new \ReflectionMethod($dtoClass, '__construct'))->getParameters()
    );
    $actual = array_keys($data);
    if ($exact) {
        sort($params);
        sort($actual);
        self::assertSame($params, $actual, "Payload keys differ from {$dtoClass}");
    } else {
        self::assertSame([], array_diff($actual, $params), "Payload has keys not in {$dtoClass}");
    }
}
```

- [ ] **Step 4: Lint.**

Run: `php -l app/Content/Enums/EntryStatus.php && php -l app/Content/Enums/FieldType.php && php -l tests/Support/LemmaTestCase.php`
Expected: `No syntax errors` for each.

- [ ] **Step 5: Commit.**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add app/Content/Enums tests/Support/LemmaTestCase.php
git commit -m "Add content enums and OpenAPI drift-guard test helper"
```

### Task B2: Content-type response DTOs + annotate + drift tests

**Files:**
- Create: `app/Content/Http/DTOs/Responses/ContentTypes/{FieldSchemaData,ContentTypeData,ContentTypeResultData,ContentTypeListData}.php`
- Modify: `app/Content/Http/Controllers/ContentTypeController.php` (3 annotations)
- Modify: `tests/Integration/Http/ContentTypeApiTest.php`

- [ ] **Step 1: Create the four DTOs.**

`FieldSchemaData.php` (name/type always; the rest optional — `toArray()` omits falsy keys):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use App\Content\Enums\FieldType;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class FieldSchemaData implements ResponseData
{
    /** @param list<string> $enum */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?bool $required = null,
        public readonly ?bool $localized = null,
        public readonly ?bool $filterable = null,
        public readonly ?string $filter_type = null,
        #[ArrayOf('string')]
        public readonly array $enum = [],
    ) {
    }
}
```

`ContentTypeData.php` (exact `content_types` row; `hydrate()` decodes `schema`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ContentTypeData implements ResponseData
{
    /** @param list<FieldSchemaData> $schema */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        #[ArrayOf(FieldSchemaData::class)]
        public readonly array $schema,
        public readonly int $schema_version,
        public readonly ?string $created_by,
        public readonly \DateTimeInterface $created_at,
        public readonly ?\DateTimeInterface $updated_at,
    ) {
    }
}
```

`ContentTypeResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;

final class ContentTypeResultData implements ResponseData
{
    public function __construct(
        public readonly ContentTypeData $content_type,
    ) {
    }
}
```

`ContentTypeListData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ContentTypeListData implements ResponseData
{
    /** @param list<ContentTypeData> $content_types */
    public function __construct(
        #[ArrayOf(ContentTypeData::class)]
        public readonly array $content_types,
    ) {
    }
}
```

- [ ] **Step 2: Annotate `ContentTypeController`.** Add `use` imports and a `schema:` to the three success responses (keep `envelope` default true):
  - line 55 `#[ApiResponse(200, description: 'All content types.')]` → `#[ApiResponse(200, ContentTypeListData::class, description: 'All content types.')]`
  - line 89 `#[ApiResponse(201, description: 'Content type created.')]` → `#[ApiResponse(201, ContentTypeResultData::class, description: 'Content type created.')]`
  - line 144 `#[ApiResponse(200, description: 'The content type.')]` → `#[ApiResponse(200, ContentTypeResultData::class, description: 'The content type.')]`
  - line 184 `#[ApiResponse(200, description: 'Schema updated.')]` → `#[ApiResponse(200, ContentTypeResultData::class, description: 'Schema updated.')]`

Imports to add at the top of the controller:

```php
use App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeListData;
use App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData;
```

- [ ] **Step 3: Add drift assertions** to `ContentTypeApiTest.php`. In `testStoreCreatesType`, after the 201 assertion:

```php
$data = json_decode((string) $resp->getContent(), true)['data'];
self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData::class);
self::assertDataMatchesDtoShape(
    $data['content_type'],
    \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeData::class
);
foreach ($data['content_type']['schema'] as $field) {
    self::assertDataMatchesDtoShape(
        $field,
        \App\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData::class,
        exact: false   // toArray() omits falsy keys
    );
}
```

Add an equivalent block to a list test (call `index()` and assert against `ContentTypeListData` + each item against `ContentTypeData`).

- [ ] **Step 4: Run the test; verify pass.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && composer test -- --filter ContentTypeApiTest`
Expected: PASS. If a key mismatch appears, fix the DTO to match the real row (the assertion message names the offending DTO).

- [ ] **Step 5: Verify the generated schema.**

Run: `php glueful generate:openapi -f --clean && php -r '$s=json_decode(file_get_contents("docs/openapi.json"),true); $d=$s["paths"]["/v1/admin/content-types/{slug}"]["get"]["responses"]["200"]["content"]["application/json"]["schema"]; echo json_encode($d["required"]).PHP_EOL; echo json_encode($d["properties"]["data"]["properties"]["content_type"]["properties"]["schema"]["items"]["properties"]["type"]).PHP_EOL;' ; rm -f docs/openapi.json`
Expected: `["success","message","data"]` and a `type` with `enum` of the 9 field types (proves envelope `required` + response-mode `#[ArrayOf]` + the `FieldType` enum).

- [ ] **Step 6: phpcs + commit.**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Http/DTOs/Responses/ContentTypes app/Content/Http/Controllers/ContentTypeController.php tests/Integration/Http/ContentTypeApiTest.php
git commit -m "Document content-type responses with typed DTOs"
```

### Task B3: Entry / draft response DTOs + annotate + drift tests

**Files:**
- Create: `app/Content/Http/DTOs/Responses/Entries/{EntryData,DraftData,EntryCreateResultData,EntryResultData,DraftResultData}.php`
- Modify: `app/Content/Http/Controllers/EntryController.php` (4 annotations, lines 56/104/141/186)
- Modify: `tests/Integration/Http/EntryApiTest.php`

- [ ] **Step 1: Create the DTOs.**

`EntryData.php` (exact `entries` row — no `schema_version` here):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use App\Content\Enums\EntryStatus;
use Glueful\Http\Contracts\ResponseData;

final class EntryData implements ResponseData
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $content_type_uuid,
        public readonly EntryStatus $status,
        public readonly ?string $created_by,
        public readonly \DateTimeInterface $created_at,
        public readonly ?\DateTimeInterface $updated_at,
    ) {
    }
}
```

`DraftData.php` (exact `entry_drafts` row + decoded `fields`; no `created_at`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class DraftData implements ResponseData
{
    public function __construct(
        public readonly int $id,
        public readonly string $entry_uuid,
        public readonly string $locale,
        public readonly object $fields,
        public readonly int $schema_version,
        public readonly int $lock_version,
        public readonly ?string $updated_by,
        public readonly \DateTimeInterface $updated_at,
    ) {
    }
}
```

`EntryCreateResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryCreateResultData implements ResponseData
{
    public function __construct(
        public readonly EntryData $entry,
        public readonly DraftData $draft,
    ) {
    }
}
```

`EntryResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryResultData implements ResponseData
{
    public function __construct(
        public readonly EntryData $entry,
    ) {
    }
}
```

`DraftResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class DraftResultData implements ResponseData
{
    public function __construct(
        public readonly DraftData $draft,
    ) {
    }
}
```

- [ ] **Step 2: Annotate `EntryController`** (add imports; add `schema:`):
  - line 56 `#[ApiResponse(201, description: 'Entry created with an empty draft.')]` → `#[ApiResponse(201, EntryCreateResultData::class, description: 'Entry created with an empty draft.')]`
  - line 104 `#[ApiResponse(200, description: 'The entry.')]` → `#[ApiResponse(200, EntryResultData::class, description: 'The entry.')]`
  - line 141 `#[ApiResponse(200, description: 'The draft.')]` → `#[ApiResponse(200, DraftResultData::class, description: 'The draft.')]`
  - line 186 `#[ApiResponse(200, description: 'Draft saved.')]` → `#[ApiResponse(200, DraftResultData::class, description: 'Draft saved.')]`

Imports:

```php
use App\Content\Http\DTOs\Responses\Entries\DraftResultData;
use App\Content\Http\DTOs\Responses\Entries\EntryCreateResultData;
use App\Content\Http\DTOs\Responses\Entries\EntryResultData;
```

- [ ] **Step 3: Add drift assertions** to `EntryApiTest.php` for the create (201) and show/draft (200) paths, e.g.:

```php
$data = json_decode((string) $resp->getContent(), true)['data'];
self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryCreateResultData::class);
self::assertDataMatchesDtoShape($data['entry'], \App\Content\Http\DTOs\Responses\Entries\EntryData::class);
self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
```

- [ ] **Step 4: Run; verify pass.**

Run: `composer test -- --filter EntryApiTest`
Expected: PASS. Any key mismatch → adjust the DTO to the real row (named in the failure message).

- [ ] **Step 5: phpcs + commit.**

```bash
composer phpcs
git add app/Content/Http/DTOs/Responses/Entries app/Content/Http/Controllers/EntryController.php tests/Integration/Http/EntryApiTest.php
git commit -m "Document entry/draft responses with typed DTOs"
```

### Task B4: Publication response DTO + annotate + drift tests

**Files:**
- Create: `app/Content/Http/DTOs/Responses/Publication/VersionResultData.php`
- Modify: `app/Content/Http/Controllers/PublicationController.php` (lines 52, 137; leave 100/unpublish description-only)
- Modify: `tests/Integration/Http/PublicationApiTest.php`

- [ ] **Step 1: Create `VersionResultData`:**

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Publication;

use Glueful\Http\Contracts\ResponseData;

final class VersionResultData implements ResponseData
{
    public function __construct(
        public readonly ?string $version_uuid,
    ) {
    }
}
```

- [ ] **Step 2: Annotate `PublicationController`** (publish + rollback only; unpublish returns empty `data` → stays description-only):
  - line 52 `#[ApiResponse(200, description: 'Entry published.')]` → `#[ApiResponse(200, VersionResultData::class, description: 'Entry published.')]`
  - line 137 `#[ApiResponse(200, description: 'Rolled back to the named version.')]` → `#[ApiResponse(200, VersionResultData::class, description: 'Rolled back to the named version.')]`

Import: `use App\Content\Http\DTOs\Responses\Publication\VersionResultData;`

- [ ] **Step 3: Add drift assertions** to `PublicationApiTest.php` for the publish + rollback paths:

```php
$data = json_decode((string) $resp->getContent(), true)['data'];
self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Publication\VersionResultData::class);
```

- [ ] **Step 4: Run; verify pass.**

Run: `composer test -- --filter PublicationApiTest`
Expected: PASS.

- [ ] **Step 5: phpcs + commit.**

```bash
composer phpcs
git add app/Content/Http/DTOs/Responses/Publication app/Content/Http/Controllers/PublicationController.php tests/Integration/Http/PublicationApiTest.php
git commit -m "Document publication responses with a typed DTO"
```

### Task B5: Preview response DTOs + annotate + drift tests

**Files:**
- Create: `app/Content/Http/DTOs/Responses/Preview/{PreviewData,PreviewResultData,PreviewMintData}.php`
- Modify: `app/Content/Http/Controllers/PreviewController.php` (lines 63, 107)
- Modify: `tests/Integration/Http/PreviewApiTest.php`

- [ ] **Step 1: Create the DTOs.** `PreviewData` mirrors `PreviewReader::read()`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

final class PreviewData implements ResponseData
{
    public function __construct(
        public readonly string $entry_uuid,
        public readonly string $locale,
        public readonly ?string $version_uuid,
        public readonly ?int $version,
        public readonly int $schema_version,
        public readonly object $fields,
    ) {
    }
}
```

`PreviewResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

final class PreviewResultData implements ResponseData
{
    public function __construct(
        public readonly PreviewData $preview,
    ) {
    }
}
```

`PreviewMintData.php` (mint returns `{token, expires_at, expires_in}` directly as `data`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

final class PreviewMintData implements ResponseData
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeInterface $expires_at,
        public readonly int $expires_in,
    ) {
    }
}
```

- [ ] **Step 2: Annotate `PreviewController`:**
  - line 63 `#[ApiResponse(200, description: 'Preview token minted.')]` → `#[ApiResponse(200, PreviewMintData::class, description: 'Preview token minted.')]`
  - line 107 `#[ApiResponse(200, description: 'The previewed draft (or pinned version).')]` → `#[ApiResponse(200, PreviewResultData::class, description: 'The previewed draft (or pinned version).')]`

Imports:

```php
use App\Content\Http\DTOs\Responses\Preview\PreviewMintData;
use App\Content\Http\DTOs\Responses\Preview\PreviewResultData;
```

- [ ] **Step 3: Add drift assertions** to `PreviewApiTest.php` for the mint + read paths (`assertDataMatchesDtoShape($data, PreviewMintData::class)`; for read, assert `$data` against `PreviewResultData` and `$data['preview']` against `PreviewData`).

- [ ] **Step 4: Run; verify pass.**

Run: `composer test -- --filter PreviewApiTest`
Expected: PASS.

- [ ] **Step 5: phpcs + commit.**

```bash
composer phpcs
git add app/Content/Http/DTOs/Responses/Preview app/Content/Http/Controllers/PreviewController.php tests/Integration/Http/PreviewApiTest.php
git commit -m "Document preview responses with typed DTOs"
```

### Task B6: Delivery response DTOs + annotate + drift tests

**Files:**
- Create: `app/Content/Http/DTOs/Responses/Delivery/{DeliveryItemData,DeliveryListData}.php`
- Modify: `app/Content/Http/Controllers/DeliveryController.php` (index 200 ~line 116 multi-line; show 200 line 218)
- Modify: `tests/Integration/Http/DeliveryApiTest.php`

- [ ] **Step 1: Create the DTOs.** `DeliveryItemData` mirrors `DeliveryController::item()`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Delivery;

use Glueful\Http\Contracts\ResponseData;

final class DeliveryItemData implements ResponseData
{
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $locale,
        public readonly ?int $version,
        public readonly ?\DateTimeInterface $published_at,
        public readonly object $fields,
    ) {
    }
}
```

`DeliveryListData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Delivery;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class DeliveryListData implements ResponseData
{
    /** @param list<DeliveryItemData> $items */
    public function __construct(
        #[ArrayOf(DeliveryItemData::class)]
        public readonly array $items,
        public readonly ?string $next_cursor,
    ) {
    }
}
```

- [ ] **Step 2: Annotate `DeliveryController`.** The index 200 is multi-line; add `DeliveryListData::class` as the second positional arg right after `200,`. The show 200 (line 218) → `#[ApiResponse(200, DeliveryItemData::class, description: 'The published entry.')]`. Add imports:

```php
use App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData;
use App\Content\Http\DTOs\Responses\Delivery\DeliveryListData;
```

Index annotation becomes:

```php
    #[ApiResponse(
        200,
        DeliveryListData::class,
        description: 'A page of published entries (cursor mode by default; offset mode replaces `data` '
            . 'with the item array plus top-level pagination keys).',
    )]
```

- [ ] **Step 3: Add drift assertions** to `DeliveryApiTest.php`. For list (cursor mode): assert `$data` against `DeliveryListData`, and each `$data['items']` element against `DeliveryItemData`. For show: assert `$data` against `DeliveryItemData`. Never assert into `fields`.

```php
$data = json_decode((string) $resp->getContent(), true)['data'];
self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Delivery\DeliveryListData::class);
foreach ($data['items'] as $item) {
    self::assertDataMatchesDtoShape($item, \App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData::class);
}
```

- [ ] **Step 4: Run; verify pass.**

Run: `composer test -- --filter DeliveryApiTest`
Expected: PASS.

- [ ] **Step 5: phpcs + commit.**

```bash
composer phpcs
git add app/Content/Http/DTOs/Responses/Delivery app/Content/Http/Controllers/DeliveryController.php tests/Integration/Http/DeliveryApiTest.php
git commit -m "Document delivery responses with typed DTOs"
```

### Task B7: Remove the dead `docs:finalize` reference + final verification

> The finalize script is **already absent** from the tree (it was untracked and removed during the earlier cleanup), and `docs:openapi` already does NOT call it. There is no committed script-finalized baseline to diff against. The only stale remnant is the `docs:finalize` entry in `composer.json` pointing at the missing file, so this task removes that entry and verifies the native polish directly.

**Files:**
- Modify: `composer.json` (remove the dead `docs:finalize` entry)

- [ ] **Step 1: Remove the dead composer entry.** Edit `composer.json`: delete the line
  `"docs:finalize": "php scripts/openapi-finalize-security.php",` (leave
  `"docs:openapi": "php glueful generate:openapi -f --clean"` as the single docs command). Confirm valid JSON:

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && php -r 'echo json_decode(file_get_contents("composer.json"))?"valid\n":"INVALID\n";'`
Expected: `valid`.

- [ ] **Step 2: Generate the spec and confirm the native polish is present.**

```bash
composer docs:openapi
php -r '$s=json_decode(file_get_contents("docs/openapi.json"),true);
$ct=$s["paths"]["/v1/admin/content-types/{slug}"]["get"]["responses"]["200"]["content"]["application/json"]["schema"];
echo "envelope required: ".json_encode($ct["required"]).PHP_EOL;
$type=$ct["properties"]["data"]["properties"]["content_type"]["properties"]["schema"]["items"]["properties"]["type"];
echo "field type enum present: ".json_encode(isset($type["enum"])).PHP_EOL;
$list=$s["paths"]["/v1/content/{type}"]["get"]["responses"]["200"]["content"]["application/json"]["schema"]["properties"]["data"]["properties"];
echo "next_cursor nullable: ".json_encode($list["next_cursor"]["nullable"]??null).PHP_EOL;
echo "delivery security: ".json_encode($s["paths"]["/v1/content/{type}"]["get"]["security"]).PHP_EOL;'
rm -f docs/openapi.json
```

Expected: `["success","message","data"]`; `field type enum present: true`; `next_cursor nullable: true`; delivery security `[{"ApiKeyAuth":[]}]`. (The 21 injected dangling component schemas remain — deferred to the separate framework task, NOT a regression.)

- [ ] **Step 3: Full suite + phpcs.**

Run: `composer test && composer phpcs`
Expected: all green (prior total + the new drift assertions), phpcs clean.

- [ ] **Step 4: Commit.** `composer.json` may also carry the earlier local `docs:openapi` rewire; reconcile so this commit is the intended docs-pipeline state.

```bash
git add composer.json
git commit -m "Remove dead docs:finalize reference — response shapes now native"
```

---

## Self-review notes (gaps deliberately left to execution)

- **The finalize script is already absent** (untracked, removed earlier) and `docs:openapi` no longer calls it — so B7 is reduced to removing the dead `docs:finalize` line from `composer.json`. There is no committed script-finalized baseline, so verification asserts native properties directly rather than diffing.
- **`composer.json` (Lemma) is dirty with prior local edits** (the `docs:openapi` rewire from the earlier cleanup). Reconcile so B7's commit reflects the intended docs-pipeline state.
- **Dangling-schema noise is expected** after B7 (deferred to a separate framework task) — it is NOT a regression introduced by this plan.
- **DTO field exactness:** the drift-guard tests are the gate. If any DTO's keys don't match the live payload, the failing assertion names the DTO — fix the DTO to the real row, never loosen the test (except the documented `exact: false` for `FieldSchemaData` items).
