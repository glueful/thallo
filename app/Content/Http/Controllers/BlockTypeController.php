<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\DTOs\BlockTypeData;
use App\Content\Http\DTOs\FieldDefinitionData;
use App\Content\Http\DTOs\Responses\BlockTypes\BlockTypeListData;
use App\Content\Http\DTOs\Responses\BlockTypes\BlockTypeResultData;
use App\Content\Http\DTOs\UpdateBlockTypeData;
use App\Content\Schema\SchemaParseException;
use App\Http\DTOs\ErrorResponse;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The global block-type registry API (block-builder spec §1–§2). Slugs are immutable
 * (blocks/{slug}.twig contract); removal is deactivation only — inactive types
 * disappear from the add-picker while existing content keeps validating and rendering.
 * Read routes require `content.view`; mutating routes require `content.manage`
 * (enforced by route middleware).
 */
final class BlockTypeController
{
    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    #[ApiOperation(summary: 'List block types', tags: ['Lemma Admin'])]
    #[ApiResponse(200, schema: BlockTypeListData::class, description: 'All block types, active first.')]
    public function index(Request $request): Response
    {
        return Response::success(['block_types' => $this->blockTypes->all()], 'Block types retrieved.');
    }

    #[ApiOperation(
        summary: 'Create a block type',
        description: '`slug` is a unique lowercase identifier and IMMUTABLE after creation — it is the '
            . 'blocks/{slug}.twig template contract.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(201, schema: BlockTypeResultData::class, description: 'Block type created.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Duplicate slug or invalid block schema (no nested blocks/localized/filterable fields).',
    )]
    public function store(BlockTypeData $input, Request $request): Response
    {
        if ($this->blockTypes->findBySlug($input->slug) !== null) {
            return Response::validation(['slug' => "block type '{$input->slug}' already exists"]);
        }
        try {
            $uuid = $this->blockTypes->create([
                'slug' => $input->slug,
                'label' => trim($input->label),
                'icon' => $input->icon,
                'category' => $input->category,
                'description' => $input->description,
                'schema' => array_map(
                    static fn (FieldDefinitionData $f): array => $f->toArray(),
                    $input->schema,
                ),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::created(
            ['block_type' => $this->blockTypes->findByUuid($uuid)],
            'Block type created.',
        );
    }

    #[ApiOperation(summary: 'One block type', tags: ['Lemma Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'The block type with its schema.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function show(Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        return $row === null
            ? Response::error('Unknown block type.', 404)
            : Response::success(['block_type' => $row]);
    }

    #[ApiOperation(summary: 'Update a block type (slug is immutable)', tags: ['Lemma Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Invalid block schema (no nested blocks/localized/filterable fields).',
    )]
    public function update(UpdateBlockTypeData $input, Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        try {
            $this->blockTypes->updateSchema(
                (string) $row['uuid'],
                array_map(static fn (FieldDefinitionData $f): array => $f->toArray(), $input->schema),
                trim($input->label),
                $input->icon,
                $input->description,
                $input->category,
            );
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::success(
            ['block_type' => $this->blockTypes->findBySlug($slug)],
            'Block type updated.',
        );
    }

    #[ApiOperation(summary: 'Reactivate a block type', tags: ['Lemma Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Active — back in the block picker.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function activate(string $slug): Response
    {
        return $this->setActive($slug, true);
    }

    #[ApiOperation(
        summary: 'Deactivate a block type (existing content keeps rendering/editing)',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Inactive — hidden from the picker.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function deactivate(string $slug): Response
    {
        return $this->setActive($slug, false);
    }

    private function setActive(string $slug, bool $active): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        $this->blockTypes->setActive((string) $row['uuid'], $active);
        return Response::success(
            ['block_type' => $this->blockTypes->findBySlug($slug)],
            $active ? 'Block type activated.' : 'Block type deactivated.',
        );
    }
}
