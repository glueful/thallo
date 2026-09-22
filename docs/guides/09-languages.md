---
title: "Publish in more than one language"
slug: languages
section: guides
order: 9
summary: "Add a language, translate entries, and give each language its URLs."
---

At the end of this page your site serves a second language: the language is enabled, one entry
has a translated draft and a published version of its own, that version has its own slug, and
a visitor reaches it at a URL prefixed with the language's code.

You need an entry already published in one language, and an account that can open **Settings**.

## Add a language

**Settings › Languages** lists every language the site knows, enabled or not. A new install has
none — the page reads **No languages** — and until you add one Thallo uses `default_locale` from
`config/i18n.php`, which is `en` out of the box.

1. Press **Add language**.
2. Enter a **Code**: two or three lower-case letters, optionally a hyphen and a region, as in
   `fr` or `fr-CA`. The code is the language's identity and cannot be changed afterwards.
3. Enter a **Name**, such as French, and a **Native name**, such as Français, which the list
   shows beside it.
4. Choose a **Direction**, `ltr` or `rtl`. It is recorded on the language; the default theme does
   not act on it.
5. Leave **Enabled** on. Turn on **Set as default** only if this language should become the
   site's default.
6. Press **Add language**.

The first language you add is stored enabled and default whatever those two switches say: a site
must have one of each.

From a terminal, `php glueful i18n:locales` prints **Code**, **Name**, **Enabled** and **Default**
for every stored language, disabled ones included.

## Choose the default language

**Set default** in a row makes that language the default and clears the previous one. The default
language is the one whose pages carry no prefix in their URLs; every other language's do. The only
default language cannot be un-defaulted or disabled — set another one first.

**Settings › General › Default locale** is a different setting. It chooses the language the admin
opens an entry in, and its list is the enabled languages. It does not change what the site serves.

## Say which fields are translated

Open the content type under **Settings › Content Types**. Each field has a **Localized** switch.

- **Localized** on: the field holds a different value in every language.
- **Localized** off: the field is shared. Starting a language version copies it from the source
  version; after that the two values are independent.

Changing the switch applies to language versions made from then on, not to ones that already
exist.

## Translate an entry

The translation controls appear in an entry's toolbar once a second language is enabled.

1. Open the entry under **Content**. At the top right, the language switcher names the language
   you are editing and badges its state: **Published**, **Scheduled**, **Draft** or
   **Not started**.
2. Press the plus button beside the switcher and pick the language. The **Create French (fr)
   version** dialog opens.
3. Leave **Copy content from an existing locale** ticked and choose the source under **Copy
   from**, then press **Create version**. The new draft arrives with the shared fields filled in
   and the **Localized** ones empty.
4. Translate the fields and press **Save draft**.
5. In the **Publishing** tab, set the **Slug** for this language.
6. Press **Publish**. It saves the draft and the slug first, then pins the version. Publishing one
   language does not touch any other.

To re-copy over a translation you already started, use the copy button beside the switcher and
choose the source. **Copy content into this locale** replaces the whole draft: shared fields go
back to the source's values and the localized ones are emptied for retranslation.

The layers button beside it does the same work for every language at once — **Create drafts for
all locales** and **Publish every locale with a draft**.

## Give each language its URLs

A slug belongs to an entry and a language, not to the entry alone. The signpost button in the
toolbar opens **Routes by locale**, which lists every enabled language with its slug and saves or
removes them one at a time. A language with no slug can still be published, but nothing on the
site links to it and no URL reaches it.

From those slugs the site builds:

| Language | URL |
|---|---|
| The default | `/{type}/{slug}` |
| Any other | `/{code}/{type}/{slug}` |

Listing and archive URLs take the prefix the same way: `/fr/blog`, `/fr/blog/page/2`.

Two rules are worth knowing before you plan the URLs:

- The prefix is only read when a path follows it. `/fr` on its own is not a French home page, and
  the home page at `/` always serves the default language.
- A language must be enabled for its prefix to be recognised. Disabling a language takes its URLs
  off the site.

On an entry page Thallo emits a `<link rel="alternate" hreflang="…">` for every language that
entry is published in, and an `x-default` for the default language. They are absolute URLs, so
they appear once `BASE_URL` in `.env` is your real address rather than the `http://localhost`
default.

## What a visitor gets when a page is not translated

A request for a page in one language falls back through that language's chain before giving up:
the language asked for, then its own fallback if one is set, then the base language of a regional
code (`fr-CA` falls back to `fr`), then `fallback_locale` from `config/i18n.php`.

The lookup is by slug. `/fr/blog/hello` serves the English entry at `hello` when French has
nothing at that slug, without redirecting — the page's canonical link points at the English URL.
If no language in the chain has that slug, the visitor gets a 404. So a French translation whose
slug is `bonjour` is not reached from `/fr/blog/hello`; it is reached from `/fr/blog/bonjour`.

The **Add language** form sets no per-language fallback. Every language falls back to
`fallback_locale` unless one is set with `PATCH /i18n/locales/{code}`.

## Show a language switcher in the theme

A [theme](../concepts/04-themes.md) has two facts to work with:

- `site.locale` is the language the page was rendered in. The default theme's `layout.twig` puts
  it in `<html lang="…">`.
- `seo.alternates` is the same list the `hreflang` tags come from: one entry of `locale` and
  `href` per published language of the page being rendered. It is empty on pages that are not
  entries, and on the entry set as the home page.

That is enough for a switcher in the layout:

```twig
{% if seo.alternates|default([])|length > 1 %}
  <nav aria-label="Language">
    {% for alt in seo.alternates %}
      <a href="{{ alt.href }}" {{ alt.locale == site.locale ? 'aria-current="page"' : '' }}>
        {{ alt.locale }}
      </a>
    {% endfor %}
  </nav>
{% endif %}
```

`site.locales` exists in the template context but is always empty; do not build anything on it.
The header and the footer are one set of blocks for the whole site, so a switcher belongs in the
theme's templates rather than in a region. Menu labels, on the other hand, are held per language:
see [build the site's menus](03-navigation.md).

## Turn a language off

Switch **Enabled** off in the language's row. If the language holds entries, **Disable this
language?** says how many are published and how many are drafts and asks you to confirm with
**Disable language**. The site stops serving that language's URLs, and the content API answers
`404` for `?locale=` naming it; the drafts, versions and publications stay in the database, and
switching the language back on brings its pages back.

There is no delete. A language that has ever been used stays in the list, disabled.

## Check it worked

- `php glueful i18n:locales` lists both languages with **Enabled** `yes`.
- The entry's language switcher badges the new language **Published**.
- `/{code}/{type}/{slug}` serves the translation, and the original URL is unchanged.
- The translated page's source shows `<html lang="{code}">` and one `hreflang` link per published
  language.
