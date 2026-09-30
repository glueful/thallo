<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * What the server answers for each shared candidate case (sections and templates design §4):
 * tests/fixtures/layouts/candidate-cases.json feeds this test and the admin's candidate check
 * (admin/src/__tests__/layout-candidate.spec.ts), so a section the editor's preflight accepts is
 * one apply accepts. The preflight lets a working copy lack a required block — an allowance for a
 * document mid-edit — so only the "must show" errors are set aside here.
 */
final class LayoutCandidateParityTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    /** Each rule's server wording. */
    private const RULES = [
        '1' => 'can appear only once',
        '4' => 'this type has no field',
        '5' => 'cannot be shown by this block',
        '6' => 'cannot be shown as a',
        '7' => 'is already placed by another block',
    ];

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(\dirname(__DIR__, 4) . '/tests/fixtures/layouts/candidate-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $types = $this->container()->get(ContentTypeRepository::class);
        foreach (self::fixture()['types'] as $slug => $type) {
            $types->create([
                'slug' => $slug, 'name' => $type['name'], 'public_delivery' => true, 'schema' => $type['schema'],
            ]);
        }
    }

    public function testTheServerAnswersEachCaseAsTheCaseSays(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        foreach (self::fixture()['cases'] as $case) {
            try {
                $validator->validate($case['surface'], $case['target'], $case['blocks'], [], [], false);
                $errors = [];
            } catch (ValidationException $e) {
                $errors = array_values(array_filter(
                    $e->errors(),
                    static fn (string $message): bool => !str_starts_with($message, 'the layout must show'),
                ));
            }
            if ($case['expect'] === 'ok') {
                self::assertSame([], $errors, $case['name']);
                continue;
            }
            $rule = self::RULES[$case['expect']];
            $matching = array_filter($errors, static fn (string $m): bool => str_contains($m, $rule));
            self::assertNotSame([], $matching, "{$case['name']}: rule {$case['expect']} — " . json_encode($errors));
        }
    }
}
