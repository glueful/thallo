---
title: "Sell products"
slug: commerce
section: guides
order: 18
summary: "Switch on the store, add a product, take a payment, and see the order."
---

At the end of this page the site has a shop at `/shop`, one product on sale, a cart and a
checkout a visitor can complete, and the resulting order in the admin under
**Commerce › Orders**.

You need a shell on the install, an admin account, and a theme rendering the public site. The
storefront is served by Thallo's own templates through your theme's `layout.twig`, so
[rendered delivery](../concepts/06-capabilities.md) has to be on.

## Switch on Commerce and Payvia

Two extensions ship installed and disabled: `glueful/commerce`, which owns products, stock, carts
and orders, and `glueful/payvia`, which talks to the payment gateways. Run all four commands:

```bash
$ php glueful extensions:enable glueful/commerce
$ php glueful extensions:enable glueful/payvia
$ php glueful migrate:run
$ php glueful thallo:provision
```

`extensions:enable` migrates each extension's own schema and rewrites `config/extensions.php`.
`migrate:run` then creates Thallo's commerce tables — the product-to-entry link, the product slug
ledger, the checkout-attempt ledger and the payment-link delivery log — and declares two
permissions, `commerce.view` and `commerce.manage`. `thallo:provision` grants every declared
permission to the `superuser` and `administrator` roles; without it the Commerce screens load and
every request behind them is refused.

Reload the admin. **Extensions › Capabilities** now shows **Commerce** as **On**, and the sidebar
has a **Commerce** section: **Overview**, **Products**, **Orders**, **Discounts**, **Reviews**,
**Customers**, **Settings**. Payvia adds no menu of its own.

To check the integration from a shell:

```bash
$ php glueful thallo:commerce:diagnose
```

```text
OK commerce_active: "Commerce provider is active."
OK stale_links: {"stale_count":0,"by_tenant":[]}
OK marketplace: "Marketplace is disabled (supported default)."
```

## Set the currency and the store details

Open **Commerce › Settings**, **Store** tab. **Currency** is an ISO 4217 code (`USD` unless you
set `COMMERCE_CURRENCY`) and every variant price must match it, so set it before you price
anything. The rest of the tab: **Tax rate (%)** (0 by default), **Order number format**
(`ORD-{seq}`), **Order payment window (minutes)** (60), **Cart lifetime (days)** (30),
**Low-stock threshold**, **Download link lifetime (seconds)**, and **Store name**, **Tax ID** and
**Business address**, which print on invoices and order emails. Press **Save settings**.

The **Store pages** card above it lists the four public paths — **Shop**, **Wishlist**, **Cart**,
**Checkout** — and is read-only. The other tabs are **Invoices & receipts**, **Emails**,
**Marketplace**, **Shipping zones**, **Shipping classes** and **Tax rates**.

## Enter your gateway keys

Open **Settings › Payments**. With no gateway extension enabled the page reads **Manual
collection** and nothing else; with Payvia it shows **Default gateway** and a card per gateway
(`paystack`, `stripe`) holding a switch, **Secret key**, the **Webhook URL** to paste into the
provider's dashboard, and **Webhook secret**. Keys are stored encrypted and write-only: a stored
key shows as `•••••••• (stored)` and can be replaced or cleared, never read back.

The page carries a standing notice, "Every workspace settles through one platform gateway
account". Credentials are platform-wide — see [known limitations](../limitations.md).

Payment links refuse to work on a misconfigured origin. A link's landing URL is composed from the
install's canonical public origin — `BASE_URL` on a single-store install, never the request's Host
header. If that origin is not `https://`, or carries a non-default port, no link is minted at all.
Set `BASE_URL` to the real HTTPS origin first;
[install](../getting-started/02-install.md#why-base-url-matters) covers the rest of what it
governs.

## Add a product

1. Go to **Commerce › Products** and press **New product**.
2. Type the name into the one input, ending with a price — "Aurora Desk Lamp 89.99". The chips
   under it show what will be created: the name, the slug, the SKU and the price. A bare whole
   number stays part of the name unless you mark it with the currency code.
3. Pick a type: **Physical**, **Digital**, **External** (which needs an **External link**) or
   **Grouped**. Press **Create**.
4. You land in the product editor, on a draft. Its sections are **Details**, **Images**,
   **Pricing & stock**, **Organization**, then **Add-ons**, **Downloads** (digital products only)
   and **Linked content**.
5. Open **Pricing & stock**. A product created from the launcher has one variant, with the slug
   as its SKU. **Add variant** takes a **SKU**, **Price**, **Currency**, **Status** and an
   optional **Original price**; the stock control takes a **Delta** (positive to add, negative to
   remove) and a **Reason**.
6. Press **Publish** in the bar at the top. The product's status goes from `draft` to `active`,
   and **View in store** appears beside it.

**Linked content** attaches a Thallo entry to the product for editorial copy. Commerce stays
authoritative for price, stock and orders; the entry only enriches. The **Product story** content
type ships for this, with a localised **headline**, a rich-text **summary** and a blocks body. A
product with no linked entry renders from Commerce data alone.

## Put the shop on the site

The storefront routes exist as soon as the capability is on — nothing to create:

| Path | Page |
|---|---|
| `/shop` | Shop index |
| `/shop/products/{slug}` | Product detail |
| `/shop/categories/{slug}` | Category archive |
| `/shop/wishlist` | Wishlist |
| `/cart` | Cart |
| `/checkout` | Checkout |

`THALLO_COMMERCE_SHOP_PREFIX` in `.env` renames the first segment; `/cart` and `/checkout` are
fixed. All of them are reserved paths, so a page built in the Design view can never shadow one.
A request for a product's old slug after a rename is a 301 to its current URL.

To pull the shop into an ordinary page, open its entry in the [Design view](../concepts/03-design-view.md)
and insert from the **Commerce** group of the Blocks tab:

| Block | What it inserts | Its settings |
|---|---|---|
| **Product grid** | A grid of products | **source** (`category`, `tag`, `manual`, `newest`), **category slug**, **tag slug**, **products** (one slug per line), **page size** |
| **Featured product** | One product, spotlit | **product slug** |
| **Add to cart** | An add-to-cart control | **product slug**, blank to use the product linked to the current entry |
| **Mini cart** | A cart count and drawer | none |
| **Wishlist link** | A link to the wishlist with a saved count | **label** |

Each renders a shell server-side and fetches its data afterwards, so a page carrying one stays
cacheable.

## Cart and checkout

Adding to the cart mints a cart token into a cookie marked `Secure`, `HttpOnly` and
`SameSite=Lax`, living as long as **Cart lifetime (days)**. Serve the storefront over HTTPS or the
browser will drop it. The cart and checkout forms are real HTML forms that work with JavaScript
off; the shipped `shop.js` intercepts the same forms and updates the page in place. Replaying an
add converges on one line at the submitted quantity rather than doubling it.

Checkout collects a **Contact** email, a shipping address and a shipping method, with an **Order
summary** beside them. What happens on submit depends on the gateway:

- With a gateway configured, the browser is redirected to the provider's hosted checkout page.
- With none, the confirmation page shows Commerce's manual instructions — "Payment is collected
  manually; an operator will mark this order paid."

Returning from the provider never marks an order paid. `/checkout/return/{ref}` and
`/checkout/cancel/{ref}` re-read the order's server-side state and redirect to the confirmation
page; only a verified provider webhook moves an order out of `pending_payment`. Your webhook
endpoint has to be reachable over HTTPS or every online order waits for a manual confirmation.

Catalog pages are cached per tenant, locale, theme and path; `/cart`, `/checkout` and the
confirmation pages are never cached.

## See the order

**Commerce › Orders** lists finalised orders with filters for status, fulfilment and date, and a
search over the order number and the email. An order's status is one of `pending_payment`, `paid`,
`fulfilled`, `canceled` or `refunded`; fulfilment is `unfulfilled`, `partial` or `fulfilled`.
**Create order** starts a walk-in order and **Drafts** lists the unfinished ones.

Open an order and the actions on it are **Mark paid**, **Fulfill** — which takes an optional
**Tracking reference** — and **Refund**. Each asks for a confirmation first.

The **Payment link** card mints a customer-payable URL for an unpaid order. **Expires in (days)**
sets its life, and **Create payment link** shows the address exactly once: copy it then, because
it cannot be shown again and the only way to get another is to regenerate the link, which kills
the old one. The link's page carries a bearer token in its path, so keep it out of your reverse
proxy's access log.

## Add the cron entries

Commerce brings sweeps of its own. They are not in `config/schedule.php`, so add them to the
crontab beside [the scheduler](../operations/03-scheduler-and-queues.md):

```text
*/15 * * * * php /path/to/site/glueful commerce:orders:expire
0 3 * * * php /path/to/site/glueful payvia:intents:sweep-stale
0 4 * * * php /path/to/site/glueful thallo:commerce:checkout:purge-attempts
```

`commerce:orders:expire` cancels unpaid orders past the **Order payment window**, cancels stale
drafts, and hard-deletes cancelled draft artifacts older than `commerce.orders.draft_purge_days`.
`payvia:intents:sweep-stale` frees abandoned payment attempts; on an install with workspaces it
needs `--tenant`. `thallo:commerce:checkout:purge-attempts` deletes checkout-attempt rows past
`THALLO_COMMERCE_GUEST_CONFIRMATION_DAYS` (30). A fourth,
`php glueful thallo:commerce:links:reconcile`, clears product-to-entry links whose product or
entry has gone; run it if `thallo:commerce:diagnose` reports a stale count.

## What the store does not do yet

- **Marketplace mode is unsupported.** The **Marketplace** settings tab exists, but
  `thallo:commerce:diagnose` reports an enabled marketplace as a warning.
- **One merchant account per install.** Every workspace settles through the same gateway account.
- **A placed order cannot be edited.** Drafts are editable until you finalise them; after that the
  remedies are cancel, mark paid or refund.
- **No product import.** The create page's import card says CSV import arrives with the product
  importer.
- **Customers are read-only.** **Commerce › Customers** has no mutation behind it.
- **Payment links go out by email or clipboard.** There is no SMS or WhatsApp channel.

Paystack adds constraints of its own — among them, its integration's `payment_session_timeout`
must stay at `0`. They are in [known limitations](../limitations.md); the operational obligations
of taking money are in [running Thallo in production](../production.md).

## Check it worked

Open `/shop` as a visitor. The product you published is listed; its page adds to the cart, `/cart`
shows the line, and `/checkout` places an order. The order appears in **Commerce › Orders** within
seconds, at `pending_payment` for an online payment or after **Mark paid** for a manual one, and
**Commerce › Overview** counts it under **Sales summary**.
