---
title: "Add a form and receive submissions"
slug: forms
section: guides
order: 7
summary: "Put a contact form on a page and read its submissions in the admin."
---

At the end of this page a published page carries a contact form, and everything a visitor
sends arrives under **Submissions** in the admin, where you can read it, filter it and export
it as CSV.

You need an entry you can open in the [Design view](../concepts/03-design-view.md), and one
email address. The address is what activates the form: without it the block renders a notice
instead of fields.

## Put the form block on the page

1. Open the entry under **Content** and press **Design**.
2. In the left panel's **Blocks** tab, stay on the **Blocks** view and find **Form** under
   **Content**. Click it to insert it at the armed target, or drag it onto the stage.
3. Select the block, open the **Block** tab and its **Content** tab. **form name** sits at the
   top; **Delivery**, **Form** and **Fields** are folded below it.

For a whole section instead of a bare block, switch the Blocks tab to **Sections** and insert
**Contact form** from the **Contact** group: a heading, contact details and a form beside them,
already named `Contact`.

## Set the recipient and the delivery mode

Open the **Delivery** group.

- **recipient** — a single email address. A form with no valid address here is un-routable, and
  the block renders "This form isn't configured yet — set a recipient email to activate it."
  in place of the fields. `FORMS_DEFAULT_RECIPIENT` in `.env` gives every form on the site a
  fallback address.
- **delivery** — `store_and_email` keeps every submission in the admin and emails the recipient.
  `email_only` emails the recipient and stores nothing, unless the email could not be sent: then
  the submission is stored after all, so it is never lost.
- **success message** — the text a theme's own JavaScript receives after a clean submit. The
  default theme has no JavaScript, so it does not display it.
- **redirect url** — where a visitor lands after a clean submit. It must start with a single
  slash, such as `/thanks`. A full URL, `//example.com`, `thanks` and `?ok=1` are all ignored,
  and the visitor returns to the page they came from.

The recipient is never written into the page's HTML.

## Choose the fields

Open the **Fields** group. The set is fixed — three fields are always there, three are switches:

| Field | Type | Required | Shown |
|---|---|---|---|
| Name | text | yes | always |
| Subject | text | no | **include subject** |
| Email | email | yes | always |
| Phone | tel | when **phone required** is on | **include phone** |
| Message | textarea | yes | always |
| Consent | checkbox | yes | **include consent** |

Rename them with **name label**, **subject label**, **email label**, **phone label** and
**message label**; **consent text** is the sentence beside the checkbox. There is no
field builder: a form cannot hold a field that is not in the table.

The **Form** group holds the wrapper: **heading** and **intro** above the fields, and
**submit label**, **submit variant** (`solid`, `outline`, `soft`, `subtle`, `ghost`, `link`)
and **submit color** (`primary`, `neutral`) for the button.

## Publish the page and send one submission

4. **Save draft**, then publish the entry. Drafts and publishing are described in
   [drafts, preview and publishing](../concepts/05-publishing.md).
5. Open the published page, fill the form in and send it. Leave at least two seconds between the
   page loading and the button, or the submission is discarded as a bot.

The form posts to `/_forms/submit`. The default theme ships no JavaScript for it, so the browser
sends an ordinary POST and Thallo answers with a 303 back to the page, with `?form_ok=` and the
form's key appended — `?form_err=` when the entries were rejected. The default theme prints
nothing for either, so point **redirect url** at a thank-you page if the visitor should see a
confirmation.

A theme that posts the form itself and sends `Accept: application/json` gets
`{"ok":true,"message":"…"}` with the **success message**, or `{"ok":false,"errors":{…}}` with one
message per field.

## Read the submissions

Open **Submissions** in the sidebar. Its badge counts unread submissions.

- The list is newest first. The form menu above it narrows it to one form, and **All**,
  **Unread** and **Read** filter it by state.
- Clicking a row opens it and marks it read.
- The detail pane shows each label and value as the visitor saw them, and **Submitted from**
  with the page's URL.
- **Delete** removes one submission permanently, after a confirmation. To remove several, tick
  them (or **Select all**) and press **Delete** with the count beside it.

Submissions are kept until someone deletes them, unless you set a retention in `.env`:
`FORMS_RETENTION_DAYS=180` has the scheduler delete, every night, each submission older than 180
days. `php glueful thallo:forms:prune --days=180` does the same once, by hand.

Values are normalised before they are stored: text is trimmed, a checkbox reads Yes or No, an
address that is not an email address is refused, a value over 5,000 characters is refused, and
any field the form did not declare is dropped.

The screen and its API need the `content.manage` permission.

## Export the submissions as CSV

**Export CSV** downloads `form-submissions.csv` with the form and status filters you are looking
at applied. The columns are `submitted_at`, `form_name`, `source_url`, `ip` and `user_agent`,
followed by one column for every field key that appears in the exported rows.

## The notification email

Each submission is emailed to **recipient** as plain text: the subject is "New {form name}
submission", the body lists each field's label and value in the form's order, and the page it came
from. It goes through the same mail settings as the rest of Thallo's email — **Settings › Email** —
so the form mails only once those settings can send. Until then, `store_and_email` still keeps
every submission, and `email_only` keeps the ones it could not send.

A mail failure is written to the log (`form notification failed`) and never shown to the visitor,
who sees the same success either way. To bind your own sender instead, an app can register an
implementation of `Thallo\Core\Content\Forms\FormMailSender`.

Nothing about a submission is queued. Validation, storage and the notification attempt all run
inside the visitor's POST, so a mail server that answered slowly would hold that request open
until it answered or timed out.

## What stops spam

Three checks run in order, and a submission that fails any of them gets exactly the same success
response as a good one, so a bot learns nothing:

1. A honeypot: a hidden text field whose name is derived from the form. It must arrive empty.
2. A time trap: a submit less than two seconds (`FORMS_MIN_SECONDS`) after the page was
   rendered.
3. A rate limit: more than five submissions (`FORMS_RATE_MAX`) of the same form from one IP
   address within sixty seconds (`FORMS_RATE_WINDOW`).

Before any of them, the endpoint itself allows 30 requests a minute per IP address. There is no
CAPTCHA.

## Why a form can expire

The hidden `_form` field is an encrypted copy of the form: its field list, the recipient, the
honeypot's name and the time trap. It is the only thing the server trusts — the visible field
names are not — which is why an extra field posted by hand is ignored and the recipient cannot
be changed from the browser.

It stops being valid after fourteen days (`FORMS_DESCRIPTOR_MAX_AGE`), or after the rendered
page cache's lifetime plus one hour (`FORMS_DESCRIPTOR_BUFFER`) if that is longer.
Submitting a page older than that, or one whose token has been altered, fails with "This form
expired — reload the page and try again." Every `FORMS_` key on this page is read from `.env`.

## Check it worked

- The published page shows fields and a button, not the "isn't configured yet" notice.
- Your test submission appears at the top of **Submissions**, unread, with the page's URL under
  **Submitted from**.
- **Export CSV** produces a file whose first data row is that submission.
