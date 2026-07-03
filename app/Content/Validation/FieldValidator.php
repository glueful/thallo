<?php

declare(strict_types=1);

namespace App\Content\Validation;

use App\Content\Blocks\BlockDepth;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Sanitization\TipTapHtmlSanitizer;
use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class FieldValidator
{
    public function __construct(
        private readonly ?Connection $db = null,
        private readonly ?ApplicationContext $context = null,
        private ?BlockTypeRepository $blockTypes = null,
        private ?RichHtmlSanitizer $sanitizer = null,
    ) {
    }

    private function blockTypes(): ?BlockTypeRepository
    {
        if ($this->blockTypes === null && $this->db !== null) {
            $this->blockTypes = new BlockTypeRepository($this->db);
        }
        return $this->blockTypes;
    }

    private function sanitizer(): RichHtmlSanitizer
    {
        return $this->sanitizer ??= new TipTapHtmlSanitizer();
    }

    /**
     * Validate a fields payload against a content type schema.
     * Returns the cleaned payload (known fields only, in schema order).
     *
     * `$strict` is the publish-time gate: it additionally rejects present-but-empty required fields
     * ('' / []) and dangling `reference` values (targets that don't exist). Draft saves call with
     * `$strict = false` so incomplete work-in-progress can still be saved; publish calls with `true`
     * so invalid content can't go live.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(ContentTypeSchema $schema, array $payload, bool $strict = false): array
    {
        // Entry-wide block-id set (visual-canvas spec §5): the canvas bridge keys
        // rendered blocks by BARE id, so uniqueness spans every blocks field AND
        // nesting level of one validated entry — not just each list.
        $seenBlockIds = [];
        return $this->validateAt($schema, $payload, $strict, 0, $seenBlockIds);
    }

    /**
     * The depth-carrying internal (nesting amendment §A3): public validate() enters at
     * depth 0; a blocks field's items sit at $depth + 1; beyond BlockDepth::MAX the
     * FIELD errors at its dot path and nothing deeper validates. Recursion for nested
     * block data goes through HERE — never blindly through public validate().
     *
     * @param array<string,mixed> $payload
     * @param array<string,bool> $seenBlockIds the ENTRY-WIDE block-id set
     * @return array<string,mixed>
     * @throws ValidationException
     */
    private function validateAt(
        ContentTypeSchema $schema,
        array $payload,
        bool $strict,
        int $depth,
        array &$seenBlockIds,
    ): array {
        $errors = [];
        $clean = [];

        foreach ($schema->fields() as $field) {
            $present = array_key_exists($field->name, $payload);
            $value = $present ? $payload[$field->name] : null;

            if (!$present || $value === null) {
                if ($field->required) {
                    $errors[$field->name] = 'is required';
                }
                continue;
            }

            // Publish-time only: a present-but-empty value ('' or []) does not satisfy `required`.
            // (Permissive mode keeps the historical behaviour where empties passed, so drafts save.)
            if ($strict && $field->required && ($value === '' || $value === [])) {
                $errors[$field->name] = 'is required';
                continue;
            }

            // Blocks (block-builder spec §4): per-block validation against the block
            // type's schema, dot-path errors `field.index[.blockField]`. The field's
            // block_types allowlist is PICKER-ONLY and deliberately not enforced here.
            if ($field->type === 'blocks') {
                [$cleanBlocks, $blockErrors] = $this->validateBlocks(
                    $field->name,
                    $value,
                    $strict,
                    $depth,
                    $seenBlockIds,
                );
                foreach ($blockErrors as $path => $message) {
                    $errors[$path] = $message;
                }
                if ($blockErrors === []) {
                    $clean[$field->name] = $cleanBlocks;
                }
                continue;
            }

            // Multi-valued reference/asset: strict ordered uuid array, deduped, capped.
            if (($field->type === 'reference' || $field->type === 'asset') && $field->multiple) {
                $normalized = $this->normalizeMultiValue($field, $value);
                if (is_string($normalized)) { // error message
                    $errors[$field->name] = $normalized;
                    continue;
                }
                if ($field->type === 'asset') {
                    foreach ($normalized as $uuid) {
                        if (!$this->assetExistsOnMediaDisk($uuid)) {
                            $errors[$field->name] = 'must reference active blobs on the configured media disk';
                            continue 2;
                        }
                    }
                }
                if ($strict && $field->type === 'reference') {
                    foreach ($normalized as $uuid) {
                        if (!$this->referenceExists($uuid)) {
                            $errors[$field->name] = 'must reference existing entries';
                            continue 2;
                        }
                    }
                }
                $clean[$field->name] = $normalized;
                continue;
            }

            $error = $this->checkType($field, $value);
            if ($error !== null) {
                $errors[$field->name] = $error;
                continue;
            }
            if ($field->type === 'asset' && is_string($value) && !$this->assetExistsOnMediaDisk($value)) {
                $errors[$field->name] = 'must reference an active blob on the configured media disk';
                continue;
            }
            // Publish-time only: a single reference must point at an entry that actually exists, so a
            // dangling/typo'd uuid can't go live (a slug-display label never reaches here; the stored
            // value is the target entry uuid).
            if ($strict && $field->type === 'reference' && is_string($value) && !$this->referenceExists($value)) {
                $errors[$field->name] = 'must reference an existing entry';
                continue;
            }
            // Rich HTML sanitizes at SAVE into the cleaned payload (sanitizer spec
            // §3): stored data is clean by construction. Blocks recursion routes
            // nested rich fields through this same line — zero special-casing.
            // Plain text fields stay untouched (escaping is the renderer's job).
            if ($field->type === 'text' && $field->format === 'rich' && is_string($value)) {
                $value = $this->sanitizer()->sanitize($value);
            }
            // Normalize datetime values to canonical ISO-8601 UTC so stored values are
            // lexicographically comparable as TEXT (the only IMMUTABLE index expression
            // for datetime — see FilterIndexPlanner / FilterCompiler).
            if ($field->type === 'datetime' && is_string($value)) {
                $value = self::normalizeDatetime($value);
            }
            $clean[$field->name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /**
     * Canonicalize a datetime string to ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`).
     *
     * Returned form is lexicographically sortable and identical regardless of the input's
     * timezone/offset, so stored values and filter bindings compare correctly as text.
     *
     * A timezone-LESS input is interpreted as UTC rather than the server's local zone: previously
     * `strtotime()` read a bare "2020-01-13 09:00:00" in the server timezone and silently shifted the
     * stored value by the server's UTC offset. Inputs carrying an explicit offset/Z are honoured.
     * (Relative inputs like "tomorrow" are still accepted here; tightening to strict ISO-only is a
     * separate, later change.)
     */
    public static function normalizeDatetime(string $value): string
    {
        $hasTimezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', trim($value)) === 1;
        try {
            $dt = new \DateTimeImmutable($value, $hasTimezone ? null : new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $value; // unparseable; checkType() reports the error separately
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function checkType(FieldDefinition $field, mixed $value): ?string
    {
        return match ($field->type) {
            'string', 'text' => is_string($value) ? null : 'must be a string',
            'number' => (is_int($value) || is_float($value)) ? null : 'must be a number',
            'boolean' => is_bool($value) ? null : 'must be a boolean',
            'datetime' => (is_string($value) && strtotime($value) !== false) ? null : 'must be an ISO datetime',
            'enum' => in_array($value, $field->enumValues, true) ? null
                : 'must be one of: ' . implode(', ', $field->enumValues),
            'reference', 'asset' => (is_string($value) && $value !== '') ? null : 'must be a uuid',
            'json' => (is_array($value)) ? null : 'must be an object/array',
            'blocks' => 'must be an ordered list of blocks', // handled by validateBlocks(); guard only
            default => 'unknown field type',
        };
    }

    /**
     * Per-block validation (block-builder spec §4): each block validates as
     * {id, type, data} — `type` a KNOWN block-type slug (active OR inactive; unknown
     * rejects), `id` unique ACROSS THE WHOLE ENTRY (visual-canvas spec §5 — the
     * shared set spans every blocks field and nesting level; server-generated when
     * missing), `data` structurally an OBJECT validated against the block type's
     * schema via recursion (the SAME cleaned-payload semantics as top-level fields:
     * known keys only, in schema order; `$strict` threads the publish gate —
     * dangling references inside block data reject at publish). Errors carry dot
     * paths: `field.index[.blockField]`.
     *
     * @param array<string,bool> $seenBlockIds the ENTRY-WIDE block-id set
     * @return array{0: list<array{id: string, type: string, data: array<string,mixed>}>,
     *   1: array<string,string>}
     */
    private function validateBlocks(
        string $fieldName,
        mixed $value,
        bool $strict,
        int $depth,
        array &$seenBlockIds,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            return [[], [$fieldName => 'must be an ordered list of blocks']];
        }
        // Depth cap (nesting amendment §A2/§A3): this list's items sit at $depth + 1.
        if ($depth + 1 > BlockDepth::MAX) {
            return [[], [$fieldName => sprintf(
                'exceeds maximum block nesting depth (%d)',
                BlockDepth::MAX,
            )]];
        }
        $registry = $this->blockTypes();
        if ($registry === null) {
            return [[], [$fieldName => 'block types are unavailable']];
        }
        $schemas = $registry->schemasBySlug();
        $errors = [];
        $clean = [];
        foreach ($value as $i => $block) {
            $path = "{$fieldName}.{$i}";
            if (!is_array($block)) {
                $errors[$path] = 'must be a block object {id, type, data}';
                continue;
            }
            $type = $block['type'] ?? null;
            if (!is_string($type) || !isset($schemas[$type])) {
                $errors[$path] = 'unknown block type' . (is_string($type) ? " '{$type}'" : '');
                continue;
            }
            $id = isset($block['id']) && is_string($block['id']) && $block['id'] !== ''
                ? $block['id']
                : Utils::generateNanoID();
            if (isset($seenBlockIds[$id])) {
                $errors[$path] = "duplicate block id '{$id}'";
                continue;
            }
            $seenBlockIds[$id] = true;
            // `data` is structurally an OBJECT (spec §1): missing, scalar, or a
            // non-empty list is a shape error — never silently coerced to [] (that
            // would let {data:"oops"} pass whenever the schema has no required
            // fields). PHP can't distinguish decoded '{}' from '[]'; empty is allowed.
            $data = $block['data'] ?? null;
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                $errors["{$path}.data"] = 'must be an object';
                continue;
            }
            try {
                $cleanData = $this->validateAt($schemas[$type], $data, $strict, $depth + 1, $seenBlockIds);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $blockField => $message) {
                    $errors["{$path}.{$blockField}"] = $message;
                }
                continue;
            }
            $clean[] = ['id' => $id, 'type' => $type, 'data' => $cleanData];
        }
        return [$clean, $errors];
    }

    /**
     * Normalize a multiple reference/asset value to an ordered, deduped uuid array.
     * Returns the array on success, or a string error message on failure.
     *
     * @return list<string>|string
     */
    private function normalizeMultiValue(FieldDefinition $field, mixed $value): array|string
    {
        if (!is_array($value) || !array_is_list($value)) { // reject objects/maps; [] is a valid empty list
            return 'must be an array of uuids';
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                return 'each item must be a non-empty uuid';
            }
            if (!in_array($item, $out, true)) { // dedupe, first occurrence kept
                $out[] = $item;
            }
        }
        if ($field->maxItems !== null && count($out) > $field->maxItems) {
            return "must have at most {$field->maxItems} items";
        }
        return $out;
    }

    /**
     * Whether a reference target entry exists and is not soft-deleted. Fail-open when no DB is wired
     * (unit context) — same posture as the asset existence check — so validation without a container
     * behaves as before.
     */
    private function referenceExists(string $uuid): bool
    {
        if ($this->db === null) {
            return true;
        }
        try {
            return $this->db->table('entries')
                ->where('uuid', '=', $uuid)
                ->where('status', '!=', 'deleted')
                ->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assetExistsOnMediaDisk(string $uuid): bool
    {
        if ($this->db === null || $this->context === null) {
            return true;
        }

        $disk = (string) config($this->context, 'lemma.media_disk', 'local');
        try {
            return $this->db->table('blobs')
                ->where('uuid', '=', $uuid)
                ->where('storage_type', '=', $disk)
                ->where('status', '=', 'active')
                ->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
