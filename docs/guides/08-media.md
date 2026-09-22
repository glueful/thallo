---
title: "Manage images and files"
slug: media
section: guides
order: 8
summary: "Upload, find and reuse images, documents and fonts."
---

Every photograph, logo, PDF and web font a site uses is one file in the media library, uploaded
once and reused wherever you need it. This page covers uploading, finding a file again,
describing it, how an image reaches a visitor's browser, where the bytes live, and what deleting
one does.

You need the `content.view` permission to open the library, and `content.manage` to upload,
edit, optimise or delete. On an install with workspaces, each workspace sees only its own
files; see [workspaces](../concepts/08-workspaces.md).

## Upload a file

1. Open **Media** in the sidebar.
2. Press the plus button beside the **Media Library** heading. The **Upload media** dialog
   opens.
3. Drop files on the left-hand area, or click it to browse. Each file appears as a thumbnail on
   the right, and the cross on a thumbnail takes it out again.
4. Press **Upload**. Files go up one at a time and the dialog closes when the last one finishes.
   A file that fails is counted in a notification; the others still upload.

Files uploaded here are stored as public, so the theme can serve them to a visitor who has no
session.

## What Thallo accepts

`config/uploads.php` decides. `allowed_types` ships as `image/*`, `video/*`, `audio/*`,
`application/pdf` and `font/woff2`. The type is read from the file's own bytes, not from its
name and not from what the browser claims, and anything outside the list is refused.

`max_size` caps one file at 10 MB. `UPLOADS_MAX_SIZE` in `.env` changes it, in bytes.

The first 64 KB of every upload is also scanned: a file containing `<?php`, `<?=` or `<script`
is refused, which rules out an SVG that carries script.

## Find a file again

The list is newest first, thirty files to a page, with the count and a pager at the bottom. The
search box matches the file's name. The buttons above it — **All**, **Images**, **Videos**,
**Audio** and **Docs** — narrow by type. **Docs** means everything that is not an image, a
video, audio or a font, so a typeface uploaded from **Site › Appearance** shows up only under
**All**.

## Describe a file

Select a file to open its preview and, beside it, its panel. An image preview has **Full** and
**Thumbnail** tabs; **Thumbnail** is the 160-pixel-wide variant the list uses.

- **Title** renames the file in the library. It is what the search box matches.
- **Alt text** and **Caption** are free text.
- **Tags** takes one tag at a time: type it and press Enter, click a tag to remove it.
- **File URL** is the file's path on its disk, not a web address.

**Save changes** writes all four at once.

Alt text, caption and tags are notes for whoever picks the file next. The theme does not read
them, and they are not returned by the [content API](../concepts/07-api.md): the alt text a
visitor's screen reader announces comes from the block, below.

## Put a file into content

An entry field of type asset takes one: drop a file on it to upload, or press **Choose from
library**. Either opens **Add media**, which has an **Upload** tab and a **Media library** tab.

In the [Design view](../concepts/03-design-view.md), the **Image** block's **image** field uses
the same picker. Fill in **alt** with what a screen reader should say — that is the field the
theme renders — and **caption** to print a line under the picture. The **Gallery** block holds
Image blocks and takes each one's **alt** the same way.

## How an image reaches the page

A theme never writes a file path. Each image slot asks for the file by its id and for a list of
widths; the default theme's Image block asks for 480, 768, 1024 and 1536. Back come the original
at `/v1/blobs/{uuid}` and one candidate per width, `/v1/blobs/{uuid}?width=768 768w`, and the
browser picks.

- Only a public, undeleted file resolves at all. When nothing resolves the theme leaves the
  picture out rather than printing a broken one.
- Only JPEG, PNG, GIF and WebP get candidates. An SVG is served as it is.
- A width above `max_width` in `config/uploads.php` — 2048 — is dropped from the list.

The resize happens on the first request for that width and is cached for `cache_ttl`, seven
days. Both the original and the variants answer with `Cache-Control: public, max-age=86400`, so
a browser holds them for a day. Resizing needs a PHP image extension: `IMAGE_DRIVER` in `.env`
is `gd` or `imagick`.

## Make an image smaller

**Optimize image** re-encodes the file at the same dimensions and writes it back over the
original. If the re-encode is no smaller, Thallo keeps the original bytes and says
**Already optimal**.

Variants already in the cache keep the old bytes until their entry expires, so the saving
reaches a page later than it reaches the library.

## Where the files are stored

`disk` in `config/uploads.php` names a disk declared in `config/storage.php`, and
`UPLOADS_DISK` in `.env` overrides it. The default is `uploads`: a local disk rooted at
`storage/uploads/`.

Each file is stored under a generated name — the upload's Unix time, an underscore, sixteen hex
characters, the original extension — so two uploads of `logo.png` never collide. The name you
see in the admin is held in the database, not on the disk.

`config/storage.php` also declares an `s3` disk for S3-compatible object storage, but only the
`local` and `memory` drivers are built in. Pointing `UPLOADS_DISK` at `s3` fails with
`Unsupported disk driver 's3'. Install it with: composer require glueful/storage-s3.`

Nothing in `storage/uploads/` is in the database, so it needs backing up alongside it: see
[back up and restore](../operations/04-backups.md).

## Delete a file

Press **Delete**, then **Delete** in the confirmation. The deletion is soft: the row is marked
deleted and the bytes stay on the disk. The library stops listing the file, `/v1/blobs/{uuid}`
answers 404, and there is no undo in the admin. The deletion is recorded under
**Users & Access › Audit Log**.

Before you delete, read the panel's **Used in** list. It names the entries that point at the
file through an asset field, with each one's status. It is built from asset fields only — an
image placed in a page's body through a block is not counted — so read it as a floor, not as a
guarantee.

A page that used a deleted file still renders. The theme resolves the image first and skips the
element when it cannot, so the picture goes and everything around it stays.

## Check it worked

- The file appears at the top of the library, with its size and today's date.
- Selecting it shows the right **Type**, **Size** and a **Visibility** of `public`.
- The page you put it on shows the image to a logged-out visitor, and the page's source names
  the image `/v1/blobs/…` with a `srcset` of `?width=` candidates.
