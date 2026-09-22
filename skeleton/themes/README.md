# Themes

Your site's own themes live here, one directory each. The site starts on the theme that ships
with Thallo, `default`, which lives in `vendor/` and is never edited in place.

## Start a theme

Copy the default theme into this directory under a new name:

```bash
php glueful render:theme:clone my-theme
```

That writes `themes/my-theme/`, a complete theme you own:

```text
themes/my-theme/
  theme.json     the theme's name, its style vocabulary and its stylesheets, in load order
  templates/     Twig templates: layout.twig, entry.twig, blocks/*.twig and the rest
  assets/        stylesheets, scripts and images the theme serves
```

Then choose it under **Site › Appearance** in the admin. The site switches on the next request.

## What a theme inherits

Templates fall back one at a time. A template your theme does not have is taken from the default
theme, so you may delete every template you do not change. Stylesheets do not fall back: the
clone carries its own copy of the default CSS, and every file `theme.json` lists must exist, or
the theme will not load.

Keep `theme.json` complete. The admin refuses to switch to a theme whose vocabulary or
stylesheet list is broken, so a half-finished theme cannot reach the live site.

## Read more

The theme guide in the documentation, "Make your own theme", walks through changing a
template and a stylesheet, and the template reference lists every variable and function a
template can use.
