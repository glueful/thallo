<?php

declare(strict_types=1);

namespace Thallo\Workflow;

use Thallo\Contracts\Events\ContentLifecycleEvent;

/**
 * Automatic state rules over the CONTRACT lifecycle surface — name()/payload() only,
 * never the app engine's concrete event classes (pack boundary).
 *   entry.updated   → edits invalidate active review/approval (changes_requested survives)
 *   entry.published → record published / published_with_bypass, consume the approval
 */
final class WorkflowLifecycleListener
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    public function onContentChanged(ContentLifecycleEvent $event): void
    {
        $payload = $event->payload();
        $entry = $payload['entry'] ?? null;
        $locale = $payload['locale'] ?? null;
        if (!is_string($entry) || $entry === '' || !is_string($locale) || $locale === '') {
            return;
        }
        $actor = is_string($payload['actor'] ?? null) ? $payload['actor'] : null;

        match ($event->name()) {
            'entry.updated' => $this->workflow->invalidateOnEdit($entry, $locale, $actor),
            'entry.published' => $this->workflow->recordPublish($entry, $locale, $actor),
            default => null,
        };
    }
}
