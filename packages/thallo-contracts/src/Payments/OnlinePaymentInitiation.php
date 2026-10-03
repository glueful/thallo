<?php

declare(strict_types=1);

namespace Thallo\Contracts\Payments;

/**
 * The one question every place that starts an online payment asks first: may a new online payment
 * start? Yes only while the Payments capability is effective. When it may not, the caller answers
 * as manual collection. Settlement of payments already started, webhooks, reconciliation, refunds
 * and records never ask.
 */
interface OnlinePaymentInitiation
{
    public function allowed(): bool;

    /** Why it may not, for the caller's message; null while it may. */
    public function refusal(): ?string;
}
