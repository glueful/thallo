---
title: "Notify other systems with webhooks"
slug: webhooks
section: guides
order: 16
summary: "Register an endpoint, verify the signature Thallo signs its requests with, and read the delivery log."
---

A webhook posts a content event to a URL you own, so another system hears that an entry was
published instead of polling for it. You register the endpoint in the admin, and Thallo signs
every request with a secret only you and it hold.

A content event is recorded in the delivery log and queued on the `webhooks` queue, and a queue
worker posts it. Run a worker that takes that queue, as in
[the scheduler and queues](../operations/03-scheduler-and-queues.md): without one, deliveries
wait at **Pending**. **Send test event** skips the queue and posts straight away.

You need an endpoint on a public `https://` URL that accepts POST, a queue worker that takes the
`webhooks` queue, and a user with the `system.access` permission.

## Switch content webhooks on

Open **Settings › General**, find the **Feature toggles** card, and check that **Content
webhooks** is on. It is on by default. Press **Save**. Setting `WEBHOOKS_ENABLED=false` in `.env`
stops dispatch whatever the switch says.

## Register the endpoint

1. Open **Developers › Webhooks** and press the plus button beside the list heading. The **New
   webhook** dialog opens.
2. Type the **Endpoint URL**. It must begin `https://`: the API refuses anything else while
   `WEBHOOKS_REQUIRE_HTTPS` is true, which is its default.
3. Choose the **Events**. The menu offers the ten event names below and four patterns — `*`,
   `entry.*`, `model.*` and `asset.*`. Pick at least one.
4. Press **Create webhook**.

**Your webhook signing secret** appears once: a string beginning `whsec_`. Copy it into your
receiver's configuration now. No later read returns it, and the only way to get another is
**Rotate signing secret**, which stops this one working.

The webhook joins the list under its host, with its event count and an **Active** badge.

## The events

| Event | Fired when |
|---|---|
| `entry.created` | An entry is created |
| `entry.updated` | A draft save changes an entry's fields |
| `entry.published` | A version is pinned, including a restore from **Versions** |
| `entry.unpublished` | The pin is removed |
| `entry.deleted` | An entry is deleted |
| `model.created` | A content type is created |
| `model.updated` | A content type is changed |
| `model.deleted` | A content type is deleted |
| `asset.attached` | A draft save points an asset field at a media file |
| `asset.detached` | A draft save stops pointing an asset field at one |

The names are fixed, and a webhook receives an event only if one of its patterns matches the
name. Everything happens after the database transaction has committed, so an event means the
change is already durable. See [drafts, preview and publishing](../concepts/05-publishing.md) for
what else a publish sets off.

## What Thallo posts

The request is a POST with these headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Webhook-ID` | The delivery's uuid, beginning `wh_del_` |
| `X-Webhook-Event` | The event name |
| `X-Webhook-Timestamp` | Unix seconds, the same value the signature carries |
| `X-Webhook-Signature` | `t={timestamp},v1={hex digest}` |
| `User-Agent` | `Glueful-Webhooks/1.0` |

The body:

```json
{
  "id": "wh_evt_3bQ7xm2Rk9LpVd4sZtHc",
  "event": "entry.published",
  "created_at": "2026-02-11T09:30:00+00:00",
  "data": {
    "entry": "0f3c9d2a1b7e",
    "type": "5c81aa07d4e2",
    "locale": "en",
    "version": 4,
    "actor": "u7d21f0a934b",
    "timestamp": 1770802200.4471
  }
}
```

`data` is the event's own payload, and it carries identity only — never the entry's field values.
A receiver that needs the content re-reads it from [the content API](../concepts/07-api.md) with its
own key.

| Key | In | Value |
|---|---|---|
| `entry` | entry and asset events | The entry's uuid |
| `type` | entry events | The content type's **uuid** |
| `type` | model events | The content type's **slug** |
| `locale` | entry events | The locale the change was made in, or `null` |
| `version` | entry events | The version number, or `null` where there is none yet |
| `asset` | asset events | The media file's uuid |
| `actor` | all | The uuid of the user who acted, or `resync` from `thallo:resync --webhooks` |
| `timestamp` | all | When the event was made, Unix seconds with a fraction |

## Verify the signature

`X-Webhook-Signature` is `t=<unix seconds>,v1=<hex>`. The `v1` digest is HMAC-SHA256 of the string
`<timestamp>.<raw body>`, keyed with the signing secret. Verify against the raw body, before any
parse or re-encode — a reserialised body produces a different digest.

```php
$raw = file_get_contents('php://input');
$header = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

$parts = [];
foreach (explode(',', $header) as $item) {
    [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
    $parts[$k] = $v;
}

$timestamp = (int) ($parts['t'] ?? 0);
$expected = hash_hmac('sha256', $timestamp . '.' . $raw, WEBHOOK_SECRET);

$valid = isset($parts['v1'])
    && hash_equals($expected, $parts['v1'])
    && abs(time() - $timestamp) <= 300;
```

The timestamp is in the signed string, so it cannot be moved without breaking the digest. Reject a
request whose timestamp is far from now: Thallo's own verifier allows five minutes either way.
Compare digests with a timing-safe function, as above. Answer 2xx to accept — any other status
counts as a failure.

## Send a test event

Open the webhook and press **Send test event** under **Actions**. Thallo posts to the endpoint
during the request and waits for the answer, then reports "Test delivered" with the status code
your endpoint returned, or "Test delivery failed" with the reason. Like a real delivery, it refuses
an endpoint on `localhost` or a private or reserved address before sending anything; to test a
receiver on your own machine, expose it through a public tunnel.

The test request is not shaped like a content event, which matters when you write the receiver:

- the event name is `webhook.test`, and it is sent whatever events the webhook lists;
- the body has no `id` and no `created_at`, and its `data` is the same every time;
- there is no `X-Webhook-ID` header;
- it is signed exactly as a content event is, with the same secret, so a signature check gets a
  real exercise;
- it is not written to the delivery log.

The whole body:

```json
{
  "event": "webhook.test",
  "timestamp": 1770802200,
  "data": {
    "message": "This is a test webhook",
    "test": true
  }
}
```

## Read the delivery log

**Recent deliveries**, under the webhook's actions, lists what has been recorded for it, newest
first, with the event name, when it was made, the attempt count and the response code. The buttons
above filter by status: **All**, **Delivered**, **Failed**, **Retrying**, **Pending**. A row opens
a **Delivery** dialog holding Event, Status, Attempts, Response code, Created, Delivered, Next
retry, the **Payload** exactly as it was built, and the **Response body** when there is one. A
failed or retrying delivery has a **Retry** button.

Above it, **Last 30 days** counts Success, Delivered, Failed and Pending for the webhook.

A delivery your endpoint does not accept with a 2xx is marked **Retrying** and sent again on its
own after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours; **Next retry** shows when. After
five attempts it is marked **Failed**. **Retry** sends a failed or retrying delivery again at once.

Records do not pile up: the nightly `webhook_cleanup` job deletes delivered ones after 7 days and
failed ones after 30 (`webhooks.cleanup` in `config/api.php`). Pending and retrying ones are never
removed by age. `php glueful webhook:cleanup` runs the same cleanup on demand.

## Pause, rotate or remove a webhook

Everything is in the webhook's detail pane.

- Change **Endpoint URL** or **Events** and press **Save changes**. The same `https://` rule
  applies to a changed URL.
- Turn **Active** off and save to pause it. A paused webhook is skipped when events are matched,
  and its badge in the list reads **Paused**.
- **Rotate signing secret** issues a new one and shows it once. There is no overlap: the old
  secret stops verifying the moment you rotate, so change the receiver's copy at the same time.
- **Delete webhook** removes the webhook and its delivery history after a confirmation.

## Check it worked

Press **Send test event** and confirm two things: your receiver logged a request whose signature
verified, and the admin reported the status code your endpoint returned. Then publish an entry the
webhook listens to. A row appears under **Recent deliveries** at **Pending**, and once the worker
takes it, it reads **Delivered** with your endpoint's status code, and your receiver logged the
event.
