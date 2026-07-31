<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Regions\RegionValidator;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;

/**
 * Opt-in `enforce_block_types` enforcement (public-account-surface plan Task 2). The flag turns a
 * `blocks` field's `block_types` allowlist from a picker-only hint into a hard server-side reject,
 * closing BOTH content write paths at once because entry writes and region writes share the same
 * `FieldValidator::validateBlocks()`. Proven here through:
 *  - the region-save path (a placed `auth-state` block, whose slots enforce) — the real scenario;
 *  - the shared validator directly — the picker-only DEFAULT is unchanged for any field without
 *    the flag, so tightening it never strands existing content.
 */
final class BlockTypeEnforcementTest extends AppTestCase
{
    /** Seed the core starter block types (idempotent) into a fresh repository. */
    private function repoWithStarters(): BlockTypeRepository
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        return $repo;
    }

    /**
     * Seed the `auth-state` block type (pack block, seeded directly so this test does not depend on
     * thallo-account): two blocks slots, each with an enforced allowlist matching the contributor.
     */
    private function seedAuthState(BlockTypeRepository $repo): void
    {
        if ($repo->findBySlug('auth-state') !== null) {
            return;
        }
        // Mirrors AccountBlockTypesContributor::ALLOWED_CHILD_TYPES (literals, not an import —
        // this app-core test stays independent of thallo-account): the vetted cache-safe set,
        // including the three account form blocks (account-form-blocks plan Task 2).
        $slot = [
            'type' => 'blocks',
            'block_types' => [
                'button', 'links', 'rich_text', 'logo', 'navigation',
                'login-form', 'register-form', 'forgot-password-form',
            ],
            'enforce_block_types' => true,
        ];
        $repo->create([
            'slug' => 'auth-state',
            'label' => 'Account state',
            'schema' => [
                ['name' => 'signed_out'] + $slot,
                ['name' => 'signed_in'] + $slot,
            ],
        ]);
    }

    public function testRegionSaveAcceptsAnAllowedChildInAnEnforcedSlot(): void
    {
        $repo = $this->repoWithStarters();
        $this->seedAuthState($repo);
        $validator = new RegionValidator(new FieldValidator($this->connection(), $this->appContext(), $repo));

        $clean = $validator->validate('header', [
            ['id' => 'as1', 'type' => 'auth-state', 'data' => [
                'signed_out' => [
                    ['id' => 'so1', 'type' => 'button', 'data' => ['label' => 'Sign in', 'url' => '/account/login']],
                ],
                'signed_in' => [],
            ]],
        ], []);

        self::assertSame('auth-state', $clean['blocks'][0]['type']);
        self::assertCount(1, $clean['blocks'][0]['data']['signed_out']);
        self::assertSame('button', $clean['blocks'][0]['data']['signed_out'][0]['type']);
    }

    public function testAnEnforcedSlotAcceptsTheVettedLoginFormBlock(): void
    {
        // The vetted-enhanced case (account-form-blocks plan Task 2): `login-form` is IN the
        // allowlist, so it validates inside a signed_out slot like any other permitted child.
        $repo = $this->repoWithStarters();
        $this->seedAuthState($repo);
        $repo->create([
            'slug' => 'login-form',
            'label' => 'Sign-in form',
            'schema' => [['name' => 'heading', 'type' => 'string']],
        ]);
        $validator = new RegionValidator(new FieldValidator($this->connection(), $this->appContext(), $repo));

        $clean = $validator->validate('header', [
            ['id' => 'as1', 'type' => 'auth-state', 'data' => [
                'signed_out' => [
                    ['id' => 'lf1', 'type' => 'login-form', 'data' => ['heading' => 'Welcome back']],
                ],
                'signed_in' => [],
            ]],
        ], []);

        self::assertSame('login-form', $clean['blocks'][0]['data']['signed_out'][0]['type']);
    }

    public function testRegionSaveRejectsADisallowedChildAtThePreciseSlotPath(): void
    {
        $repo = $this->repoWithStarters();
        $this->seedAuthState($repo);
        $validator = new RegionValidator(new FieldValidator($this->connection(), $this->appContext(), $repo));

        // `separator` is a registered core block, so it passes the registered-slug check — but it is
        // NOT in the slot's allowlist, so enforce_block_types must reject it BEFORE it recurses into
        // the child's own schema, at the exact slot dot-path.
        try {
            $validator->validate('header', [
                ['id' => 'as1', 'type' => 'auth-state', 'data' => [
                    'signed_out' => [
                        ['id' => 'bad1', 'type' => 'separator', 'data' => []],
                    ],
                    'signed_in' => [],
                ]],
            ], []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.0.signed_out.0', $e->errors());
            self::assertStringContainsString('not allowed here', $e->errors()['blocks.0.signed_out.0']);
        }
    }

    public function testAPickerOnlyBlocksFieldStillAcceptsAnOutOfListChild(): void
    {
        // A blocks field carrying `block_types` metadata but WITHOUT `enforce_block_types` stays
        // picker-only: a registered out-of-list child validates exactly as before (default preserved).
        $repo = $this->repoWithStarters();
        $repo->create([
            'slug' => 'picker-only-gate',
            'label' => 'Picker-only gate',
            'schema' => [
                ['name' => 'slot', 'type' => 'blocks', 'block_types' => ['logo']], // NO enforce_block_types
            ],
        ]);
        $validator = new FieldValidator($this->connection(), $this->appContext(), $repo);
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);

        $clean = $validator->validate($schema, [
            'body' => [
                ['id' => 'g1', 'type' => 'picker-only-gate', 'data' => [
                    'slot' => [
                        // `button` is out of the ['logo'] picker list, but with no enforcement it saves.
                        ['id' => 'c1', 'type' => 'button', 'data' => ['label' => 'Hi', 'url' => '/x']],
                    ],
                ]],
            ],
        ]);

        self::assertCount(1, $clean['body']);
        self::assertSame('button', $clean['body'][0]['data']['slot'][0]['type']);
    }
}
