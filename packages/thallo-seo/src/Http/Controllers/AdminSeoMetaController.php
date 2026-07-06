<?php

declare(strict_types=1);

namespace Thallo\Seo\Http\Controllers;

use Glueful\Http\Response;
use Thallo\Seo\Meta\SeoMetaRepository;
use Thallo\Seo\Meta\SeoMetaUpsertDTO;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin read/write of the seo_meta override table. Behind auth + content_permission:seo.manage.
 */
final class AdminSeoMetaController
{
    public function __construct(private readonly SeoMetaRepository $repo)
    {
    }

    /**
     * The raw seo_meta override row for an entry+locale (empty object if none set yet).
     *
     * @queryParam locale:string="Locale of the override; defaults to en."
     */
    #[ApiOperation(summary: 'Read SEO meta overrides for an entry', tags: ['Thallo SEO'])]
    #[ApiResponse(200, description: 'The override row, or an empty object when unset.')]
    public function show(Request $request, string $entryUuid): Response
    {
        $locale = (string) $request->query->get('locale', 'en');
        $row = $this->repo->find($entryUuid, $locale);
        return Response::success($row ?? new \stdClass());
    }

    /**
     * Upsert the seo_meta override row for an entry+locale. Body accepts any of:
     * `title`, `description`, `og_title`, `og_description`, `og_image`, `twitter_card`, `robots`.
     *
     * @queryParam locale:string="Locale of the override; defaults to en."
     */
    #[ApiOperation(summary: 'Upsert SEO meta overrides for an entry', tags: ['Thallo SEO'])]
    #[ApiResponse(200, description: 'The stored override row after the upsert.')]
    #[ApiResponse(422, description: 'Non-string field, over-length value, or unknown enum value.')]
    public function update(Request $request, string $entryUuid): Response
    {
        $locale = (string) $request->query->get('locale', 'en');
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);

        // Throws ValidationException (422) on bad input — the framework handler renders it.
        $dto = SeoMetaUpsertDTO::fromRequest($locale, $body);
        $this->repo->upsert($entryUuid, $dto->locale, $dto->fields);
        return Response::success($this->repo->find($entryUuid, $dto->locale));
    }
}
