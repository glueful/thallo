<?php

declare(strict_types=1);

namespace Thallo\Analytics\Listeners;

use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Thallo\Analytics\Facts\AnalyticsFact;
use Thallo\Analytics\Facts\AnalyticsRecorder;

/**
 * Maps framework auth events to analytics facts. ALLOW-LIST ONLY: never reads token accessors
 * (getTokens/getAccessToken/getRefreshToken) and never records an attempted username. Auth facts
 * carry only the event name + (for login/logout) the user uuid; metadata is always empty.
 */
final class AuthAnalyticsListener
{
    public function __construct(private readonly AnalyticsRecorder $recorder)
    {
    }

    public function onLogin(SessionCreatedEvent $event): void
    {
        $uuid = $event->getUserUuid();
        $this->recorder->record(new AnalyticsFact(
            event: 'auth.login',
            category: 'auth',
            subjectType: 'user',
            subjectId: $uuid,
            actorType: 'user',
            actorId: $uuid,
            occurredAt: $event->getTimestamp(),
        ));
    }

    public function onLogout(SessionDestroyedEvent $event): void
    {
        $uuid = $event->getUserUuid();

        if ($uuid === null) {
            $this->recorder->record(new AnalyticsFact(
                event: 'auth.logout',
                category: 'auth',
                subjectType: null,
                subjectId: null,
                actorType: null,
                actorId: null,
                occurredAt: $event->getTimestamp(),
            ));
            return;
        }

        $this->recorder->record(new AnalyticsFact(
            event: 'auth.logout',
            category: 'auth',
            subjectType: 'user',
            subjectId: $uuid,
            actorType: 'user',
            actorId: $uuid,
            occurredAt: $event->getTimestamp(),
        ));
    }

    public function onLoginFailed(AuthenticationFailedEvent $event): void
    {
        // Count only — no attempted username (unverified PII), no actor.
        $this->recorder->record(new AnalyticsFact(
            event: 'auth.login_failed',
            category: 'auth',
            subjectType: null,
            subjectId: null,
            actorType: null,
            actorId: null,
            occurredAt: $event->getTimestamp(),
        ));
    }
}
