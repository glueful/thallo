<?php

declare(strict_types=1);

namespace Thallo\Contracts\Billing;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The pricing-blocks → billing deep-link bridge (workspace-checkout spec §5.4, verbatim
 * interface). Lets a `pricing_plan` block's authored `plan_key` resolve to a real admin
 * billing checkout URL WITHOUT thallo-render ever importing thallo-subscriptions — the
 * same soft-bound, contract-in-thallo-contracts pattern already established for
 * `AdminUrlProvider`/`MediaUrlResolver`/`StorefrontLinkResolver` and friends.
 *
 * Deliberately takes the CALLER's {@see ApplicationContext} rather than assuming any
 * context an implementation might have been constructed with — the request that is
 * rendering the block is the one whose capability/tenant/engine state must decide the
 * answer, never a stale context captured earlier.
 *
 * Makes NO existence or purchasability promise about `$planKey`: a well-formed but
 * UNKNOWN key still resolves to a real URL. The admin billing page is the single source
 * of truth that revalidates everything (including whether the plan actually exists)
 * before checkout — this seam exists purely to produce a deep link, never to query the
 * plan catalog at render time.
 */
interface PlanCheckoutUrlResolver
{
    /**
     * The admin billing checkout URL for `$planKey`, or null when the bridge is
     * unavailable — capability off, the subscriptions engine unavailable, or no
     * configured admin origin. Callers MUST treat null identically regardless of which
     * of those reasons produced it (the pricing_plan block falls back to its authored
     * `button_url` on any null).
     */
    public function resolve(ApplicationContext $context, string $planKey): ?string;
}
