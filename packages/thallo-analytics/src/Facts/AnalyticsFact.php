<?php

declare(strict_types=1);

namespace Thallo\Analytics\Facts;

/**
 * Immutable carrier of one analytics fact. Built by listeners, consumed by AnalyticsRecorder.
 */
final class AnalyticsFact
{
    /**
     * @param array<string, mixed> $metadata Small event context. MUST be empty for auth facts.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $category,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $actorType,
        public readonly ?string $actorId,
        public readonly float $occurredAt,
        public readonly array $metadata = [],
    ) {
    }

    /** True when the subject is a low-cardinality breakdown dimension (rolled by subject). */
    public function hasBreakdownSubject(): bool
    {
        return in_array($this->subjectType, ['collection', 'content_type'], true)
            && is_string($this->subjectId) && $this->subjectId !== '';
    }

    /** True when the actor is a human user (admin normalized to user); drives active_users. */
    public function isHumanActor(): bool
    {
        return in_array($this->actorType, ['user', 'admin'], true)
            && is_string($this->actorId) && $this->actorId !== '';
    }
}
