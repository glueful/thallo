# Appearance tabs — design

Status: draft for review (2026-10-10). Admin only; no server change. Ships before revision 5 of the
custom palette (`2026-10-09-custom-palette-design.md`), whose growing brand colour list lands in the
Colours tab.

## 1. Purpose

**Appearance** is one long column of cards beside a pinned preview: Theme, Theme colors (accent,
neutral and its Custom fields, brand colours, contrast checks), Design (corners, typefaces, page
ground), the typeface library, and Logos & site icon. Every addition makes the column longer, and
the brand colour list (revision 5) can grow to twelve rows with their dialogs. Tabs give each
concern its own room without changing what is saved or how.

## 2. The tabs

| Tab | Holds (today's controls, moved unchanged) |
|---|---|
| **Theme** | the theme gallery |
| **Colours** | Accent, Neutral (with Custom's six fields, Dark mode base, Reset to), Brand colours, Contrast |
| **Design** | Corners, Page ground |
| **Typefaces** | the Text and Headings typeface pickers (moved out of Design) and the typeface library card |
| **Logos & site icon** | Site logo, Site logo (dark), Favicon |

- **Theme** is the default. Its card shows only when the theme list loads with at least one
  theme (`themeCards`), as today. When it does not (the fetch failed or returned no list), the
  Theme tab is hidden and **Colours** is the default.
- The tab is in the URL, as `?tab=colours`, following the commerce products page: the default tab
  has no query, an unknown value falls back to the default, and back and forward move between
  tabs. Other pages can deep-link: the colour pickers' **Manage brand colours** link (custom
  palette revision 5, §5.2) goes to `/appearance?tab=colours`.

## 3. Saving, the preview and errors

- **One form, one Save.** The navbar's Save and its unsaved-changes dot are unchanged and save
  every tab's pending changes together (`useSettingsForm` is unchanged). Switching tabs never
  discards or saves anything.
- **Per-tab dots.** A tab whose controls hold unsaved changes shows a dot in its label, so an
  author who changed the accent and then went to Logos can see where the pending change is.
- **The typeface library card** keeps its own immediate actions (uploads, removals) as today; they
  are not part of Save.
- **The preview** stays pinned beside every tab at `xl` and above, and above the tabs below `xl`,
  and wears the whole unsaved look whichever tab is open.
- **Errors.** When Save returns a 422, the page switches to the first tab, in tab order, holding a
  field the error names, and marks every tab holding one with an error dot. The existing error
  toast still names the failure. A 409 from a palette change keeps the toast it has today.

## 4. Accessibility

The tab list uses the admin's `UTabs` (arrow keys move between tabs; each panel is labelled by its
tab). The dots have text equivalents ("Colours — unsaved changes", "Colours — has errors").

## 5. Testing

- **Unit (vitest):**
  - the tab comes from `?tab`, the default has no query, and an unknown value falls back;
  - with no theme list the Theme tab is hidden and Colours is the default;
  - each tab renders its controls and only those;
  - a change shows its tab's dot, and switching tabs keeps it;
  - Save from any tab sends every tab's changed keys;
  - a 422 naming a Logos field switches to Logos and marks it;
  - `/appearance?tab=colours` opens on Colours.
- **Specs that drive the page** — `appearancePage.spec.ts`, `appearance-palette.spec.ts`,
  `clear-brand-dialog.spec.ts` and any e2e spec that opens Appearance — select the tab their
  controls live in.

## 6. Documentation and changelog

The Appearance guide's screenshots and section headings follow the tabs; an `[Unreleased]` Changed
bullet: "Appearance is split into tabs — Theme, Colours, Design, Typefaces, Logos & site icon".
