---
title: "Take online payments"
slug: payments
section: guides
order: 21
summary: "Turn Payments on, enter your gateway keys, and know what turning it off stops and keeps."
---

At the end of this page the install takes online payments: a shop checkout and a payment link
open the gateway's payment page, and a workspace can buy a plan. You will also know exactly what
turning Payments off does to orders and subscriptions already in flight.

You need an operator account holding `system.access`, for Extensions, and `tenancy.manage`, for
**Settings › Payments**, and the install's real HTTPS origin in `BASE_URL`.

## Turn Payments on

Go to **Extensions › Capabilities**, switch **Payments** on and confirm. Payments is a capability
backed by `glueful/payvia`, the extension that talks to the payment gateways, and it turns on like
Commerce: one "Turning on Payments…" state enables and migrates Payvia, checks it in a fresh
request, and switches Payments on. You never enable Payvia yourself: **Extensions › Installed**
shows it as managed by Payments, and `php glueful extensions:enable glueful/payvia` refuses and
names Extensions instead.

From a shell, the same activation is one command:

```bash
$ php glueful thallo:capabilities:enable thallo.payments
```

The steps, the failure states and the read-only-host variant are the ones
[capabilities and packs](../concepts/06-capabilities.md#turning-on-a-capability-with-an-activation)
describes.

## Enter your gateway keys

Open **Settings › Payments**. With Payments on it shows **Default gateway** and a card per gateway
(`paystack`, `stripe`) holding a switch, **Secret key**, the **Webhook URL** to paste into the
provider's dashboard, and **Webhook secret**. Keys are stored encrypted and write-only: a stored
key shows as `•••••••• (stored)` and can be replaced or cleared, never read back.

Paste the **Webhook URL** into the provider's dashboard. A payment counts as paid only when the
provider's webhook lands, never because the buyer came back from the payment page.

[Sell products](18-commerce.md) covers checkout and payment links, and
[charge for plans](19-subscriptions.md) covers a workspace buying a plan.

## What turning Payments off stops

Turning Payments off stops every new online payment. Each place that would start one asks the same
question and gets the same answer:

- **Shop checkout** still places the order, and the confirmation page shows the manual payment
  instructions instead of sending the buyer to a gateway. An operator marks the order paid from
  its page. The gateway is never contacted.
- **Send payment link** on an order is refused with "Payments is off: customers pay by manual
  collection. Turn Payments on in Extensions to take online payments."
- **A payment link already sent** opens on "Online payment isn’t available for this order"
  instead of starting a payment.
- **Billing** in a workspace says "Online payments are off on this platform" and offers no
  **Subscribe**. A pricing block's button no longer leads to checkout; it uses the block's own
  button link if it has one.

**Settings › Payments** says "Payments is off" with a link to Extensions. Your saved gateway keys
stay on the page, and stay stored.

## What turning Payments off keeps

Payments that were already under way finish. Turning Payments off doesn't cancel anything at the
provider:

- **Webhooks** keep landing and settle payments started before you turned it off: the order is
  paid, the payment link is used up.
- **Refunds** and an order's payment record keep working.
- **Subscriptions already billed by your provider** keep renewing, and each renewal still reaches
  the workspace's subscription. Cancel a subscription from its own page if you want it to stop.
- **A checkout a workspace already started** can still be abandoned or resolved from Billing.
- The scheduled jobs that clean up stale payment attempts keep running.

Turn Payments on again and online payments resume with the same keys. Turning it on again skips
the steps already done.

## A site that already used Payvia

On an install that had Payvia enabled with its schema ready before Payments was a capability,
`php glueful thallo:provision` keeps Payments on when you upgrade. Until provision runs, Payments
reads off and checkout collects manually. A site that never enabled Payvia starts with Payments
off.

## Check it worked

Switch Payments on, set a gateway's keys, and place a test order on the shop with the provider's
test card. The order's page shows the payment as pending until the webhook lands, then paid.
`php glueful thallo:capabilities:status` lists Payments as on.
