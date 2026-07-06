<?php

declare(strict_types=1);

namespace Thallo\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Thallo\Workflow\Events\ChangesRequested;
use Thallo\Workflow\Events\ReviewApproved;
use Thallo\Workflow\Events\ReviewSubmitted;

use function config;

/**
 * The single-stage review state machine (spec: 2026-07-02-approval-workflow-design.md §3).
 * Explicit transitions throw IllegalTransition (409) / WorkflowForbidden (403); the
 * automatic rules (invalidateOnEdit / recordPublish) are driven by the lifecycle listener.
 */
final class WorkflowService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly WorkflowStateRepository $states,
        private readonly EventService $events,
    ) {
    }

    /**
     * @return array{state:string, submitted_by:?string, submitted_at:?string,
     *   reviewed_by:?string, reviewed_at:?string, history: list<array<string,mixed>>}
     */
    public function overview(string $entryUuid, string $locale): array
    {
        $row = $this->states->find($entryUuid, $locale) ?? [];
        return [
            'state' => (string) ($row['state'] ?? 'draft'),
            'submitted_by' => isset($row['submitted_by']) ? (string) $row['submitted_by'] : null,
            'submitted_at' => isset($row['submitted_at']) ? (string) $row['submitted_at'] : null,
            'reviewed_by' => isset($row['reviewed_by']) ? (string) $row['reviewed_by'] : null,
            'reviewed_at' => isset($row['reviewed_at']) ? (string) $row['reviewed_at'] : null,
            'history' => $this->states->history($entryUuid, $locale),
        ];
    }

    /** @return array<string,mixed> */
    public function submit(string $entryUuid, string $locale, string $actor, ?string $note): array
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        if (!in_array($from, ['draft', 'changes_requested'], true)) {
            throw new IllegalTransition("Cannot submit for review from state \"{$from}\".", $from);
        }
        $this->states->setState($entryUuid, $locale, 'in_review', [
            'submitted_by' => $actor,
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->states->record($entryUuid, $locale, $from, 'in_review', 'submit', $actor, $note);
        $this->events->dispatch(new ReviewSubmitted($entryUuid, $locale, $actor));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function approve(string $entryUuid, string $locale, string $actor, ?string $note): array
    {
        $row = $this->requireState($entryUuid, $locale, 'in_review', 'approve');
        $allowSelf = (bool) config($this->context, 'workflow.allow_self_review', false);
        if (!$allowSelf && (string) ($row['submitted_by'] ?? '') === $actor) {
            throw new WorkflowForbidden('The submitter cannot approve their own submission.');
        }
        $this->states->setState($entryUuid, $locale, 'approved', [
            'reviewed_by' => $actor,
            'reviewed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->states->record($entryUuid, $locale, 'in_review', 'approved', 'approve', $actor, $note);
        $this->events->dispatch(new ReviewApproved($entryUuid, $locale, $actor));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function requestChanges(string $entryUuid, string $locale, string $actor, string $note): array
    {
        $this->requireState($entryUuid, $locale, 'in_review', 'request changes on');
        $this->states->setState($entryUuid, $locale, 'changes_requested', [
            'reviewed_by' => $actor,
            'reviewed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->states->record(
            $entryUuid,
            $locale,
            'in_review',
            'changes_requested',
            'request_changes',
            $actor,
            $note,
        );
        $this->events->dispatch(new ChangesRequested($entryUuid, $locale, $actor, $note));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function withdraw(string $entryUuid, string $locale, string $actor, bool $actorIsReviewer): array
    {
        $row = $this->requireState($entryUuid, $locale, 'in_review', 'withdraw');
        if (!$actorIsReviewer && (string) ($row['submitted_by'] ?? '') !== $actor) {
            throw new WorkflowForbidden('Only the submitter or a reviewer may withdraw a submission.');
        }
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, 'in_review', 'draft', 'withdraw', $actor);
        return $this->overview($entryUuid, $locale);
    }

    /** Spec rule: edits invalidate ACTIVE review/approval; changes_requested survives. */
    public function invalidateOnEdit(string $entryUuid, string $locale, ?string $actor): void
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        if (!in_array($from, ['in_review', 'approved'], true)) {
            return;
        }
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, $from, 'draft', 'edit_invalidated', $actor);
    }

    /**
     * Single history writer for publishes (spec §4): approved → 'published'; anything else
     * necessarily passed the gate via bypass → 'published_with_bypass'. Then the approval
     * is consumed: state resets to draft.
     */
    public function recordPublish(string $entryUuid, string $locale, ?string $actor): void
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        $action = $from === 'approved' ? 'published' : 'published_with_bypass';
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, $from, 'draft', $action, $actor);
    }

    /** @return array<string,mixed> the current row */
    private function requireState(string $entryUuid, string $locale, string $required, string $verb): array
    {
        $row = $this->states->find($entryUuid, $locale) ?? ['state' => 'draft'];
        $state = (string) ($row['state'] ?? 'draft');
        if ($state !== $required) {
            throw new IllegalTransition("Cannot {$verb} a submission in state \"{$state}\".", $state);
        }
        return $row;
    }

    private function resetToDraft(string $entryUuid, string $locale): void
    {
        $this->states->setState($entryUuid, $locale, 'draft', [
            'submitted_by' => null,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }
}
