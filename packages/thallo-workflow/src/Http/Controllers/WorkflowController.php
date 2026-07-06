<?php

declare(strict_types=1);

namespace Thallo\Workflow\Http\Controllers;

use Glueful\Http\Response;
use Thallo\Contracts\Authoring\DraftSummaryReader;
use Thallo\Workflow\Http\WorkflowNoteDTO;
use Thallo\Workflow\IllegalTransition;
use Thallo\Workflow\WorkflowForbidden;
use Thallo\Workflow\WorkflowService;
use Thallo\Workflow\WorkflowStateRepository;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin review-workflow API. Route-gated (capability → auth → lemma_permission); the
 * finer rules (self-review, withdraw ownership, note-required) live in the service/DTO.
 */
final class WorkflowController
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly WorkflowStateRepository $states,
        private readonly DraftSummaryReader $drafts,
        private readonly ?PermissionManager $permissions,
    ) {
    }

    #[ApiOperation(summary: 'Submit a draft for review', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(404, description: 'Unknown entry/locale (no draft).')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function submit(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition(
            $request,
            $uuid,
            $locale,
            fn(string $actor, ?string $note): array => $this->workflow->submit($uuid, $locale, $actor, $note),
            requireNote: false,
        );
    }

    #[ApiOperation(summary: 'Approve a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(403, description: 'Self-review blocked.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function approve(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition(
            $request,
            $uuid,
            $locale,
            fn(string $actor, ?string $note): array => $this->workflow->approve($uuid, $locale, $actor, $note),
            requireNote: false,
        );
    }

    #[ApiOperation(summary: 'Request changes on a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    #[ApiResponse(422, description: 'A note is required.')]
    public function requestChanges(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition(
            $request,
            $uuid,
            $locale,
            fn(string $actor, ?string $note): array
                => $this->workflow->requestChanges($uuid, $locale, $actor, (string) $note),
            requireNote: true,
        );
    }

    #[ApiOperation(summary: 'Withdraw a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(403, description: 'Only the submitter or a reviewer may withdraw.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function withdraw(Request $request, string $uuid, string $locale): Response
    {
        $actor = $this->actor($request);
        $isReviewer = $actor !== null
            && $this->permissions !== null
            && $this->permissions->can($actor, 'workflow.review', "locale:{$locale}", []);
        return $this->transition(
            $request,
            $uuid,
            $locale,
            fn(string $a, ?string $n): array => $this->workflow->withdraw($uuid, $locale, $a, $isReviewer),
            requireNote: false,
        );
    }

    #[ApiOperation(summary: 'Review state + history for an entry/locale', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'State row (draft default) + recent history.')]
    public function show(Request $request, string $uuid, string $locale): Response
    {
        return Response::success($this->workflow->overview($uuid, $locale));
    }

    #[ApiOperation(summary: 'Review queue (in_review submissions)', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'Paginated in-review items enriched with draft summaries.')]
    public function queue(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min((int) $request->query->get('perPage', 25), 100));
        $result = $this->states->queuePage('in_review', $page, $perPage);

        $items = [];
        foreach ($result['items'] as $row) {
            $summary = $this->drafts->summary((string) $row['entry_uuid'], (string) $row['locale']);
            $items[] = [
                'entry_uuid' => (string) $row['entry_uuid'],
                'locale' => (string) $row['locale'],
                'submitted_by' => $row['submitted_by'] !== null ? (string) $row['submitted_by'] : null,
                'submitted_at' => $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
                'title' => $summary['title'] ?? null,
                'type_slug' => $summary['type_slug'] ?? null,
            ];
        }
        return Response::success([
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    /** @param callable(string, ?string): array<string,mixed> $fn */
    private function transition(
        Request $request,
        string $uuid,
        string $locale,
        callable $fn,
        bool $requireNote,
    ): Response {
        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::error('Unauthenticated.', 401);
        }
        if ($this->drafts->summary($uuid, $locale) === null) {
            return Response::error('Unknown entry or locale.', 404);
        }
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $note = WorkflowNoteDTO::fromRequest($body, $requireNote)->note; // throws 422

        try {
            return Response::success($fn($actor, $note));
        } catch (IllegalTransition $e) {
            return Response::error($e->getMessage(), 409, ['workflow_state' => $e->state]);
        } catch (WorkflowForbidden $e) {
            return Response::error($e->getMessage(), 403);
        }
    }

    private function actor(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user');
        return is_string($user['uuid'] ?? null) && $user['uuid'] !== '' ? $user['uuid'] : null;
    }
}
