<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Validation\RequestDataHydrator;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A part of a layout checked against a layout's target (sections and templates design §3.2, §5):
 * the surface's palette and cards, block validation and every field a block binds — never the
 * counts a whole layout owes — with its blocks normalised as a save would write them; and the
 * target's binding rules the editor needs to check a section before it lands (§4).
 */
final class LayoutFragmentTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $types = $this->container()->get(ContentTypeRepository::class);
        $types->create(['slug' => 'lf_post', 'name' => 'LF posts', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true, 'label' => 'Headline'],
            ['name' => 'body', 'type' => 'blocks'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                'reference_slug_field' => 'slug', 'multiple' => true],
        ]]);
        $types->create(['slug' => 'lf_page', 'name' => 'LF pages', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]]);
    }

    private function validator(): LayoutValidator
    {
        return $this->container()->get(LayoutValidator::class);
    }

    /** @return array<string,mixed> */
    private function session(string $surface, string $target): array
    {
        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => $surface, 'target' => $target],
        );
        $response = $this->container()->get(LayoutPreviewController::class)->session($dto);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    public function testASectionThatFitsHasNoErrorsAndComesBackNormalised(): void
    {
        $block = ['type' => 'entry_cover', 'data' => ['aspect' => '16:9'], 'settings' => []];
        $result = $this->validator()->fragment('entry', 'lf_post', [$block]);
        self::assertSame([], $result['errors']);
        self::assertSame('cover', $result['blocks'][0]['data']['field'], 'bound to the field the server chose');
        self::assertArrayNotHasKey('id', $result['blocks'][0], 'an id-less tree comes back id-less');

        $saved = ['id' => 'cover0000001'] + $block;
        self::assertSame('cover0000001', $this->validator()->fragment('entry', 'lf_post', [$saved])['blocks'][0]['id']);
    }

    public function testAMissingFieldIsNamed(): void
    {
        $block = ['type' => 'entry_field', 'data' => ['field' => 'subtitle', 'format' => 'text'], 'settings' => []];
        $errors = $this->validator()->fragment('entry', 'lf_page', [$block])['errors'];
        self::assertSame("this type has no field 'subtitle'", $errors['blocks.0.data.field'] ?? null);
    }

    public function testAnIncompatibleFieldIsRefused(): void
    {
        $block = ['type' => 'entry_cover', 'data' => ['field' => 'title'], 'settings' => []];
        $errors = $this->validator()->fragment('entry', 'lf_post', [$block])['errors'];
        self::assertSame("'title' cannot be shown by this block", $errors['blocks.0.data.field'] ?? null);
    }

    public function testAFormatTheFieldCannotTakeIsRefused(): void
    {
        $block = ['type' => 'entry_field', 'data' => ['field' => 'title', 'format' => 'date'], 'settings' => []];
        $errors = $this->validator()->fragment('entry', 'lf_post', [$block])['errors'];
        self::assertArrayHasKey('blocks.0.data.format', $errors);
    }

    public function testTheSurfacesPaletteAndCardsStillApply(): void
    {
        $loop = ['type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []];
        self::assertNotSame(
            [],
            $this->validator()->fragment('entry', 'lf_post', [$loop])['errors'],
            'an Entry list is not in the post palette',
        );
    }

    public function testRequiredBlocksAreNotAsked(): void
    {
        // A fragment is a part of a layout: it need not hold the required Entry content block.
        $title = ['type' => 'entry_title', 'data' => ['level' => 'h1'], 'settings' => []];
        self::assertSame([], $this->validator()->fragment('entry', 'lf_post', [$title])['errors']);
    }

    public function testTargetError(): void
    {
        self::assertNull($this->validator()->targetError('entry', 'lf_post'));
        self::assertSame("unknown layout surface 'basket'", $this->validator()->targetError('basket', 'x'));
        self::assertNotNull($this->validator()->targetError('entry', 'no_such_type'));
        self::assertSame(
            ['target' => $this->validator()->targetError('entry', 'no_such_type')],
            $this->validator()->fragment('entry', 'no_such_type', [])['errors'],
        );
    }

    public function testTheSessionCarriesTheTargetsBindingRules(): void
    {
        $data = $this->session('entry', 'lf_post');
        self::assertSame(
            ['title' => 'string', 'body' => 'blocks', 'cover' => 'asset', 'categories' => 'reference'],
            $data['bindable'],
        );
        self::assertSame(
            ['title' => 'Headline', 'body' => 'Body', 'cover' => 'Cover', 'categories' => 'Categories'],
            $data['field_labels'],
        );
        self::assertSame(['asset'], $data['bindings']['entry_cover']);
        self::assertSame('cover', $data['default_fields']['entry_cover']);
        self::assertSame(['datetime'], $data['format_needs']['date']);
        self::assertSame('LF posts', $data['type_name']);
    }
}
