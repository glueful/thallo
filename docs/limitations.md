# Known Limitations (Developer Preview)

Stated plainly so you can decide with open eyes. None of these are bugs; each is a deliberate
current boundary.

## One merchant account per installation

Payment gateway credentials are **platform-scoped**: every workspace on an installation
settles through the installation's single gateway account (Stripe and/or Paystack), including
workspace SaaS-subscription billing. There are no per-workspace gateway credentials. If your
model needs each workspace to settle to its own merchant account, Thallo does not do that
today.

## Paystack constraints

- **No session renewal.** Paystack provides no provider-confirmed way to prove an old
  checkout session dead, so Thallo never silently replaces one (two live payment URLs would
  risk double charges). A genuinely dead Paystack session surfaces a typed
  "renewal unavailable" state; recovery is the documented operator path (mark paid, or
  cancel with the risk acknowledgement and recreate).
- **Repricing is a hard stop.** If an order's total changes after a Paystack checkout session
  exists, initiation refuses rather than minting a second live URL. Stripe orders re-mint at
  the new total automatically.
- Your Paystack integration's `payment_session_timeout` must remain `0` (see
  [production](production.md)).

## Placed orders are not editable

Draft (walk-in) orders are fully editable until finalized; finalization locks them. A placed
order's totals are load-bearing (payment sessions, invoices, and stock claims derive from
them), so post-finalize remedies are: cancel (with the payment-session risk acknowledgement
where one was exposed) and recreate, mark paid, or refund — never in-place edits.

## No payment-link open/click analytics

The public payment-link landing page ships zero third-party assets and no tracking by design
(it carries a bearer credential in its URL). Platform analytics cover the rest of the site;
the link page itself is deliberately blind.

## Delivery channels

Payment links are delivered by email or copy-to-clipboard. SMS/WhatsApp channels are not
built yet.
