# Nuxt UI — Component Index

Quick name-and-purpose lookup for the admin SPA's UI library (Nuxt UI 4, 125+
components). This is just a reference index — for full API (props/slots/events/
examples) use the Nuxt UI MCP tools (`get_component`, `get_component_metadata`) or
the docs at <https://ui.nuxt.com>.

> `UApp` is the **required** root wrapper (toasts, tooltips, overlays, i18n).

## Layout
`UApp` · `UHeader` · `UFooter` · `UFooterColumns` · `UMain` · `UContainer` · `ULink`

## Element
`UButton` · `UBadge` · `UAvatar` · `UAvatarGroup` · `UIcon` · `UCard` · `UAlert` ·
`UBanner` · `UChip` · `UKbd` · `USeparator` · `USkeleton` · `UProgress` · `UToast` ·
`UCalendar` · `UCollapsible` · `UFieldGroup` · `UMarquee` · `UCarousel` · `UEmpty` ·
`UError` · `UScrollArea` · `UTimeline` · `UUser` · `UTheme`

## Form
`UAuthForm` · `UInput` · `UTextarea` · `USelect` · `USelectMenu` · `UInputMenu` ·
`UInputNumber` · `UInputDate` · `UInputTime` · `UInputTags` · `UPinInput` ·
`UCheckbox` · `UCheckboxGroup` · `URadioGroup` · `USwitch` · `USlider` ·
`UColorPicker` · `UFileUpload` · `UForm` · `UFormField`

## Overlay
`UModal` · `USlideover` · `UDrawer` · `UPopover` · `UTooltip` · `UContextMenu` ·
`UCommandPalette`

## Navigation
`USidebar` · `UNavigationMenu` · `UTabs` · `UBreadcrumb` · `UDropdownMenu` ·
`UPagination` · `UStepper` · `UAccordion`

## Data
`UTable` · `UTree`

## Dashboard
`UDashboardGroup` · `UDashboardSidebar` · `UDashboardPanel` · `UDashboardNavbar` ·
`UDashboardToolbar` · `UDashboardResizeHandle` · `UDashboardSidebarToggle` ·
`UDashboardSearchButton` · `UDashboardSearch` · `UDashboardSidebarCollapse`

## Page (marketing)
`UPage` · `UPageHero` · `UPageSection` · `UPageCTA` · `UPageHeader` · `UPageBody` ·
`UPageGrid` · `UPageColumns` · `UPageCard` · `UPageFeature` · `UPageLogos` ·
`UPageAside` · `UPageAnchors` · `UPageLinks` · `UPageList`

## Blog & Changelog
`UBlogPosts` · `UBlogPost` · `UChangelogVersions` · `UChangelogVersion`

## Pricing
`UPricingPlans` · `UPricingPlan` · `UPricingTable`

## Content (Nuxt Content)
`UContentNavigation` · `UContentToc` · `UContentSurround` · `UContentSearch` ·
`UContentSearchButton`

## Chat (AI)
`UChatMessages` · `UChatMessage` · `UChatReasoning` · `UChatTool` · `UChatShimmer` ·
`UChatPrompt` · `UChatPromptSubmit` · `UChatPalette`

## Editor
`UEditor` · `UEditorToolbar` · `UEditorDragHandle` · `UEditorSuggestionMenu` ·
`UEditorMentionMenu` · `UEditorEmojiMenu`

## Color Mode
`UColorModeButton` · `UColorModeSwitch` · `UColorModeSelect` · `UColorModeAvatar` ·
`UColorModeImage`

---

## Notes for the Thallo admin

- **Rich text for entry `body`** — `UEditor` (+ `UEditorToolbar`, and the slash/mention/
  emoji menus) is the rich-text editor; outputs JSON/HTML/Markdown. Good fit for the
  `text`/long-form fields on content types (e.g. the seeded Pages `body`).
- **Content lists** — `UTable` (TanStack Table: sorting, selection, pinning) +
  `UPagination` for the entries list under each content type.
- **Field editor forms** — `UForm` + `UFormField` with the `UInput`/`USelect`/`USwitch`/
  `UInputDate`/`UFileUpload` family, mapped from the content-type schema field types.
- **Sidebar/nav** — `UDashboardGroup` + `UDashboardSidebar` + `UNavigationMenu`
  (already in use in `layouts/default.vue` / `navigation/sidebar.ts`).
- **Color mode** — `UColorModeSelect` (light/dark/system dropdown) or
  `UColorModeButton`/`UColorModeSwitch` are ready-made alternatives to the hand-rolled
  Appearance submenu in `UserMenu.vue`.
