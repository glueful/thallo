---
title: "Titles, descriptions, sitemaps and redirects"
slug: seo
section: guides
order: 10
summary: "Control how pages appear in search results and what happens to old URLs."
---

At the end of this page each entry carries its own title, description and social card in the
rendered page's head, `/sitemap.xml` and `/robots.txt` answer, and an old URL forwards to the new
one instead of going dead.

You need the **SEO** capability on. It is on by default; the switch is at
**Extensions › Capabilities**, and [capabilities and packs](../concepts/06-capabilities.md)
explains what turning it off takes away. You also need `BASE_URL` in `.env` set to the site's
public origin: while it is unset, or left at the bare `http://localhost` default, Thallo leaves
the canonical and Open Graph URLs out of the head rather than tell a crawler the site lives on
localhost.

## Fill in an entry's SEO tab

1. Open the entry under **Content** and choose the **SEO** tab in the editor's side panel, beside
   **Publishing** and **Versions**. The Design view carries the same tab in its own side panel.
2. Type a **Title** and a **Description**. The hints beside them count towards 60 and 160
   characters; they are advice, not limits.
3. Leave **Robots** at `index` unless the page should stay out of search results. The other
   choices are `noindex` and `noindex,nofollow`.
4. Pick a **Twitter card** if you want one — **Summary** or **Summary large image**. **Not set**
   writes no card tag at all.
5. Open **Open Graph** for **OG title**, **OG description** and **OG image URL**. The image is
   either an absolute `https://` URL or a path beginning with a single `/`.
6. Press **Save SEO**.

Every value is stored against the entry *and* the locale you are editing, so a translated entry
gets its own title and description. Switch locale in the header and fill the tab again.

## What each field becomes in the head

| Field | In the page's head |
|---|---|
| **Title** | The `<title>` element, and `og:title` when **OG title** is empty. |
| **Description** | `<meta name="description">`, and `og:description` when **OG description** is empty. |
| **Robots** | `<meta name="robots">`, written only when the value is not `index`. |
| **Twitter card** | `<meta name="twitter:card">`, written only when you pick one. |
| **OG title** | `<meta property="og:title">`. |
| **OG description** | `<meta property="og:description">`. |
| **OG image URL** | `<meta property="og:image">`, made absolute against the origin when it starts with `/`. |

Thallo adds four tags you do not edit: `og:type` (`website` on the homepage, `article`
everywhere else), `og:url` and `<link rel="canonical">`, both the entry's canonical URL, and
`og:site_name` from `RENDER_SITE_NAME`. An entry published in more than one locale also gets a
`<link rel="alternate" hreflang="…">` per locale and an `x-default`.

Values are escaped before they are written, and a URL that is neither `http://`, `https://` nor
rooted at a single `/` is dropped rather than emitted.

A page rendered in a preview session is the exception: it carries
`<meta name="robots" content="noindex, nofollow">` and no other head tag, so a draft is never
indexed or scraped for a social card.

The tags come from `{{ seo_head() }}` in the theme's layout. The default theme's `layout.twig`
calls it; a theme of your own has to call it too.

## What Thallo writes when a field is empty

- **Title** — the entry's `title` field, run through the title template `{title} — {site_name}`.
  With neither an override nor a `title` field, the head carries the site name alone.
- **Description** — nothing, unless the content type has a fallback field. The description tag is
  simply not written.
- **OG title** and **OG description** — the resolved title and description.
- **OG image** — the site-wide default image, if one is set.
- **Robots** — `index`, which writes no tag.

A title you type in the SEO tab is used verbatim. Only a title taken from a field goes through
the template.

## Set the site-wide defaults

Three values in `.env` feed the defaults:

| Setting | Default | What it does |
|---|---|---|
| `SEO_SITE_NAME` | `Thallo` | Replaces `{site_name}` in the title template, and is the title of a page that has none of its own. |
| `SEO_TITLE_TEMPLATE` | `{title} — {site_name}` | Applied to a title taken from a field. |
| `SEO_DEFAULT_OG_IMAGE` | empty | The `og:image` of every page without one. |

To feed the meta slots from fields of your own, add `config/seo.php` to the project and map them
per content type:

```php
<?php

return [
    'fallbacks' => [
        'posts' => [
            'title_field' => 'title',
            'description_field' => 'excerpt',
            'image_field' => 'cover',
        ],
    ],
];
```

The key is the content type's slug, and those three are the only keys read. Without a map, the
title still falls back to a field named `title`; description and image do not fall back to
anything.

## Serve the sitemap and robots.txt

The feeds need an absolute origin of their own: set `PUBLIC_URL_BASE` in `.env` to the site's
public origin, the same value as `BASE_URL`. It is a separate setting, and a fresh install leaves
it empty. While it is empty all three feeds answer `409` with the plain-text body
`SEO origin (thallo.seo.public_url_base) is not configured.` rather than publish relative URLs a
crawler cannot follow.

With it set, three URLs answer:

- `/sitemap.xml` — a `<urlset>` of up to 50 000 URLs. Above that it becomes a `<sitemapindex>`
  listing the page files instead.
- `/sitemap/1.xml`, `/sitemap/2.xml` … — one page file each, up to 50 000 URLs. A number past the
  last page is a 404.
- `/robots.txt`.

The sitemap holds one `<url>` per published entry and locale whose content type has **Public
delivery** on (**Settings › Content Types**, on the type). Each one carries the entry's canonical
URL, a `<lastmod>` of when that version was published, and an `xhtml:link` alternate for every
other locale the entry is published in. Listing, archive and term URLs are not in it. The XML is
cached with no expiry and dropped whole on any content change, so a publish shows up on the next
request.

`robots.txt` is built from a list of groups. The default is one group:

```text
User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
```

The `Sitemap:` line is appended for you. To change the groups, add a `robots` key to
`config/seo.php`:

```php
'robots' => [
    ['user_agent' => '*', 'allow' => ['/'], 'disallow' => ['/cart']],
],
```

## Let Thallo redirect a changed slug

Change an entry's slug and Thallo writes a 301 from the old slug to the entry. Nothing to do:
the old URL keeps working. Claiming a slug also clears any redirect that pointed away from it, so
moving an entry back does not leave a loop. [Content types, entries and
fields](../concepts/01-content-model.md) has the URLs a slug builds.

These automatic redirects sit in the same list as the ones you write, and can be removed the same
way. If the entry they point at stops being published, the old URL answers `410 Gone` instead of
forwarding — see [drafts, preview and publishing](../concepts/05-publishing.md).

## Write your own redirect

1. Go to **Settings › Redirects** and press **Add redirect**.
2. Choose the **Content type** the old slug belonged to.
3. Type the **Source slug** — the slug alone, `old-path`, not a whole path. Thallo looks for it at
   that content type's URL: `/posts/old-path` for a type served at `/posts`, `/old-path` for one
   mounted at root.
4. Type the **Target URL**: a path beginning with a single `/`, or an absolute `http://` or
   `https://` URL. A protocol-relative `//example.com` is refused.
5. Choose the **Status** — `301`, `302` or `308`.
6. Press **Add redirect**.

A source slug another entry of that type already holds as its own slug is refused: the entry
wins the URL, and a redirect there would never fire. Redirects are written in the site's default language.

The list shows **Content type**, **Source**, **Status** and **State**. **State** is `live` when
the target resolves and `broken` when it does not — a redirect pointing at an entry that was
unpublished or deleted. Each row ends with a **Remove redirect** button.

The same redirects, one content type at a time, are behind the **Redirects** button on that
type's entry list.

## Check it worked

Open a published page in a browser and view its source: the `<title>`, the description and the
canonical link should be the ones you set. Then, from any machine:

```bash
$ curl -s https://example.com/robots.txt
$ curl -s https://example.com/sitemap.xml
$ curl -sI https://example.com/posts/old-path
```

The first two answer with the robots groups and the XML rather than the 409 text. The third
answers `301` with a `Location` header holding the entry's canonical URL — absolute once
`PUBLIC_URL_BASE` is set.
