# Pre-typeface rendering references

Captured by `scripts/capture-pre-typeface.php` at the start of the block typeface release (plan
Task 4, Step 0), before any of its rendering changes, and committed on their own. Later tasks compare
against these files and never re-capture them. Asset cache-busters (`?v=`) and hashed stylesheet names
vary with the checkout; comparisons normalise them.

| file | what it is |
|---|---|
| `head-sans.html`, `head-serif.html`, `head-custom.html` | the default theme's home page `<head>` for `theme_font` = `sans`, `serif`, and `custom` (text and headings uploads) |
| `custom-theme-blocks.html` | a custom theme without face metadata: an unset Heading and an unset Rich text block |
| `appearance-text-only.html`, `appearance-headings-only.html`, `appearance-both.html`, `appearance-shared.html`, `appearance-unselected.html` | the appearance `<style>` for Custom with a text face, a headings face, both (different files), one file for both, and saved uploads while Custom is not selected |
