---
title: "Charge for plans"
slug: subscriptions
section: guides
order: 19
summary: "Define plans, show pricing on a page, and let a workspace subscribe."
---

At the end of this page the install has a plan catalogue, a pricing section on a public page whose
buttons lead to a real checkout, and a workspace paying for a plan through the install's payment
gateway.

You need a shell on the install and a platform operator account — one holding the `tenancy.manage`
permission. Subscriptions charge a **workspace** for using the install. They are not a way to
charge a visitor for content; that is [Commerce](18-commerce.md), and visitor accounts are
[accounts](17-accounts.md).

## What subscribes, and to what

The subscriber is a [workspace](../concepts/08-workspaces.md). On an install that has never turned
workspaces on there is still exactly one — the site itself — and everything here applies to it.

The catalogue is platform-wide: one set of plans, shared by every workspace on the install. A plan
carries a **plan key** (its permanent id), a display name, a status, and a map of **entitlements**
— named keys, each granted, denied, limited to a whole number, or unlimited. Nothing in Thallo
gates its own features on entitlements; they are there for your own code to read. The one effect
that is already wired up is API rate limiting: a granted `rate.tier.{tier}` entitlement, for a tier
named in the `rate_tiers` key of the `subscriptions` config, puts the workspace on that tier.

**Subscriptions** is on by default, and `glueful/subscriptions` — the engine behind it — is one of
the extensions a new install enables. Check **Extensions › Capabilities**: if the row is not **On**,
[capabilities and packs](../concepts/06-capabilities.md) explains why. The admin's Subscriptions
pages tell you plainly when the engine is disabled or its migrations have not run.

## Enable payments

A plan can be assigned by an operator with no payment provider at all. For a workspace to buy one
itself, the install needs `glueful/payvia` enabled and a gateway that supports subscription
checkout:

```bash
$ php glueful extensions:enable glueful/payvia
```

Then enter the gateway's keys in **Settings › Payments**, exactly as
[sell products](18-commerce.md#enter-your-gateway-keys) describes, and paste the **Webhook URL** it
shows into the provider's dashboard. The webhook is not optional here: a finished checkout becomes
an active subscription only when the provider's webhook lands. Nothing about returning from the
payment page activates anything.

The provider is also told where to send the buyer back, so the admin's own address has to be
resolvable. Set `BASE_URL` to the install's real HTTPS origin —
[install](../getting-started/02-install.md#why-base-url-matters) covers what else it governs. Leave
**Admin URL** in **Settings › General** empty, which means the admin on this site, unless you host
the admin elsewhere. With neither set, checkout is refused before it starts.

## Create a plan

1. Go to **Subscriptions › Plans** and press **New plan**.
2. Fill in **Plan key**. It is lower-case letters, digits, `.`, `_` and `-`, and it can never be
   changed afterwards — the field is disabled once the plan exists.
3. Fill in **Display name**, and **Description** if you want one.
4. Set **Status** to `active`. A `draft` or `archived` plan is never purchasable.
5. Add **Entitlements**. Each row is a key and one of **Granted**, **Denied**, **Limited** (which
   takes a whole number) or **Unlimited**.
6. Add a **Provider identifiers** row: the gateway's key on the left (`stripe`, `paystack`) and the
   price or plan id from that provider's dashboard on the right. Until a plan has one, the editor
   says so — "this plan isn't purchasable through any gateway" — and it is exactly true.
7. Fill in **Price** if the plan picker should say what it costs: the amount (`19.99`), the
   three-letter currency (`USD`) and **per month**, **per year**, **per week** or **per day**. It is
   for display only; the provider charges whatever its price or plan id says, so keep the two in
   step. Leave it empty for a plan with no price shown.
8. Press **Create**.

**Provider price ID** is a separate, older field. It correlates webhooks for subscriptions that
already existed; it is never read to decide purchasability. Use **Provider identifiers**.

The plans table lists each plan's name, key, status and sort order. **Edit** reopens the editor.
**Archive** retires a plan: everyone already on it keeps their access, and nobody new can start on
it.

**Import from config** seeds the catalogue from the `plans` block of the `subscriptions` config
instead. Plans that already exist are skipped unless you tick **Overwrite plans that already
exist**. The engine ships two example plans there, `free` and `pro`; write your own into
`config/subscriptions.php`, which is merged over those defaults, so the two shipped keys stay
unless your file uses the same names. Seeded plans carry no provider identifier: add one in the
editor before expecting anyone to buy them.

## Let workspaces buy a plan

Open **Subscriptions › Billing**. The **Self-serve checkout** switch at the top is the platform
kill switch: off, no workspace can start its own checkout, and its Billing page says so.

The switch will not turn on until the install's default gateway supports subscription checkout. If
it cannot, the panel says which gateway is configured and why it does not qualify. Turning the
switch off is always allowed.

Below the switch is the workspace directory: name, workspace status, plan and subscription status,
one row per workspace. On an install without workspaces turned on, it is a single **This site's
plan** panel instead. Either way, opening a workspace gives you the same controls:

- **Plan** and **Start subscription** (or **Change plan**) — assign a plan directly, with no
  payment. This is how you grant a complimentary or invoiced plan.
- **At period end** and **Cancel subscription**.
- **Entitlement overrides** — a key, its value, an optional expiry date and a reason. An override
  sits on top of the plan for that workspace alone. Expired overrides stay listed, marked
  `(expired)`.

A subscription the payment provider is managing cannot be changed here. Those controls are disabled
and the panel says "Managed by a payment provider": cancel it from the workspace's own Billing
page, or in the provider's dashboard.

## Put pricing on a page

Open the entry in the [Design view](../concepts/03-design-view.md). The fastest route is the Blocks
tab's **Sections** view, where **Pricing plans** inserts a heading and three plan cards, and the
**Pages** view's **Pricing** inserts a whole page around it. [Use the section and page
library](04-sections-and-pages.md) covers both.

To build one by hand, the **Content** group of the Blocks tab has **Pricing plans** — a row or
stack that holds **Pricing plan** cards — and **Pricing table**, a feature-comparison grid of
**Pricing tier** columns and **Pricing feature** rows.

Select a **Pricing plan** card and open the **Block** tab. Its **price**, **billing period**,
**billing cycle**, **features** (one per line), **badge**, **button label** and **button url** are
all yours to type. The field that matters here is **plan key**: type the key of a plan in the
catalogue, and the card's button stops using **button url** and links to the admin's signup page
with that plan chosen — `/signup?plan={plan key}` under the **Admin URL**.

On that page a visitor names their workspace and its address, enters their name, email and a
password, and confirms the code emailed to them. The workspace is created with them as its owner,
they are signed in, and they land on **Workspace billing** with the plan chosen, ready to pay. A
visitor who is already signed in goes straight there. The page needs **Workspace signup** on, under
**Settings › Workspaces**, which itself needs multi-workspace mode and working email; while it is
off, the page says signup is unavailable when the form is sent.

Thallo checks nothing about the key beyond its shape. If the capability is off, the engine is
unavailable, no admin address is configured, or the field is empty or malformed, the button falls
back to **button url** — so fill that in too. A key matching no purchasable plan still produces a
working link; the billing page refuses it on arrival.

Only **Pricing plan** has a **plan key**. A **Pricing tier** in a pricing table links wherever its
**button url** points. A custom theme can build the same link itself with the
`plan_checkout_url()` template function; see [template
functions](../reference/03-template-functions.md).

## What the workspace does

The person who buys the plan needs the **Manage billing** permission in that workspace. The
built-in `owner` role has it; `admin`, `member` and `viewer` do not, and neither does a platform
operator acting from outside — the two authorities are deliberately separate. A workspace can
delegate it to a custom role in **Workspaces › Roles**. See the [permissions
reference](../reference/06-permissions.md).

They go to **Subscriptions › Workspace billing**, choose a plan and press **Subscribe**. Thallo
sends them to the provider's hosted checkout page. When they are done, the provider returns them to
**Checkout**, at `/billing/return`, which polls until the webhook has landed and then reports the
subscription active. There is nothing to press.

Three things refuse a checkout before it reaches the provider, each with its own message: the
workspace already has an active subscription, the plan carries no identifier for the configured
gateway, or the account has no verified email address on file.

If a checkout is left half-finished, the page shows **A checkout is already in progress** with
**Resume checkout** and **Abandon checkout**. Paystack cannot confirm a session dead, so abandoning
is unavailable there and the page says so — resume it, or ask the operator. Once a subscription is
running, the workspace can **Cancel subscription**, choosing **Cancel at the end of the billing
period** or **Cancel immediately**.

**Change plan** opens a list of the other purchasable plans. On Stripe the choice takes effect at
once: the subscription moves to the new plan's price, the difference is prorated on the next
invoice, and a pending cancellation is withdrawn. The page shows the new plan once the provider's
webhook lands. Paystack cannot move a subscription between plans, so there the dialog says so and
offers **Cancel at period end** instead; subscribe to the new plan once access ends.

When a checkout sticks and neither side can move it, the operator resolves it from a shell:

```bash
$ php glueful subscriptions:checkout:resolve 3f9a1c2d4e5b --resolution=provider_confirmed_dead --note="support ticket 412"
```

The uuid is the **Reference** printed on the Checkout page. `--note` is required. The other
resolution is `provider_canceled_or_refunded`. The command refuses to resolve a checkout that in
fact succeeded.

## Check it worked

**Subscriptions › Billing** shows the workspace with its plan name and a status of `active`. The
workspace's own **Subscriptions › Workspace billing** shows the plan and its renewal date, with
**Cancel subscription** beside it.

One boundary to know before you sell anything: every workspace on an install settles through the
install's single gateway account. There are no per-workspace merchant accounts. See [known
limitations](../limitations.md).
