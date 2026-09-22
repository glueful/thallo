---
title: "Make your own block type"
slug: make-a-block-type
section: guides
order: 14
summary: "Define a block's fields in the admin, write its template, and give it style settings."
---

At the end of this page the Blocks tab offers a **Testimonial** block of your own: four fields an
author fills in, a Twig template that renders them, and a set of spacing, colour and corner
settings in the block's inspector.

You need a theme of your own, because the block's template is a file in it — see
[make your own theme](13-make-a-theme.md). The concepts behind all of this are in
[blocks and block types](../concepts/02-blocks.md).

## Create the block type

1. Open **Settings › Block Types** and press **New block type**.
2. Fill in the **Details** card. **Label** is the name in the picker: type `Testimonial` and
   **Slug** fills itself in as `testimonial`. The slug is lower-case letters, digits, hyphens and
   underscores, starting with a letter, and it cannot be changed afterwards: it names the
   template. **Icon** takes a Lucide icon name, such as `i-lucide-quote`. **Category** groups the
   picker — type `Content` to sit with the shipped content blocks. **Description** is shown on
   the type's card and is searched along with the label and the slug.
3. In the **Fields** card, press **Add field** four times and fill the rows in:

   | Field name | Type | Then |
   |---|---|---|
   | `quote` | `text` | **Editor**: **Plain textarea**. Turn **Required** on. |
   | `author` | `string` | Turn **Required** on. |
   | `role` | `string` | |
   | `avatar` | `asset` | |

   A field name is lower-case letters, digits and underscores, starting with a letter. The field
   types are the content model's, listed in
   [the field types](../concepts/01-content-model.md#the-field-types). Two switches a content
   type's fields have are missing here on purpose: a block's field is never **Localized** —
   localisation belongs to the `blocks` field that holds the block — and never **Filterable**.

4. In the **Style settings** card, tick **Spacing**, **Colours**, **Corners**, **Shadow** and
   **Visibility**. Tick them now: there is no template yet, so nothing can refuse them, and the
   next section explains the order.
5. Press **Create block type**.

The type's card appears under **Content** on the block types page. A category Thallo does not
know gets a group of its own after Layout, Content, Media and Items; an empty category puts the
card under **Other**.

## Write the template

A block type's slug is the name of its template. Create the file in your theme:

```text
themes/my-theme/templates/blocks/testimonial.twig
```

Or start it in the admin: on the block type's page, **Open in the Theme editor** beside
**Template** opens `blocks/testimonial.twig`. When the theme has none yet, the editor starts it for
you with the type's style settings and slots already in place; add the markup and press **Save**.
A template saved there is stored in the database, layered over the theme's files, and needs
`RENDER_DB_TEMPLATES` on (the default).

The template is rendered with a `data` object holding that block's fields, so the four fields
above arrive as `data.quote`, `data.author`, `data.role` and `data.avatar`.

```twig
{% set avatar = data.avatar ? media(data.avatar) : null %}
<figure class="testimonial{{ style_classes('root') }}"{{ style_attrs('root') }}>
  <blockquote class="testimonial__quote">{{ data.quote|default('')|editable_text('quote') }}</blockquote>
  <figcaption class="testimonial__by">
    {% if avatar %}<img class="testimonial__avatar" src="{{ avatar }}" alt="" width="48" height="48">{% endif %}
    <span class="testimonial__author">{{ data.author|default('')|editable_text('author') }}</span>
    {% if data.role %}<span class="testimonial__role">{{ data.role|editable_text('role') }}</span>{% endif %}
  </figcaption>
</figure>
```

Three things in there are Thallo's, not Twig's. `media(uuid)` turns the uuid an `asset` field
stores into a URL, and returns nothing for a file that is not publicly retrievable, which is why
the `<img>` is guarded. The `editable_text('field')` filter marks a value editable on the stage:
a `string` field and a plain `text` field can then be changed by double-clicking them in the
Design view. Every helper a template may call is listed in
[template functions](../reference/03-template-functions.md).

Put the block's CSS in one of the stylesheets your `theme.json` lists. Those are delivered
inside `@layer theme`, which the settings layer sits above, so a padding chosen in the editor
still wins over your rule. `custom.css`, under **Site › Theme editor**, loads after both layers,
so a rule there beats the editor's settings.

## The two style helpers

A block type made in the admin has one style target, named `root`: its outermost element. Every
setting group you ticked lands there, and so do the **Advanced** tab's anchor, CSS classes and
attributes. The template emits them with two helpers on that element —
`style_classes('root')` inside the class attribute, and `style_attrs('root')` on the tag — which
is what the example above does.

The order matters once. Changing the setting groups of a type whose template does **not** emit
them is refused, and the message names the two helpers to add. Add them first and save the type
again. For a brand-new type the refusal cannot happen, because there is no template yet — tick
the groups when you create it, as step 4 did.

Where each group shows up in the block's inspector:

| Group | Tab | Under |
|---|---|---|
| Spacing | Style | **Spacing** |
| Typography | Style | **Typography** |
| Colours, Backdrop | Style | **Colours** |
| Corners, Border, Shadow | Style | **Effects** |
| Motion | Style | **Motion** |
| Visibility | Style | **Visibility** |
| Width, Placement, Minimum height, Overflow | Layout | **Box** |
| Sizing in a parent layout | Layout | **As an item** |

Two kinds of setting are not offered to a block made in the admin: text alignment, which needs a
target of its own, and the groups that arrange a container's children. A block that needs those,
or several targets, is declared in code. Every setting and what it compiles to is in
[the style settings reference](../reference/05-style-settings.md).

## Put the block on a page

Open an entry under **Content** and press **Design**. In the **Blocks** tab, find **Testimonial**
under **Content** — or type its name in the search box — and click it to insert it. Select it,
and the **Block** tab's **Content** shows the four fields. Each is labelled by its name with the
underscores turned into spaces, so `success_message` would read **success message**. **Style**
and **Layout** show the groups you ticked.

## When the theme has no template for the type

Nothing breaks. The block is skipped and the page renders without it: in production the page
carries an HTML comment in its place, and with `APP_DEBUG=true` it carries a dashed red box
naming the missing template. Either way a warning is logged, once per block type per process.
The block is not selectable on the stage either, so a missing template shows up the first time
you insert the block.

## Change the type later

Open the type from **Settings › Block Types**.

- **Adding a field** is an ordinary edit: add the row and press **Save**. Existing entries keep
  their data and the new field is empty until an author fills it in.
- **Renaming or deleting a field** is refused by **Save**. Use **Migrate fields** in the
  **Usage & lifecycle** card instead: choose **Rename** or **Delete**, name the field, and press
  **Start migration**. The schema changes at once and a background job rewrites every current
  draft and publication; entries holding the block cannot be saved or published until it
  finishes, and it moves only while a queue worker runs, which the card says — see
  [the scheduler and the queue](../operations/03-scheduler-and-queues.md). A failed migration is
  re-driven with `php glueful thallo:blocks:migration:backfill <uuid>`.
- **The slug never changes.** A block that needs a different template is a new block type.
- **Deactivate** takes the type out of the picker and leaves every page that uses it rendering.
  **Delete block type** is offered only while the usage count is zero and no migration is
  running, and it cannot be undone.

The style settings of a block type Thallo or a pack declares are shown in the same card, but
read-only: they are set in code and re-synced on every upgrade.

## Check it worked

Publish the entry and open the page on the site. The markup is your template's, the author's name
reads back, and changing **Padding** or **Background** in the Style tab changes the block on the
published page after the next publish. If the block is missing, view the page's source: the
comment naming the block type means the theme has no `blocks/testimonial.twig`, or the theme
holding it is not the live one.
