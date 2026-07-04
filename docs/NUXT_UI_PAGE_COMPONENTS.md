# Nuxt UI Page Components — Structure, Config & Styling Reference

Extracted verbatim from the **installed** `@nuxt/ui` **v4.9.0** package
(`admin/node_modules/@nuxt/ui/dist`): component sources
(`runtime/components/*.vue`), typed props/slots (`*.d.vue.ts`), and the
bundled theme definitions (`shared/ui.*.mjs`). Purpose: reference material
for designing Lemma starter blocks (server-rendered Twig + plain CSS — the
semantic structure and Tailwind styling recipes are what transfer, not the
Vue mechanics).

**Reading the theme blocks**
- Each theme is a `tv()` config: `slots` maps a named DOM region to its
  Tailwind classes; `variants` toggle per-prop class sets;
  `compoundVariants` apply when several props combine; `defaultVariants`
  are the initial prop values.
- `data-slot="<name>"` on the rendered element identifies which theme slot
  styles it.
- Placeholders inside themes: `colors` = configured color tokens
  (`primary`, `secondary`, `success`, `info`, `warning`, `error`; `neutral`
  is always explicit); `options.theme.transitions` conditionally appends
  `transition-*` classes; `ssr(...)` wraps SSR-only pseudo-element classes
  (treat as plain space-joined classes).
- Semantic color utilities (`text-muted`, `bg-elevated`, `ring-default`,
  `text-highlighted`, `bg-inverted`, `text-dimmed`, `text-toned`,
  `bg-accented`) are Nuxt UI design tokens — map these to Lemma theme
  tokens (`--ink`, `--surface`, …) when porting.

**Contents**

| Group | Components |
|---|---|
| Layout primitives | [Page](#page) · [PageBody](#pagebody) · [PageAside](#pageaside) · [PageAnchors](#pageanchors) · [PageLinks](#pagelinks) · [PageColumns](#pagecolumns) · [PageGrid](#pagegrid) |
| Marketing sections | [PageHero](#pagehero) · [PageSection](#pagesection) · [PageCTA](#pagecta) · [PageHeader](#pageheader) · [PageCard](#pagecard) · [PageFeature](#pagefeature) · [PageLogos](#pagelogos) |
| Blog | [BlogPost](#blogpost) · [BlogPosts](#blogposts) |
| Site chrome | [FooterColumns](#footercolumns) · [NavigationMenu](#navigationmenu) |
| Interactive | [Tabs](#tabs) · [Accordion](#accordion) · [Stepper](#stepper) · [Carousel](#carousel) · [Button](#button) |

---

# Layout primitives

## Page

Responsive 10-column grid wrapper that lays out an optional left rail, a main center column, and an optional right rail for a documentation-style page.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | Element or component to render as. |
| `class` | `any` | — | Classes applied to the root slot. |
| `ui` | `Page['slots']` | — | Overrides for individual slot classes (`root`, `left`, `center`, `right`). |

**Slots**
- `left` — content for the left rail (its presence toggles the `left` variant).
- `default` — main center-column content.
- `right` — content for the right rail (its presence toggles the `right` variant).

**Emits** — none.

**DOM structure**
```
root (div)
├─ left      (rendered only if `left` slot provided)
├─ center    (div) → default slot
└─ right     (rendered only if `right` slot provided)
```
Note: `right` sits after center in DOM but uses `order-first lg:order-last` so on mobile it appears first, on large screens last. Column spans of `center` adapt via compoundVariants depending on which rails exist.

**Theme**
```js
{
  slots: {
    root: "flex flex-col lg:grid lg:grid-cols-10 lg:gap-10",
    left: "lg:col-span-2",
    center: "lg:col-span-8",
    right: "lg:col-span-2 order-first lg:order-last"
  },
  variants: {
    left: { true: "" },
    right: { true: "" }
  },
  compoundVariants: [
    { left: true, right: true, class: { center: "lg:col-span-6" } },
    { left: false, right: false, class: { center: "lg:col-span-10" } }
  ]
}
```

## PageBody

Simple single-element content container that provides consistent top margin, bottom padding, and vertical spacing between page sections (the main body region of a Page's center column).

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | Element or component to render as. |
| `class` | `any` | — | Classes appended to the base class. |
| `ui` | `{ base?: any }` | — | Override for the single `base` class. |

**Slots**: `default` — body content.

**Emits** — none.

**DOM structure**
```
base (div) → default slot
```
Single element only; flat `base` string rather than a `slots` map. `space-y-12` provides the gap between stacked child sections.

**Theme**
```js
{ base: "mt-8 pb-24 space-y-12" }
```

## PageAside

Sticky, scrollable side column (e.g. a docs sidebar navigation) with an optional sticky top-fade region above the main aside content and an optional bottom region.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'aside'` | Element or component to render as. |
| `class` | `any` | — | Classes applied to the root slot. |
| `ui` | `PageAside['slots']` | — | Overrides for individual slot classes. |

**Slots**
- `top` — content pinned in the sticky top area (renders the `top` wrapper only when provided).
- `default` — main aside content.
- `bottom` — content placed after the default content.

**Emits** — none.

**DOM structure**
```
root (aside)  // hidden on mobile, sticky below header on lg
└─ container (div)
   ├─ top (div, only if `top` slot; sticky)
   │  ├─ topHeader (solid bg spacer)
   │  ├─ topBody   → top slot
   │  └─ topFooter (gradient fade)
   ├─ [default slot]
   └─ [bottom slot]
```
No sub-components; pure structural layout. The three `top*` slots create a header/fade sandwich so scrolled content dissolves under a sticky top.

**Theme**
```js
{
  slots: {
    root: "hidden overflow-y-auto lg:block lg:max-h-[calc(100vh-var(--ui-header-height))] lg:sticky lg:top-(--ui-header-height) py-8 lg:ps-4 lg:-ms-4 lg:pe-6.5",
    container: "relative",
    top: "sticky -top-8 -mt-8 pointer-events-none z-[1]",
    topHeader: "h-8 bg-default -mx-4 px-4",
    topBody: "bg-default relative pointer-events-auto flex flex-col -mx-4 px-4",
    topFooter: "h-8 bg-gradient-to-b from-default -mx-4 px-4"
  }
}
```

## PageAnchors

Vertical navigation list of anchor links (typically an on-page "on this page" table of contents), each with optional leading icon and external-link indicator.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'nav'` | Element or component to render as. |
| `links` | `PageAnchor[]` | — | Array of anchor link objects (see shape below). |
| `class` | `any` | — | Classes applied to the root slot. |
| `ui` | `PageAnchors['slots']` | — | Overrides for individual slot classes. |

`PageAnchor` shape (extends `LinkProps` minus `custom`): `label: string`, `icon?` (Iconify name), `class?`, plus per-link `ui?` overriding `item | link | linkLabel | linkLabelExternalIcon | linkLeading | linkLeadingIcon`. Standard link props (`to`, `target`, etc.) are spread onto the link.

**Slots** (scope payload `{ link, active, ui }` unless noted)
- `link` — replace the entire link inner content.
- `link-leading` — replace leading icon area.
- `link-label` — replace the label text; payload `{ link, active }`.
- `link-trailing` — trailing content; payload `{ link, active }`.

**Emits** — none.

**DOM structure**
```
root (nav)
└─ list (ul)
   └─ item (li, per link)
      └─ link (anchor, gets `active` state)
         ├─ linkLeading (div, if link.icon)
         │  └─ linkLeadingIcon (icon)
         ├─ linkLabel (span)
         │  ├─ {{ link.label }}
         │  └─ linkLabelExternalIcon (icon, if target === '_blank')
         └─ [link-trailing slot]
```
The `active` variant drives the primary-colored styling of the current anchor.

**Theme**
```js
{
  slots: {
    root: "",
    list: "",
    item: "relative",
    link: "group text-sm flex items-center gap-1.5 py-1 rounded-sm outline-primary/25 focus-visible:outline-3",
    linkLeading: "rounded-md p-1 inline-flex ring-inset ring",
    linkLeadingIcon: "size-4 shrink-0",
    linkLabel: "truncate",
    linkLabelExternalIcon: "size-3 absolute top-0 text-dimmed"
  },
  variants: {
    active: {
      true: {
        link: "text-primary font-semibold",
        linkLeading: "bg-primary ring-primary text-inverted"
      },
      false: {
        link: ["text-muted hover:text-default font-medium", "transition-colors"],
        linkLeading: ["bg-elevated/50 ring-accented text-dimmed group-hover:bg-primary group-hover:ring-primary group-hover:text-inverted", "transition"]
      }
    }
  }
}
```

## PageLinks

A titled vertical navigation list (e.g. a docs "On this page" / table-of-contents or footer link column) that renders each entry as a link with optional leading icon, external-link indicator, and an active state.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | any | `'nav'` | The element or component this renders as. |
| `title` | string | — | Heading text above the list (renders in `<p>`). |
| `links` | `PageLink[]` | — | Link items (see PageLink shape below). |
| `class` | any | — | Classes for the root. |
| `ui` | object (slot map) | — | Per-slot class overrides. |

`PageLink` extends `LinkProps` (minus `custom`) with: `label: string` (required), `icon?` (Iconify name), `class?`, and `ui?` (Pick of `item`, `link`, `linkLabel`, `linkLabelExternalIcon`, `linkLeadingIcon` slots). Active state is derived from the link's route match; `target: '_blank'` triggers the external icon.

**Slots**
- `title` — no scope.
- `link` — scope `{ link, active, ui }`.
- `link-leading` — scope `{ link, active, ui }`.
- `link-label` — scope `{ link, active }`.
- `link-trailing` — scope `{ link, active }`.

**Emits** — none.

**DOM structure**
```
root (nav)
├─ title (p)  [{{ title }}]
└─ list (ul)
   └─ item (li, per link)
      └─ link (anchor)
         ├─ linkLeadingIcon (icon, if link.icon)
         ├─ linkLabel (span) → {{ link.label }}
         │  └─ linkLabelExternalIcon (icon, if target="_blank")
         └─ [link-trailing slot]
```

**Theme**
```js
{
  slots: {
    root: "flex flex-col gap-3",
    title: "text-sm font-semibold flex items-center gap-1.5",
    list: "flex flex-col gap-2",
    item: "relative",
    link: "group text-sm flex items-center gap-1.5 rounded-sm outline-primary/25 focus-visible:outline-3",
    linkLeadingIcon: "size-5 shrink-0",
    linkLabel: "truncate",
    linkLabelExternalIcon: "size-3 absolute top-0 text-dimmed"
  },
  variants: {
    active: {
      true: { link: "text-primary font-medium" },
      false: { link: ["text-muted hover:text-default", "transition-colors"] }
    }
  }
}
```

## PageColumns

Masonry-style responsive multi-column layout wrapper that flows its children across 1–3 columns (CSS `columns`), keeping each child from breaking across columns.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `class` | `any` | — | Root class override. |
| `ui` | `{ base?: any }` | — | Per-slot class overrides (only `base`). |

**Slots**: `default` — the column items.

**Emits** — none.

**DOM structure**
```
base (div) → default slot   (children flow into CSS columns)
```

**Theme**
```js
{ base: "relative column-1 md:columns-2 lg:columns-3 gap-8 space-y-8 *:break-inside-avoid-column *:will-change-transform" }
```

## PageGrid

Responsive CSS-grid wrapper that arranges its children in 1/2/3 columns — the grid counterpart to PageColumns (equal-height grid cells rather than masonry flow).

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `class` | `any` | — | Root class override. |
| `ui` | `{ base?: any }` | — | Per-slot class overrides (only `base`). |

**Slots**: `default` — the grid items.

**Emits** — none.

**DOM structure**
```
base (div) → default slot   (children become grid cells)
```

**Theme**
```js
{ base: "relative grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8" }
```

---

# Marketing sections

## PageHero

A full-width hero band for the top of a marketing/landing page, rendering an eyebrow headline, large title, description, and a row of call-to-action buttons, with optional vertical (centered) or horizontal (two-column) layouts.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | any | `'div'` | The element or component this renders as. |
| `headline` | string | — | Eyebrow text shown above the title. |
| `title` | string | — | Main heading text (renders in `<h1>`). |
| `description` | string | — | Supporting paragraph below the title. |
| `links` | `ButtonProps[]` | — | List of buttons under the description (each forced to `size: 'xl'`). |
| `orientation` | `'vertical' \| 'horizontal'` | `'vertical'` | Layout orientation. |
| `reverse` | boolean | `false` | Reverse the order of the default slot (moves wrapper to `order-last`). |
| `class` | any | — | Classes for the root. |
| `ui` | object (slot map) | — | Per-slot class overrides. |

**Slots** (all unscoped): `top`, `header`, `headline`, `title`, `description`, `body`, `footer`, `links`, `default`, `bottom`.

**Emits** — none.

**DOM structure**
```
root (div, data-orientation)
├─ [top slot]
├─ container (UContainer)
│  ├─ wrapper (div, if any header/body/footer/links content)
│  │  ├─ header (div)
│  │  │  ├─ headline (div) → {{ headline }}
│  │  │  ├─ title (h1) → {{ title }}
│  │  │  └─ description (div) → {{ description }}
│  │  ├─ body (div)
│  │  └─ footer (div)
│  │     └─ links (div) → Button × links (size="xl")
│  ├─ [default slot]  (if provided)
│  └─ div.hidden.lg:block  (else, only when orientation="horizontal" — empty spacer column)
└─ [bottom slot]
```
Composes: Container, Button.

**Theme**
```js
{
  slots: {
    root: "relative isolate",
    container: "flex flex-col lg:grid py-24 sm:py-32 lg:py-40 gap-16 sm:gap-y-24",
    wrapper: "",
    header: "",
    headline: "mb-4",
    title: "text-5xl sm:text-7xl text-pretty tracking-tight font-bold text-highlighted",
    description: "text-lg sm:text-xl/8 text-muted",
    body: "mt-10",
    footer: "mt-10",
    links: "flex flex-wrap gap-x-6 gap-y-3"
  },
  variants: {
    orientation: {
      horizontal: {
        container: "lg:grid-cols-2 lg:items-center",
        description: "text-pretty"
      },
      vertical: {
        container: "",
        headline: "justify-center",
        wrapper: "text-center",
        description: "text-balance",
        links: "justify-center"
      }
    },
    reverse: { true: { wrapper: "order-last" } },
    headline: { true: { headline: "font-semibold text-primary flex items-center gap-1.5" } },
    title: { true: { description: "mt-6" } }
  }
}
// no compoundVariants / defaultVariants (component defaults: orientation="vertical", reverse=false)
```

## PageSection

A general content section for landing pages — like PageHero but smaller/section-scale — rendering an icon, eyebrow headline, title, description, an optional grid of PageFeature items, and CTA buttons, in vertical (centered) or horizontal (two-column) layouts.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | any | `'section'` | The element or component this renders as. |
| `headline` | string | — | Eyebrow text displayed above the title. |
| `icon` | Iconify name | — | Icon displayed above the title. |
| `title` | string | — | Section heading (renders in `<h2>`). |
| `description` | string | — | Supporting paragraph below the title. |
| `links` | `ButtonProps[]` | — | Buttons under the description (each forced to `size: 'lg'`). |
| `features` | `PageFeatureProps[]` | — | List of PageFeature items rendered in a grid `<ul>`. |
| `orientation` | `'vertical' \| 'horizontal'` | `'vertical'` | Section orientation. |
| `reverse` | boolean | `false` | Reverse the order of the default slot. |
| `class` | any | — | Classes for the root. |
| `ui` | object (slot map) | — | Per-slot class overrides. |

**Slots**: `top`, `header`, `leading` (`{ ui }`), `headline`, `title`, `description`, `body`, `features`, `footer`, `links`, `default`, `bottom`.

**Emits** — none.

**DOM structure**
```
root (section, data-orientation)
├─ [top slot]
├─ container (UContainer)
│  ├─ wrapper (div)
│  │  ├─ header (div)
│  │  │  ├─ leading (div)
│  │  │  │  └─ leadingIcon (icon, if icon)
│  │  │  ├─ headline (div) → {{ headline }}
│  │  │  ├─ title (h2) → {{ title }}
│  │  │  └─ description (div) → {{ description }}
│  │  ├─ body (div)
│  │  │  └─ features (ul)
│  │  │     └─ PageFeature × features (as="li")
│  │  └─ footer (div)
│  │     └─ links (div) → Button × links (size="lg")
│  ├─ [default slot]  (if provided)
│  └─ div.hidden.lg:block  (else, only when orientation="horizontal" — empty spacer column)
└─ [bottom slot]
```
Composes: Container, Icon, PageFeature, Button.

**Theme**
```js
{
  slots: {
    root: "relative isolate",
    container: "flex flex-col lg:grid py-16 sm:py-24 lg:py-32 gap-8 sm:gap-16",
    wrapper: "",
    header: "",
    leading: "flex items-center mb-6",
    leadingIcon: "size-10 shrink-0 text-primary",
    headline: "mb-3",
    title: "text-3xl sm:text-4xl lg:text-5xl text-pretty tracking-tight font-bold text-highlighted",
    description: "text-base sm:text-lg text-muted",
    body: "mt-8",
    features: "grid",
    footer: "mt-8",
    links: "flex flex-wrap gap-x-6 gap-y-3"
  },
  variants: {
    orientation: {
      horizontal: {
        container: "lg:grid-cols-2 lg:items-center",
        description: "text-pretty",
        features: "gap-4"
      },
      vertical: {
        container: "",
        headline: "justify-center",
        leading: "justify-center",
        title: "text-center",
        description: "text-center text-balance",
        links: "justify-center",
        features: "sm:grid-cols-2 lg:grid-cols-3 gap-8"
      }
    },
    reverse: { true: { wrapper: "order-last" } },
    headline: { true: { headline: "font-semibold text-primary flex items-center gap-1.5" } },
    title: { true: { description: "mt-6" } },
    description: { true: "" },
    body: { true: "" }
  },
  compoundVariants: [
    { orientation: "vertical", title: true, class: { body: "mt-16" } },
    { orientation: "vertical", description: true, class: { body: "mt-16" } },
    { orientation: "vertical", body: true, class: { footer: "mt-16" } }
  ]
}
```

## PageCTA

A prominent call-to-action band with title, description, action buttons, and optional body — supporting vertical (centered) or horizontal (2-column) orientation and several background variants.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `class` | `any` | — | Root class override. |
| `title` | `string` | — | Heading text (renders in `<h2>`). |
| `description` | `string` | — | Supporting text. |
| `orientation` | `'vertical' \| 'horizontal'` | `'vertical'` | Layout orientation of the CTA. |
| `reverse` | `boolean` | `false` | Reverse the order of the default slot (moves wrapper last). |
| `variant` | `'solid' \| 'outline' \| 'soft' \| 'subtle' \| 'naked'` | `'outline'` | Background/style variant. |
| `links` | `ButtonProps[]` | — | List of buttons shown under the description (each rendered at `size: 'lg'`). |
| `ui` | `PageCTA['slots']` | — | Per-slot class overrides. |

**Slots** (all unscoped): `top`, `header`, `title`, `description`, `body`, `footer`, `links`, `default` (horizontal media/second column), `bottom`.

**Emits** — none.

**DOM structure**
```
root (div, data-orientation, rounded band)
├─ [top slot]
├─ container (UContainer)
│  ├─ wrapper (div, if any header/body/footer/links content)
│  │  ├─ header (div)
│  │  │  ├─ title (h2) → {{ title }}
│  │  │  └─ description (div) → {{ description }}
│  │  ├─ body (div)
│  │  └─ footer (div)
│  │     └─ links (div) → Button × links (size="lg")
│  ├─ [default slot]  (if provided)
│  └─ div.hidden.lg:block  (else, only when orientation="horizontal" — spacer)
└─ [bottom slot]
```
Composes: Container, Button.

**Theme**
```js
{
  slots: {
    root: "relative isolate rounded-xl overflow-hidden",
    container: "flex flex-col lg:grid px-6 py-12 sm:px-12 sm:py-24 lg:px-16 lg:py-24 gap-8 sm:gap-16",
    wrapper: "",
    header: "",
    title: "text-3xl sm:text-4xl text-pretty tracking-tight font-bold text-highlighted",
    description: "text-base sm:text-lg text-muted",
    body: "mt-8",
    footer: "mt-8",
    links: "flex flex-wrap gap-x-6 gap-y-3"
  },
  variants: {
    orientation: {
      horizontal: {
        container: "lg:grid-cols-2 lg:items-center",
        description: "text-pretty"
      },
      vertical: {
        container: "",
        title: "text-center",
        description: "text-center text-balance",
        links: "justify-center"
      }
    },
    reverse: { true: { wrapper: "order-last" } },
    variant: {
      solid: { root: "bg-inverted text-inverted", title: "text-inverted", description: "text-dimmed" },
      outline: { root: "bg-default ring ring-default", description: "text-muted" },
      soft: { root: "bg-elevated/50", description: "text-toned" },
      subtle: { root: "bg-elevated/50 ring ring-default", description: "text-toned" },
      naked: { description: "text-muted" }
    },
    title: { true: { description: "mt-6" } }
  },
  defaultVariants: { variant: "outline" }
}
```

## PageHeader

A page/section header block with optional eyebrow headline, a large `<h1>` title, action buttons aligned opposite the title, and a description — separated from content below by a bottom border.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `headline` | `string` | — | Small eyebrow text above the title. |
| `title` | `string` | — | Main heading (renders in `<h1>`). |
| `description` | `string` | — | Supporting text below the title row. |
| `links` | `ButtonProps[]` | — | Buttons shown next to the title (each rendered `color: 'neutral', variant: 'outline'`). |
| `class` | `any` | — | Root class override. |
| `ui` | `PageHeader['slots']` | — | Per-slot class overrides. |

**Slots** (all unscoped): `headline`, `title`, `description`, `links`, `default`.

**Emits** — none.

**DOM structure**
```
root (div, bottom border)
├─ headline (div, if headline) → {{ headline }}
└─ container (div)
   ├─ wrapper (div, flex row: title left, links right)
   │  ├─ title (h1) → {{ title }}
   │  └─ links (div) → Button × links (color="neutral", variant="outline")
   ├─ description (div, if description) → {{ description }}
   └─ [default slot]
```
Composes: Button.

**Theme**
```js
{
  slots: {
    root: "relative border-b border-default py-8",
    container: "",
    wrapper: "flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4",
    headline: "mb-2.5 text-sm font-semibold text-primary flex items-center gap-1.5",
    title: "text-3xl sm:text-4xl text-pretty font-bold text-highlighted",
    description: "text-lg text-pretty text-muted",
    links: "flex flex-wrap items-center gap-1.5"
  },
  variants: {
    title: { true: { description: "mt-4" } }
  }
}
```

## PageCard

A flexible content card presenting an icon, title, description, optional header/footer, and optional full-card link, with variant styling, orientation, highlight ring, and a mouse-follow spotlight effect.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `icon` | Iconify name | — | Icon displayed above the title. |
| `title` | `string` | — | Card title text. |
| `description` | `string` | — | Card description text. |
| `orientation` | `'horizontal' \| 'vertical'` | `'vertical'` | Layout orientation of the card. |
| `reverse` | `boolean` | `false` | Reverse the order of the default slot (moves wrapper last). |
| `highlight` | `boolean` | — | Display a ring around the card. |
| `highlightColor` | color token | `'primary'` | Color of the highlight ring. |
| `spotlight` | `boolean` | — | Spotlight effect following the mouse cursor. |
| `spotlightColor` | color token | `'primary'` | Color of the spotlight. |
| `variant` | `'solid' \| 'outline' \| 'soft' \| 'subtle' \| 'ghost' \| 'naked'` | `'outline'` | Visual style variant. |
| `to` | `LinkProps['to']` | — | Makes the whole card a link. |
| `target` | `LinkProps['target']` | — | Link target. |
| `onClick` | `(event: MouseEvent) => void \| Promise<void>` | — | Click handler (also activates the `to` interactive styling). |
| `class` | `any` | — | Classes applied to the root slot. |
| `ui` | `PageCard['slots']` | — | Overrides for individual slot classes. |

**Slots**: `header`, `leading` (`{ ui }`), `body`, `title`, `description`, `footer`, `default`.

**Emits** — none (uses `onClick` prop; extra attrs forwarded to the inner link).

**DOM structure**
```
root (div, data-orientation, --spotlight-x/y CSS vars)
├─ spotlight (div, if spotlight)
├─ container (div)
│  ├─ wrapper (div, if any header/icon/body/title/description/footer)
│  │  ├─ header (div)
│  │  ├─ leading (div, if icon)
│  │  │  └─ leadingIcon (icon)
│  │  ├─ body (div)
│  │  │  ├─ title (div) → {{ title }}
│  │  │  └─ description (div) → {{ description }}
│  │  └─ footer (div)
│  └─ [default slot]
└─ link (if to; anchor with span.absolute.inset-0 overlay making the whole card clickable)
```
Spotlight is a radial-gradient positioned by `--spotlight-x/--spotlight-y` (mouse-tracked).

**Theme**
```js
{
  slots: {
    root: "relative flex rounded-lg",
    spotlight: "absolute inset-0 rounded-[inherit] pointer-events-none bg-default/90",
    container: "relative flex flex-col flex-1 lg:grid gap-x-8 gap-y-4 p-4 sm:p-6",
    wrapper: "flex flex-col flex-1 items-start",
    header: "mb-4",
    body: "flex-1",
    footer: "pt-4 mt-auto",
    leading: "inline-flex items-center mb-2.5",
    leadingIcon: "size-5 shrink-0 text-primary",
    title: "text-base text-pretty font-semibold text-highlighted",
    description: "text-[15px] text-pretty"
  },
  variants: {
    orientation: {
      horizontal: { container: "lg:grid-cols-2 lg:items-center" },
      vertical: { container: "" }
    },
    reverse: { true: { wrapper: "order-last" } },
    variant: {
      solid: { root: "bg-inverted text-inverted", title: "text-inverted", description: "text-dimmed" },
      outline: { root: "bg-default ring ring-default", description: "text-muted" },
      soft: { root: "bg-elevated/50", description: "text-toned" },
      subtle: { root: "bg-elevated/50 ring ring-default", description: "text-toned" },
      ghost: { description: "text-muted" },
      naked: { container: "p-0 sm:p-0", description: "text-muted" }
    },
    to: { true: { root: ["outline-primary/25 has-[>a:focus-visible]:outline-3", "transition"] } },
    title: { true: { description: "mt-1" } },
    highlight: { true: { root: "ring-2" } },
    highlightColor: { /* per color token: "" */ },
    spotlight: {
      true: { root: "[--spotlight-size:400px] before:absolute before:-inset-px before:pointer-events-none before:rounded-[inherit] before:bg-[radial-gradient(var(--spotlight-size)_var(--spotlight-size)_at_calc(var(--spotlight-x,0px))_calc(var(--spotlight-y,0px)),var(--spotlight-color),transparent_70%)]" }
    },
    spotlightColor: { /* per color token: "" */ }
  },
  compoundVariants: [
    { variant: "solid", to: true, class: { root: "hover:bg-inverted/90" } },
    { variant: "outline", to: true, class: { root: "hover:bg-elevated/50" } },
    { variant: "soft", to: true, class: { root: "hover:bg-elevated" } },
    { variant: "subtle", to: true, class: { root: "hover:bg-elevated" } },
    { variant: "subtle", to: true, highlight: false, class: { root: "hover:ring-accented" } },
    { variant: ["outline", "subtle"], to: true, highlight: false, class: { root: "has-[>a:focus-visible]:ring-primary" } },
    { variant: "ghost", to: true, class: { root: "hover:bg-elevated/50" } },
    // per color: { highlightColor, highlight: true, class: { root: `ring-${color}` } }
    { highlightColor: "neutral", highlight: true, class: { root: "ring-inverted" } },
    // per color: { spotlightColor, spotlight: true, class: { root: `[--spotlight-color:var(--ui-${color})]` } }
    { spotlightColor: "neutral", spotlight: true, class: { root: "[--spotlight-color:var(--ui-bg-inverted)]" } }
  ],
  defaultVariants: { variant: "outline", highlightColor: "primary", spotlightColor: "primary" }
}
```

## PageFeature

A single feature entry — leading icon plus title and description — laid out horizontally (icon beside text) or vertically (icon above), optionally turned into a full-card link.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `icon` | Iconify name | — | Icon shown next to (horizontal) or above (vertical) the title. |
| `title` | `string` | — | Feature title. |
| `description` | `string` | — | Feature description. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Layout orientation of the feature. |
| `to` | `LinkProps['to']` | — | Makes the whole feature a link (overlay anchor). |
| `target` | `LinkProps['target']` | — | Link target attribute. |
| `onClick` | `(event: MouseEvent) => void \| Promise<void>` | — | Click handler on the root. |
| `class` | `any` | — | Root class override. |
| `ui` | `PageFeature['slots']` | — | Per-slot class overrides. |

**Slots**: `leading` (`{ ui }`), `title`, `description`, `default` (replaces the title+description wrapper contents).

**Emits** — none.

**DOM structure**
```
root (div, data-orientation)
├─ leading (div, if icon)
│  └─ leadingIcon (icon)
└─ wrapper (div)
   ├─ link overlay (if to; anchor + span.absolute.inset-0)
   ├─ title (div) → {{ title }}
   └─ description (div) → {{ description }}
```

**Theme**
```js
{
  slots: {
    root: "relative rounded-sm",
    wrapper: "",
    leading: "inline-flex items-center justify-center",
    leadingIcon: "size-5 shrink-0 text-primary",
    title: "text-base text-pretty font-semibold text-highlighted",
    description: "text-[15px] text-pretty text-muted"
  },
  variants: {
    orientation: {
      horizontal: { root: "flex items-start gap-2.5", leading: "p-0.5" },
      vertical: { leading: "mb-2.5" }
    },
    to: { true: { root: ["outline-primary/25 has-focus-visible:outline-3", "transition"] } },
    title: { true: { description: "mt-1" } }
  }
}
// no compoundVariants / defaultVariants (component default: orientation="horizontal")
```

## PageLogos

A "trusted by / as featured in" logo strip that displays a titled row of brand logos (images or icons), either statically centered or as a continuously scrolling marquee.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | any | `'div'` | The element or component this renders as. |
| `title` | string | — | Heading text above the logos (renders in `<h2>`). |
| `items` | `(string \| { src, alt })[]` | — | Logo items: a string renders as an icon (Iconify name), an object renders as an avatar image. |
| `marquee` | `boolean \| MarqueeProps` | `false` | When truthy, logos scroll in a marquee; an object is forwarded as marquee props. |
| `class` | any | — | Classes for the root. |
| `ui` | object (slot map) | — | Per-slot class overrides. |

**Slots**: `default` — replaces the generated logo items entirely.

**Emits** — none.

**DOM structure**
```
root (div)
├─ title (h2, if title) → {{ title }}
└─ logos (marquee wrapper OR static div)
   └─ logo × items (avatar for {src,alt} objects, icon for strings)
```

**Theme**
```js
{
  slots: {
    root: "relative overflow-hidden",
    title: "text-lg text-center font-semibold text-highlighted",
    logos: "mt-10",
    logo: "size-10 shrink-0"
  },
  variants: {
    marquee: {
      false: { logos: "flex items-center shrink-0 justify-around gap-(--gap) [--gap:--spacing(16)]" }
    }
  }
}
```

---

# Blog

## BlogPost

A self-contained article card that displays a blog post's image, badge, date, title, description, and authors, optionally wrapped as a clickable link.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'article'` | The element or component this renders as. |
| `title` | `string` | — | Post title (rendered in `<h2>`). |
| `description` | `string` | — | Post description text. |
| `date` | `string \| Date` | — | Post date; formatted (medium style) into a `<time>` with ISO `datetime`. |
| `badge` | `string \| BadgeProps` | — | Badge in the meta row. String becomes `{ label }`; defaults `color="neutral" variant="subtle"`. |
| `authors` | `UserProps[]` | — | One author renders a user chip; multiple render an avatar group of linked avatars. |
| `image` | `string \| object` | — | Post image. String becomes `{ src, alt: title }`. |
| `orientation` | `'vertical' \| 'horizontal'` | `'vertical'` | Layout orientation. |
| `variant` | `'outline' \| 'soft' \| 'subtle' \| 'ghost' \| 'naked'` | `'outline'` | Visual style. |
| `to` | `LinkProps['to']` | — | Makes the whole card an overlay link. |
| `target` | `LinkProps['target']` | — | Link target. |
| `onClick` | `(event: MouseEvent) => void \| Promise<void>` | — | Click handler; also flags the card interactive. |
| `class` | `any` | — | Root class override. |
| `ui` | `BlogPost['slots']` | — | Per-slot class overrides. |

**Slots**: `date`, `badge`, `title`, `description`, `authors` (`{ ui }`), `header` (`{ ui }`), `body`, `footer` (footer renders only when provided).

**Emits** — none.

**DOM structure**
```
root (article, data-orientation)
├─ link overlay (if to; absolute inset-0)
├─ header (div, if image) 
│  └─ image (img, 16/9 cover)
├─ body (div)
│  ├─ meta (div, if date/badge)
│  │  ├─ badge
│  │  └─ date (time, datetime=ISO)
│  ├─ title (h2)
│  ├─ description (div)
│  └─ authors (div; user chip ×1 or avatar group ×N)
└─ footer (div, only if footer slot)
```
Card image scales up on hover when linked (`group-hover scale-110`).

**Theme**
```js
{
  slots: {
    root: "relative group/blog-post flex flex-col rounded-lg overflow-hidden",
    header: "relative overflow-hidden aspect-[16/9] w-full pointer-events-none",
    body: "min-w-0 flex-1 flex flex-col",
    footer: "",
    image: "object-cover object-top w-full h-full",
    title: "text-xl text-pretty font-semibold text-highlighted",
    description: "mt-1 text-base text-pretty",
    authors: "pt-4 mt-auto flex flex-wrap gap-x-3 gap-y-1.5",
    avatar: "",
    meta: "flex items-center gap-2 mb-2",
    date: "text-sm",
    badge: ""
  },
  variants: {
    orientation: {
      horizontal: { root: "lg:grid lg:grid-cols-2 lg:items-center gap-x-8", body: "justify-center p-4 sm:p-6 lg:px-0" },
      vertical: { root: "flex flex-col", body: "p-4 sm:p-6" }
    },
    variant: {
      outline: { root: "bg-default ring ring-default", date: "text-toned", description: "text-muted" },
      soft: { root: "bg-elevated/50", date: "text-muted", description: "text-toned" },
      subtle: { root: "bg-elevated/50 ring ring-default", date: "text-muted", description: "text-toned" },
      ghost: { date: "text-toned", description: "text-muted", header: "shadow-lg rounded-lg" },
      naked: { root: "p-0 sm:p-0", date: "text-toned", description: "text-muted", header: "shadow-lg rounded-lg" }
    },
    to: {
      true: {
        root: ["outline-primary/25 has-[>a:focus-visible]:outline-3", "transition"],
        image: "transform transition-transform duration-200 group-hover/blog-post:scale-110",
        avatar: "inline-flex transform transition-transform duration-200 hover:scale-115 rounded-full outline-primary/25 focus-visible:outline-3"
      }
    },
    image: { true: "" }
  },
  compoundVariants: [
    { variant: "outline", to: true, class: { root: "hover:bg-elevated/50" } },
    { variant: "soft", to: true, class: { root: "hover:bg-elevated" } },
    { variant: "subtle", to: true, class: { root: "hover:bg-elevated hover:ring-accented" } },
    { variant: ["outline", "subtle"], to: true, class: { root: "has-[>a:focus-visible]:ring-primary" } },
    { variant: "ghost", to: true, class: { root: "hover:bg-elevated/50", header: ["group-hover/blog-post:shadow-none", "transition-all"] } },
    { variant: "ghost", to: true, orientation: "vertical", class: { header: "group-hover/blog-post:rounded-b-none" } },
    { variant: "ghost", to: true, orientation: "horizontal", class: { header: "group-hover/blog-post:rounded-r-none" } },
    { orientation: "vertical", image: false, variant: "naked", class: { body: "p-0 sm:p-0" } }
  ],
  defaultVariants: { variant: "outline" }
}
```

## BlogPosts

A responsive grid/stack wrapper that lays out a list of BlogPost cards (or arbitrary default-slot content).

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'div'` | The element or component this renders as. |
| `posts` | `BlogPostProps[]` | — | Array of posts; each is spread onto a child BlogPost. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Grid (`horizontal`) vs stacked (`vertical`) layout. Each child BlogPost receives the *inverted* orientation (grid container → vertical cards, and vice versa). |
| `class` | `any` | — | Root class override. |
| `ui` | `{ base?: any }` | — | Class override for the single `base` slot. |

**Slots**: `default` (replaces the post loop) + every BlogPost slot re-exposed with `post` added to its scope payload.

**Emits** — none.

**DOM structure**
```
base (div, data-orientation)
└─ BlogPost × posts   (orientation inverted from container)
```

**Theme**
```js
{
  base: "flex flex-col gap-8 lg:gap-y-16",
  variants: {
    orientation: {
      horizontal: "sm:grid sm:grid-cols-2 lg:grid-cols-3",
      vertical: ""
    }
  }
}
```

---

# Site chrome

## FooterColumns

A footer navigation block that renders labeled columns of links, with optional left and right flanking regions.

**Props**

| name | type | default | description |
|------|------|---------|-------------|
| `as` | `any` | `'nav'` | The element or component this renders as. |
| `columns` | `FooterColumn[]` | — | Array of `{ label, children }`; each column renders a heading and a `<ul>` of link items. |
| `class` | `any` | — | Root class override. |
| `ui` | `FooterColumns['slots']` | — | Per-slot class overrides. |

`FooterColumn = { label: string; children?: FooterColumnLink[] }`; `FooterColumnLink` extends `LinkProps` with `{ label: string; icon?; class?; ui? }`.

**Slots**: `left`, `default` (replaces the columns loop), `right`, `column-label` (`{ column }`), `link` (`{ link, active, ui }`), `link-leading`, `link-label`, `link-trailing`.

**Emits** — none.

**DOM structure**
```
root (nav, 3-col grid on xl)
├─ left (div, if left slot)
├─ center (div, cols flow)
│  └─ per column (div)
│     ├─ label (h3) → column.label
│     └─ list (ul)
│        └─ item (li) × links
│           └─ link (anchor)
│              ├─ linkLeadingIcon (icon, if link.icon)
│              ├─ linkLabel (span) → link.label
│              │  └─ linkLabelExternalIcon (if target="_blank")
│              └─ [link-trailing]
└─ right (div, if right slot)
```

**Theme**
```js
{
  slots: {
    root: "xl:grid xl:grid-cols-3 xl:gap-8",
    left: "mb-10 xl:mb-0",
    center: "flex flex-col lg:grid grid-flow-col auto-cols-fr gap-8 xl:col-span-2",
    right: "mt-10 xl:mt-0",
    label: "text-sm font-semibold",
    list: "mt-6 space-y-4",
    item: "relative",
    link: "group text-sm flex items-center gap-1.5 rounded-sm outline-primary/25 focus-visible:outline-3",
    linkLeadingIcon: "size-5 shrink-0",
    linkLabel: "truncate",
    linkLabelExternalIcon: "size-3 absolute top-0 text-dimmed inline-block"
  },
  variants: {
    active: {
      true: { link: "text-primary font-medium" },
      false: { link: ["text-muted hover:text-default", "transition-colors"] }
    }
  }
}
```

## NavigationMenu

A horizontal menubar or vertical sidebar of links, supporting nested children, active highlighting, and (vertical) collapsible groups; interaction state is the open/active item value(s).

**Props** (key subset)

| name | type | default | description |
|---|---|---|---|
| `as` | any | `'div'` | Root element/component. |
| `type` | `'single' \| 'multiple'` | `'multiple'` | Single/multiple open groups (vertical only). |
| `modelValue` | string \| string[] | — | Controlled active/open value(s). |
| `defaultValue` | string \| string[] | — | Uncontrolled initial value(s). |
| `items` | `NavigationMenuItem[]` or nested arrays | — | Menu entries (nested arrays render as grouped sections). |
| `color` | color token | `'primary'` | Item color. |
| `variant` | `'pill' \| 'link'` | `'pill'` | Item style. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Menubar vs sidebar. |
| `collapsed` | boolean | `false` | Icons-only sidebar (vertical only). |
| `highlight` | boolean | — | Show a line next to the active item. |
| `highlightColor` | color token | `'primary'` | Color of that highlight line. |
| `trailingIcon` | icon | chevronDown | Expand icon for collapsible items. |
| `externalIcon` | boolean \| icon | external icon | Icon for external links; false to hide. |
| `tooltip` | boolean \| TooltipProps | `false` | Tooltip on items (vertical collapsed / horizontal). |
| `popover` | boolean \| PopoverProps | `false` | Popover with children when collapsed. |
| `arrow` | boolean | `false` | Arrow alongside the dropdown viewport. |
| `contentOrientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Dropdown content layout (horizontal only). |
| `valueKey` / `labelKey` | string | `'value'` / `'label'` | Item keys. |
| `class` / `ui` | — | — | Root class / per-slot overrides. |

**Item shape** (`NavigationMenuItem`, extends LinkProps): `label?`, `icon?`, `avatar?`, `badge?`, `chip?`, `tooltip?`, `popover?`, `trailingIcon?`, `type?` (`'label' | 'trigger' | 'link'`, default `link`), `slot?`, `value?`, `children?` (child items add `description?`, shown in horizontal orientation), `defaultOpen?`, `open?`, `onSelect?`, `to`/`href`/`target`, `disabled?`, `class?`, `ui?`.

**Slots**: `item`, `item-leading`, `item-label`, `item-trailing`, `item-content`, `list-leading`, `list-trailing`, plus per-item dynamic slots.

**Emits**: `update:modelValue`.

**DOM structure**
```
root
└─ list
   ├─ [list-leading]
   ├─ per item, one of:
   │  ├─ label item (vertical only) → linkLeadingIcon / linkLabel
   │  ├─ separator
   │  └─ item
   │     ├─ link/trigger (data-state=open, active styling)
   │     │  ├─ linkLeadingIcon | linkLeadingAvatar (optional chip)
   │     │  ├─ linkLabel (+ linkLabelExternalIcon)
   │     │  └─ linkTrailing → linkTrailingBadge, linkTrailingIcon (chevron, rotates open)
   │     └─ (children) content
   │        └─ childList (ul) → childItem (li) → childLink
   │           ├─ childLinkIcon
   │           └─ childLinkWrapper → childLinkLabel (+external), childLinkDescription (horizontal only)
   └─ [list-trailing]
(horizontal dropdowns:)
viewportWrapper → viewport (animated dropdown panel), indicator → arrow
```
Interaction model: horizontal = one open dropdown at a time (string value); vertical = accordion-style groups, `single` or `multiple` open. Active links get color + `before:bg-elevated`; `highlight` adds an `after:` line (bottom edge horizontal, start edge vertical). `collapsed` hides labels/trailing and shrinks links to icons.

**Theme** (abridged where rows are per-color generated; structure verbatim)
```js
{
  slots: {
    root: "relative flex gap-1.5 [&>div]:min-w-0",
    list: "isolate min-w-0",
    label: "w-full flex items-center gap-1.5 font-semibold text-xs/5 text-highlighted px-2.5 py-1.5",
    item: "min-w-0",
    link: "group relative w-full flex items-center gap-1.5 font-medium text-sm before:absolute before:z-[-1] before:rounded-md focus:outline-none focus-visible:outline-none focus-visible:before:outline-3",
    linkLeadingIcon: "shrink-0 size-5",
    linkLeadingAvatar: "shrink-0",
    linkLeadingAvatarSize: "2xs",
    linkLeadingChipSize: "sm",
    linkTrailing: "group ms-auto inline-flex gap-1.5 items-center",
    linkTrailingBadge: "shrink-0",
    linkTrailingBadgeSize: "sm",
    linkTrailingIcon: "size-5 transform shrink-0 group-data-[state=open]:rotate-180 transition-transform duration-200",
    linkLabel: "truncate",
    linkLabelExternalIcon: "inline-block size-3 align-top text-dimmed",
    childList: "isolate",
    childLabel: "text-xs text-highlighted",
    childItem: "",
    childLink: "group relative size-full flex items-start text-start text-sm before:absolute before:z-[-1] before:rounded-md focus:outline-none focus-visible:outline-none focus-visible:before:outline-3",
    childLinkWrapper: "min-w-0",
    childLinkIcon: "size-5 shrink-0",
    childLinkLabel: "truncate",
    childLinkLabelExternalIcon: "inline-block size-3 align-top text-dimmed",
    childLinkDescription: "text-muted",
    separator: "px-2 h-px bg-border",
    viewportWrapper: "absolute top-full left-0 flex w-full",
    viewport: "relative overflow-hidden bg-default shadow-lg rounded-md ring ring-default h-(--reka-navigation-menu-viewport-height) w-full transition-[width,height,left,right] duration-200 origin-[top_center] data-[state=open]:animate-[scale-in_100ms_ease-out] data-[state=closed]:animate-[scale-out_100ms_ease-in] z-1",
    content: "",
    indicator: "absolute left-0 data-[state=visible]:animate-[fade-in_100ms_ease-out] data-[state=hidden]:animate-[fade-out_100ms_ease-in] data-[state=hidden]:opacity-0 bottom-0 z-2 w-(--reka-navigation-menu-indicator-size) translate-x-(--reka-navigation-menu-indicator-position) flex h-2.5 items-end justify-center overflow-hidden transition-[translate,width] duration-200",
    arrow: "relative top-[50%] size-2.5 rotate-45 border border-default bg-default z-1 rounded-xs"
  },
  variants: {
    color: { /* per color: link/childLink outline-{color}/25; neutral: outline-inverted/25 */ },
    highlightColor: { /* per color: "" */ },
    variant: { pill: "", link: "" },
    orientation: {
      horizontal: {
        root: "items-center justify-between",
        list: "flex items-center",
        item: "py-2",
        link: "px-2.5 py-1.5 before:inset-x-px before:inset-y-0",
        childList: "grid p-2",
        childLink: "px-3 py-2 gap-2 before:inset-x-px before:inset-y-0",
        childLinkLabel: "font-medium",
        content: "absolute top-0 left-0 w-full max-h-[70vh] overflow-y-auto"
      },
      vertical: {
        root: "flex-col",
        link: "flex-row px-2.5 py-1.5 before:inset-y-px before:inset-x-0",
        childLabel: "px-1.5 py-0.5",
        childLink: "p-1.5 gap-1.5 before:inset-y-px before:inset-x-0"
      }
    },
    contentOrientation: {
      horizontal: {
        viewportWrapper: "justify-center",
        content: "data-[motion=from-start]:animate-[enter-from-left_200ms_ease] data-[motion=from-end]:animate-[enter-from-right_200ms_ease] data-[motion=to-start]:animate-[exit-to-left_200ms_ease] data-[motion=to-end]:animate-[exit-to-right_200ms_ease]"
      },
      vertical: {
        viewport: "sm:w-(--reka-navigation-menu-viewport-width) left-(--reka-navigation-menu-viewport-left) rtl:left-auto rtl:right-[calc(100%-var(--reka-navigation-menu-viewport-left)-var(--reka-navigation-menu-viewport-width))]"
      }
    },
    active: {
      true: { childLink: "before:bg-elevated text-highlighted", childLinkIcon: "text-default" },
      false: {
        link: "text-muted",
        linkLeadingIcon: "text-dimmed",
        childLink: ["hover:before:bg-elevated/50 text-default hover:text-highlighted", "transition-colors before:transition-colors"],
        childLinkIcon: ["text-dimmed group-hover:text-default", "transition-colors"]
      }
    },
    disabled: { true: { link: "cursor-not-allowed opacity-75" } },
    highlight: { true: "" },
    level: { true: "" },
    collapsed: { true: "" }
  },
  compoundVariants: [
    { orientation: "horizontal", contentOrientation: "horizontal", class: { childList: "grid-cols-2 gap-2" } },
    { orientation: "horizontal", contentOrientation: "vertical", class: { childList: "gap-1", content: "w-60" } },
    { orientation: "vertical", collapsed: false, class: {
        childList: "ms-5 border-s border-default", childItem: "ps-1.5 -ms-px",
        content: "data-[state=open]:animate-[collapsible-down_200ms_ease-out] data-[state=closed]:animate-[collapsible-up_200ms_ease-out] data-[state=closed]:overflow-hidden" } },
    { orientation: "vertical", collapsed: true, class: {
        link: "px-1.5", linkLabel: "hidden", linkTrailing: "hidden", content: "shadow-sm rounded-sm min-h-6 p-1" } },
    { orientation: "horizontal", highlight: true, class: {
        link: ["after:absolute after:-bottom-2 after:inset-x-2.5 after:block after:h-px after:rounded-full", "after:transition-colors"] } },
    { orientation: "vertical", highlight: true, level: true, class: {
        link: ["after:absolute after:-start-1.5 after:inset-y-0.5 after:block after:w-px after:rounded-full", "after:transition-colors"] } },
    { disabled: false, active: false, variant: "pill", class: {
        link: ["hover:text-highlighted hover:before:bg-elevated/50", "transition-colors before:transition-colors"],
        linkLeadingIcon: ["group-hover:text-default", "transition-colors"] } },
    { disabled: false, active: false, variant: "pill", orientation: "horizontal", class: {
        link: "data-[state=open]:text-highlighted", linkLeadingIcon: "group-data-[state=open]:text-default" } },
    { disabled: false, variant: "pill", highlight: true, orientation: "horizontal", class: { link: "data-[state=open]:before:bg-elevated/50" } },
    { disabled: false, variant: "pill", highlight: false, active: false, orientation: "horizontal", class: { link: "data-[state=open]:before:bg-elevated/50" } },
    // per color: { color, variant: "pill", active: true, class: { link: `text-${color}`, linkLeadingIcon: `text-${color} group-data-[state=open]:text-${color}` } }
    { color: "neutral", variant: "pill", active: true, class: {
        link: "text-highlighted", linkLeadingIcon: "text-highlighted group-data-[state=open]:text-highlighted" } },
    { variant: "pill", active: true, highlight: false, class: { link: "before:bg-elevated" } },
    { variant: "pill", active: true, highlight: true, disabled: false, class: {
        link: ["hover:before:bg-elevated/50", "before:transition-colors"] } },
    { disabled: false, active: false, variant: "link", class: {
        link: ["hover:text-highlighted", "transition-colors"],
        linkLeadingIcon: ["group-hover:text-default", "transition-colors"] } },
    { disabled: false, active: false, variant: "link", orientation: "horizontal", class: {
        link: "data-[state=open]:text-highlighted", linkLeadingIcon: "group-data-[state=open]:text-default" } },
    // per color: { color, variant: "link", active: true, class: { link: `text-${color}`, … } }
    { color: "neutral", variant: "link", active: true, class: {
        link: "text-highlighted", linkLeadingIcon: "text-highlighted group-data-[state=open]:text-highlighted" } },
    // per color: { highlightColor, highlight: true, level: true, active: true, class: { link: `after:bg-${color}` } }
    { highlightColor: "neutral", highlight: true, level: true, active: true, class: { link: "after:bg-inverted" } }
  ],
  defaultVariants: { color: "primary", highlightColor: "primary", variant: "pill" }
}
```

---

# Interactive

## Tabs

A horizontal or vertical set of triggers that switch between mutually-exclusive content panels; interaction state is the single active tab `value`.

**Props**

| name | type | default | description |
|---|---|---|---|
| `as` | any | `'div'` | Element/component the root renders as. |
| `items` | `TabsItem[]` | — | The tab definitions. |
| `color` | color token | `'primary'` | Indicator/active color. |
| `variant` | `'pill' \| 'link'` | `'pill'` | Visual style of the list. |
| `size` | `'xs' \| 'sm' \| 'md' \| 'lg' \| 'xl'` | `'md'` | Trigger sizing. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Layout direction. |
| `content` | boolean | `true` | If false, content panels are not rendered (triggers only). |
| `valueKey` / `labelKey` | string | `'value'` / `'label'` | Item keys. |
| `defaultValue` | string \| number | `'0'` | Initially active tab (uncontrolled). |
| `modelValue` | string \| number | — | Controlled active tab. |
| `activationMode` | `'automatic' \| 'manual'` | — | Whether focus activates a tab. |
| `unmountOnHide` | boolean | `true` | Unmount inactive panels. |
| `class` / `ui` | — | — | Root class / per-slot overrides. |

**Item shape** (`TabsItem`): `label?`, `icon?`, `avatar?`, `badge?` (string|number|BadgeProps), `slot?`, `content?` (panel text), `value?` (unique id, defaults to index), `disabled?`, `class?`, `ui?`.

**Slots**: `leading`, `default` (label), `trailing`, `content`, `list-leading`, `list-trailing`, plus per-item dynamic slots.

**Emits**: `update:modelValue`.

**DOM structure**
```
root
├─ list
│  ├─ indicator (sliding active marker, absolutely positioned)
│  ├─ [list-leading]
│  ├─ trigger × items (data-state=active|inactive, disabled)
│  │  ├─ leadingIcon | leadingAvatar
│  │  ├─ label (span)
│  │  └─ trailingBadge
│  └─ [list-trailing]
└─ content × items (if content; data-state, one visible)
```
Interaction model: exactly one trigger has `data-state="active"`; its matching panel (by `value`) is shown. The indicator translates to the active trigger via `--reka-tabs-indicator-*` CSS vars.

**Theme** (abridged where rows are per-color generated)
```js
{
  slots: {
    root: "flex items-center gap-2",
    list: "relative flex p-1 group",
    indicator: "absolute transition-[translate,width] duration-200",
    trigger: ["group relative inline-flex items-center min-w-0 data-[state=inactive]:text-muted hover:data-[state=inactive]:not-disabled:text-default font-medium rounded-md disabled:cursor-not-allowed disabled:opacity-75", "transition-colors"],
    leadingIcon: "shrink-0",
    leadingAvatar: "shrink-0",
    leadingAvatarSize: "",
    label: "truncate",
    trailingBadge: "shrink-0",
    trailingBadgeSize: "sm",
    content: "w-full rounded-md focus-visible:outline-3"
  },
  variants: {
    color: { /* per color: { content: `outline-${color}/25` }; neutral: outline-inverted/25 */ },
    variant: {
      pill: {
        list: "bg-elevated rounded-lg",
        trigger: ["grow", "before:content-[''] before:absolute before:inset-0 before:rounded-md before:shadow-xs before:-z-10 isolate"],
        indicator: "rounded-md shadow-xs"
      },
      link: {
        list: "border-default",
        indicator: "rounded-full",
        trigger: "after:content-[''] after:absolute after:rounded-full"
      }
    },
    orientation: {
      horizontal: {
        root: "flex-col",
        list: "w-full",
        indicator: "left-0 w-(--reka-tabs-indicator-size) translate-x-(--reka-tabs-indicator-position)",
        trigger: "justify-center"
      },
      vertical: {
        list: "flex-col",
        indicator: "top-0 h-(--reka-tabs-indicator-size) translate-y-(--reka-tabs-indicator-position)"
      }
    },
    size: {
      xs: { trigger: "px-2 py-1 text-xs gap-1", leadingIcon: "size-4", leadingAvatarSize: "3xs" },
      sm: { trigger: "px-2.5 py-1.5 text-xs gap-1.5", leadingIcon: "size-4", leadingAvatarSize: "3xs" },
      md: { trigger: "px-3 py-1.5 text-sm gap-1.5", leadingIcon: "size-5", leadingAvatarSize: "2xs" },
      lg: { trigger: "px-3 py-2 text-sm gap-2", leadingIcon: "size-5", leadingAvatarSize: "2xs" },
      xl: { trigger: "px-3 py-2 text-base gap-2", leadingIcon: "size-6", leadingAvatarSize: "xs" }
    }
  },
  compoundVariants: [
    { orientation: "horizontal", variant: "pill", class: { indicator: "inset-y-1" } },
    { orientation: "horizontal", variant: "link", class: {
        list: "border-b -mb-px", indicator: "-bottom-px h-px",
        trigger: "after:inset-x-0 after:-bottom-[calc(var(--spacing)+1px)] after:h-px" } },
    { orientation: "vertical", variant: "pill", class: { indicator: "inset-x-1", list: "items-center", trigger: "w-full justify-center" } },
    { orientation: "vertical", variant: "link", class: {
        list: "border-s -ms-px", indicator: "-start-px w-px",
        trigger: "after:inset-y-0 after:-start-[calc(var(--spacing)+1px)] after:w-px" } },
    // per color: pill → indicator `bg-${color}`, trigger active text-inverted + before:bg-{color}
    { color: "neutral", variant: "pill", class: {
        indicator: "bg-inverted",
        trigger: ["data-[state=active]:text-inverted outline-inverted/25 focus-visible:outline-3", "before:bg-inverted"] } },
    // per color: link → indicator `bg-${color}`, trigger active `text-${color}` + after:bg-{color}
    { color: "neutral", variant: "link", class: {
        indicator: "bg-inverted",
        trigger: ["data-[state=active]:text-highlighted outline-inverted/25 focus-visible:outline-3", "after:bg-inverted"] } }
  ],
  defaultVariants: { color: "primary", variant: "pill", size: "md" }
}
```

## Accordion

A vertical stack of collapsible header/panel sections; interaction state is which item value(s) are open (`single` or `multiple`).

**Props**

| name | type | default | description |
|---|---|---|---|
| `as` | any | `'div'` | Root element/component. |
| `items` | `AccordionItem[]` | — | Section definitions. |
| `type` | `'single' \| 'multiple'` | `'single'` | One or many open at once. |
| `collapsible` | boolean | `true` | Allow closing the open item (single mode). |
| `defaultValue` | string \| string[] | — | Uncontrolled open value(s). |
| `modelValue` | string \| string[] | — | Controlled open value(s). |
| `disabled` | boolean | — | Disable all triggers. |
| `trailingIcon` | icon | chevronDown | Expand/collapse icon. |
| `unmountOnHide` | boolean | `true` | Unmount closed panels. |
| `valueKey` / `labelKey` | string | `'value'` / `'label'` | Item keys. |
| `class` / `ui` | — | — | Root class / per-slot overrides. |

**Item shape** (`AccordionItem`): `label?`, `icon?` (leading), `trailingIcon?`, `slot?`, `content?` (panel text), `value?` (unique id, defaults to index), `disabled?`, `class?`, `ui?`.

**Slots**: `default` (label), `leading`, `trailing`, `content`, `body`, plus per-item dynamic slots. Slot props include `open: boolean`.

**Emits**: `update:modelValue`.

**DOM structure**
```
root (type=single|multiple)
└─ item × items (data-state=open|closed, data-disabled)
   ├─ header (div)
   │  └─ trigger (button)
   │     ├─ leadingIcon (if item.icon)
   │     ├─ label (span)
   │     └─ trailingIcon (chevron; rotates 180° when open)
   └─ content (only when content exists; data-state drives open/close animation)
      └─ body (div) → content text
```
Interaction model: in `single` mode at most one item is open (string value); in `multiple` mode any number (string[]). Content height animates with `accordion-down`/`accordion-up` keyframes.

**Theme**
```js
{
  slots: {
    root: "w-full",
    item: "border-b border-default last:border-b-0",
    header: "flex",
    trigger: "group flex-1 flex items-center gap-1.5 font-medium text-sm py-3.5 outline-primary/25 focus-visible:outline-3 min-w-0 rounded-md",
    content: "data-[state=open]:animate-[accordion-down_200ms_ease-out] data-[state=closed]:animate-[accordion-up_200ms_ease-out] data-[state=closed]:overflow-hidden focus:outline-none",
    body: "text-sm pb-3.5",
    leadingIcon: "shrink-0 size-5",
    trailingIcon: "shrink-0 size-5 ms-auto group-data-[state=open]:rotate-180 transition-transform duration-200",
    label: "text-start break-words"
  },
  variants: {
    disabled: { true: { trigger: "cursor-not-allowed opacity-75" } }
  }
}
```

## Stepper

A numbered progress indicator across sequential steps (horizontal or vertical); interaction state is the current step index/value.

**Props**

| name | type | default | description |
|---|---|---|---|
| `as` | any | `'div'` | Root element/component. |
| `items` | `StepperItem[]` | — (required) | The step definitions. |
| `size` | `'xs' \| 'sm' \| 'md' \| 'lg' \| 'xl'` | `'md'` | Indicator/text sizing. |
| `color` | color token | `'primary'` | Active/completed color. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Layout direction. |
| `valueKey` | string | `'value'` | Item key used as step value. |
| `defaultValue` | string \| number | — | Initially active step. |
| `disabled` | boolean | — | Disable all step triggers. |
| `linear` | boolean | `true` | Enforce sequential progression. |
| `modelValue` | string \| number | — | Controlled current step. |
| `class` / `ui` | — | — | Root class / per-slot overrides. |

**Item shape** (`StepperItem`): `slot?`, `value?`, `title?`, `description?`, `icon?`, `content?` (panel shown for the active step), `disabled?`, `class?`, `ui?`.

**Slots**: `indicator`, `wrapper`, `title`, `description`, `content`, plus per-item dynamic `{slot}-wrapper` / `{slot}-title` / `{slot}-description`.

**Emits**: `next` (item), `prev` (item), `update:modelValue`. Exposes `next()`, `prev()`, `hasNext`, `hasPrev`.

**DOM structure**
```
root
├─ header (div)
│  └─ item × items (data-state=completed|active|inactive, data-disabled)
│     ├─ container (div)
│     │  ├─ trigger (circular numbered/icon badge)
│     │  │  └─ indicator → icon (if item.icon) | step number
│     │  └─ separator (between steps, not after last)
│     └─ wrapper (div)
│        ├─ title (if item.title)
│        └─ description (if item.description)
└─ content (only the current step's content panel)
```
Interaction model: steps before the current are `completed`, the current is `active`, the rest `inactive`. Completed/active triggers get the color background; the separator fills up to completed steps.

**Theme**
```js
{
  slots: {
    root: "flex gap-4",
    header: "flex",
    item: "group text-center relative w-full",
    container: "relative",
    trigger: "rounded-full font-medium text-center align-middle flex items-center justify-center font-semibold group-data-[state=completed]:text-inverted group-data-[state=active]:text-inverted text-muted bg-elevated focus-visible:outline-3",
    indicator: "flex items-center justify-center size-full",
    icon: "shrink-0",
    separator: "absolute rounded-full group-data-[disabled]:opacity-75 bg-accented",
    wrapper: "",
    title: "font-medium text-default",
    description: "text-muted text-wrap",
    content: "size-full"
  },
  variants: {
    orientation: {
      horizontal: {
        root: "flex-col",
        container: "flex justify-center",
        separator: "top-[calc(50%-2px)] h-0.5",
        wrapper: "mt-1"
      },
      vertical: {
        header: "flex-col gap-4",
        item: "flex text-start",
        separator: "start-[calc(50%-1px)] -bottom-[10px] w-0.5"
      }
    },
    size: {
      xs: { trigger: "size-6 text-xs", icon: "size-3", title: "text-xs", description: "text-xs", wrapper: "mt-1.5" },
      sm: { trigger: "size-8 text-sm", icon: "size-4", title: "text-xs", description: "text-xs", wrapper: "mt-2" },
      md: { trigger: "size-10 text-base", icon: "size-5", title: "text-sm", description: "text-sm", wrapper: "mt-2.5" },
      lg: { trigger: "size-12 text-lg", icon: "size-6", title: "text-base", description: "text-base", wrapper: "mt-3" },
      xl: { trigger: "size-14 text-xl", icon: "size-7", title: "text-lg", description: "text-lg", wrapper: "mt-3.5" }
    },
    color: {
      // per color: trigger `group-data-[state=completed]:bg-${color} group-data-[state=active]:bg-${color} outline-${color}/25`,
      //            separator `group-data-[state=completed]:bg-${color}`
      neutral: {
        trigger: "group-data-[state=completed]:bg-inverted group-data-[state=active]:bg-inverted outline-inverted/25",
        separator: "group-data-[state=completed]:bg-inverted"
      }
    }
  },
  compoundVariants: [
    { orientation: "horizontal", size: "xs", class: { separator: "start-[calc(50%+16px)] end-[calc(-50%+16px)]" } },
    { orientation: "horizontal", size: "sm", class: { separator: "start-[calc(50%+20px)] end-[calc(-50%+20px)]" } },
    { orientation: "horizontal", size: "md", class: { separator: "start-[calc(50%+28px)] end-[calc(-50%+28px)]" } },
    { orientation: "horizontal", size: "lg", class: { separator: "start-[calc(50%+32px)] end-[calc(-50%+32px)]" } },
    { orientation: "horizontal", size: "xl", class: { separator: "start-[calc(50%+36px)] end-[calc(-50%+36px)]" } },
    { orientation: "vertical", size: "xs", class: { separator: "top-[30px]", item: "gap-1.5" } },
    { orientation: "vertical", size: "sm", class: { separator: "top-[38px]", item: "gap-2" } },
    { orientation: "vertical", size: "md", class: { separator: "top-[46px]", item: "gap-2.5" } },
    { orientation: "vertical", size: "lg", class: { separator: "top-[54px]", item: "gap-3" } },
    { orientation: "vertical", size: "xl", class: { separator: "top-[62px]", item: "gap-3.5" } }
  ],
  defaultVariants: { size: "md", color: "primary" }
}
```

## Button

A clickable button/link with leading/trailing icons, loading state, and a full color × variant × size styling matrix.

**Props** (Button-specific; also inherits all LinkProps: `to`, `href`, `target`, `active`, `disabled`, etc.)

| name | type | default | description |
|---|---|---|---|
| `label` | string | — | Button text. |
| `color` | color token | `'primary'` | Color. |
| `activeColor` | color token | — | Color applied when link is active. |
| `variant` | `'solid' \| 'outline' \| 'soft' \| 'subtle' \| 'ghost' \| 'link'` | `'solid'` | Visual style. |
| `activeVariant` | same | — | Variant applied when active. |
| `size` | `'xs' \| 'sm' \| 'md' \| 'lg' \| 'xl'` | `'md'` | Sizing. |
| `square` | boolean | — | Equal padding on all sides (auto-true when no label). |
| `block` | boolean | — | Full width. |
| `loading` | boolean | — | Show spinner. |
| `loadingAuto` | boolean | — | Auto loading based on `@click` promise / form submit. |
| `loadingIcon` | icon | — | Spinner icon. |
| `icon` / `leadingIcon` / `trailingIcon` | icon | — | Icons. |
| `avatar` | object | — | Leading avatar. |
| `leading` / `trailing` | boolean | — | Force icon position. |
| `type` | string | — | Button type (submit/button/reset). |
| `disabled` | boolean | — | Disable. |
| `onClick` | fn \| fn[] | — | Click handler(s). |
| `class` / `ui` | — | — | Base class / per-slot overrides. |

**Slots**: `leading`, `default` (label), `trailing` — each receives `{ ui }`.

**Emits** — none (native `click` via `onClick`).

**DOM structure**
```
base (<button> or <a>; active/variant/color state applied here)
├─ leadingIcon (icon; animate-spin when loading) | leadingAvatar
├─ label (span, if label set)
└─ trailingIcon (icon; animate-spin when loading and no leading)
```

**Theme** (full color × variant matrix; `{color}` = each configured token)
```js
{
  slots: {
    base: ["rounded-md font-medium inline-flex items-center disabled:cursor-not-allowed aria-disabled:cursor-not-allowed disabled:opacity-75 aria-disabled:opacity-75", "transition-colors"],
    label: "truncate",
    leadingIcon: "shrink-0",
    leadingAvatar: "shrink-0",
    leadingAvatarSize: "",
    trailingIcon: "shrink-0"
  },
  variants: {
    // + shared field-group variants (rounded-corner/ring adjustments inside button groups)
    color: { /* per color: "" */ },
    variant: { solid: "", outline: "", soft: "", subtle: "", ghost: "", link: "" },
    size: {
      xs: { base: "px-2 py-1 text-xs gap-1", leadingIcon: "size-4", leadingAvatarSize: "3xs", trailingIcon: "size-4" },
      sm: { base: "px-2.5 py-1.5 text-xs gap-1.5", leadingIcon: "size-4", leadingAvatarSize: "3xs", trailingIcon: "size-4" },
      md: { base: "px-2.5 py-1.5 text-sm gap-1.5", leadingIcon: "size-5", leadingAvatarSize: "2xs", trailingIcon: "size-5" },
      lg: { base: "px-3 py-2 text-sm gap-2", leadingIcon: "size-5", leadingAvatarSize: "2xs", trailingIcon: "size-5" },
      xl: { base: "px-3 py-2 text-base gap-2", leadingIcon: "size-6", leadingAvatarSize: "xs", trailingIcon: "size-6" }
    },
    block: { true: { base: "w-full justify-center", trailingIcon: "ms-auto" } },
    square: { true: "" },
    leading: { true: "" },
    trailing: { true: "" },
    loading: { true: "" },
    active: { true: { base: "" }, false: { base: "" } }
  },
  compoundVariants: [
    // color × variant (per non-neutral color):
    // solid:   text-inverted bg-{color} hover:bg-{color}/75 active:bg-{color}/75 disabled:bg-{color} aria-disabled:bg-{color} outline-{color}/25 focus-visible:outline-3
    // outline: ring ring-inset ring-{color}/50 text-{color} hover:bg-{color}/10 active:bg-{color}/10 disabled:bg-transparent outline-{color}/25 focus-visible:outline-3 focus-visible:ring-{color}
    // soft:    text-{color} bg-{color}/10 hover:bg-{color}/15 active:bg-{color}/15 outline-{color}/25 focus-visible:outline-3 disabled:bg-{color}/10
    // subtle:  text-{color} ring ring-inset ring-{color}/25 bg-{color}/10 hover:bg-{color}/15 active:bg-{color}/15 disabled:bg-{color}/10 outline-{color}/25 focus-visible:outline-3 focus-visible:ring-{color}
    // ghost:   text-{color} hover:bg-{color}/10 active:bg-{color}/10 outline-{color}/25 focus-visible:outline-3 disabled:bg-transparent
    // link:    text-{color} hover:text-{color}/75 active:text-{color}/75 disabled:text-{color} outline-{color}/25 focus-visible:outline-3
    // neutral color × variant:
    { color: "neutral", variant: "solid",
      class: "text-inverted bg-inverted hover:bg-inverted/90 active:bg-inverted/90 disabled:bg-inverted aria-disabled:bg-inverted outline-inverted/25 focus-visible:outline-3" },
    { color: "neutral", variant: "outline",
      class: "ring ring-inset ring-accented text-default bg-default hover:bg-elevated active:bg-elevated disabled:bg-default aria-disabled:bg-default outline-inverted/25 focus-visible:outline-3 focus-visible:ring-inverted" },
    { color: "neutral", variant: "soft",
      class: "text-default bg-elevated hover:bg-accented/75 active:bg-accented/75 outline-inverted/25 focus-visible:outline-3 disabled:bg-elevated aria-disabled:bg-elevated" },
    { color: "neutral", variant: "subtle",
      class: "ring ring-inset ring-accented text-default bg-elevated hover:bg-accented/75 active:bg-accented/75 disabled:bg-elevated aria-disabled:bg-elevated outline-inverted/25 focus-visible:outline-3 focus-visible:ring-inverted" },
    { color: "neutral", variant: "ghost",
      class: "text-default hover:bg-elevated active:bg-elevated outline-inverted/25 focus-visible:outline-3 hover:disabled:bg-transparent hover:aria-disabled:bg-transparent" },
    { color: "neutral", variant: "link",
      class: "text-muted hover:text-default active:text-default disabled:text-muted aria-disabled:text-muted outline-inverted/25 focus-visible:outline-3" },
    // square padding per size
    { size: "xs", square: true, class: "p-1" },
    { size: "sm", square: true, class: "p-1.5" },
    { size: "md", square: true, class: "p-1.5" },
    { size: "lg", square: true, class: "p-2" },
    { size: "xl", square: true, class: "p-2" },
    // loading spinner placement
    { loading: true, leading: true, class: { leadingIcon: "animate-spin" } },
    { loading: true, leading: false, trailing: true, class: { trailingIcon: "animate-spin" } }
  ],
  defaultVariants: { color: "primary", variant: "solid", size: "md" }
}
```
For a server-rendered port: resolve one color+variant+size row from the matrices above into literal class strings.

## Carousel

A swipeable slider of items with optional prev/next arrows, dot indicators, autoplay/auto-scroll, and fade transitions — built on **Embla Carousel** (a JS engine; plugins are lazy-imported per feature).

**Props** (Embla options are forwarded; key subset)

| name | type | default | description |
|---|---|---|---|
| `as` | any | `'div'` | Root element/component. |
| `items` | `CarouselItem[]` | — | Slide data; each renders through the default slot. |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | Scroll axis. |
| `arrows` | boolean | `false` | Show prev/next buttons. |
| `prev` / `next` | `ButtonProps` | — | Arrow button overrides. |
| `prevIcon` / `nextIcon` | icon | arrowLeft/arrowRight (RTL-aware) | Arrow icons. |
| `dots` | boolean | `false` | Show dot indicators. |
| `autoplay` | boolean \| object | `false` | Autoplay plugin (stops on interaction by default). |
| `autoScroll` | boolean \| object | `false` | Continuous auto-scroll plugin. |
| `autoHeight` | boolean \| object | `false` | Height follows the active slide. |
| `fade` | boolean \| object | `false` | Cross-fade instead of translate (forces align center). |
| `wheelGestures` | boolean \| object | `false` | Mouse-wheel navigation plugin. |
| `loop` | boolean | `false` | Infinite loop. |
| `align` | `'start' \| 'center' \| 'end'` | `'center'` | Slide alignment in the viewport. |
| `slidesToScroll` | number \| 'auto' | `1` | Slides advanced per action. |
| `dragFree` | boolean | `false` | Momentum scrolling instead of snap. |
| `startIndex` | number | `0` | Initial slide. |
| `breakpoints` | object | `{}` | Per-media-query Embla option overrides. |
| `class` / `ui` | — | — | Root class / per-slot overrides. |

**Slots**: `default` — scope `{ item, index }` (one invocation per item).

**Emits**: `select` (payload: selected index).

**DOM structure**
```
root (div, role="region", aria-roledescription="carousel", tabindex for arrow keys)
├─ viewport (div, overflow-hidden; Embla mounts here)
│  └─ container (flex row/col; translated by Embla)
│     └─ item × items (min-w-0 shrink-0 basis-full; slot content)
└─ controls
   ├─ arrows → prev (Button, absolute) / next (Button, absolute)
   └─ dots (tablist) → dot × snaps (button, data-state=active)
```
Interaction model: Embla translates the `container`; slide width comes from the `item` basis (`basis-full` = 1/view; override via `ui.item`, e.g. `basis-1/3`). Keyboard arrows scroll prev/next; interaction stops autoplay. State: selected index + can-scroll flags; the active dot carries `data-state="active"`.

**Theme**
```js
{
  slots: {
    root: "relative focus:outline-none",
    viewport: "overflow-hidden",
    container: "flex items-start",
    item: "min-w-0 shrink-0 basis-full",
    controls: "",
    arrows: "",
    prev: "absolute rounded-full",
    next: "absolute rounded-full",
    dots: "absolute inset-x-0 -bottom-7 flex flex-wrap items-center justify-center gap-3",
    dot: ["cursor-pointer size-3 bg-accented rounded-full outline-inverted/25 focus-visible:outline-3", "transition"]
  },
  variants: {
    orientation: {
      vertical: {
        container: "flex-col -mt-4",
        item: "pt-4",
        prev: "top-4 sm:-top-12 left-1/2 -translate-x-1/2 rotate-90 rtl:-rotate-90",
        next: "bottom-4 sm:-bottom-12 left-1/2 -translate-x-1/2 rotate-90 rtl:-rotate-90"
      },
      horizontal: {
        container: "flex-row -ms-4",
        item: "ps-4",
        prev: "start-4 sm:-start-12 top-1/2 -translate-y-1/2",
        next: "end-4 sm:-end-12 top-1/2 -translate-y-1/2"
      }
    },
    active: {
      true: { dot: "data-[state=active]:bg-inverted" }
    }
  }
}
```
Server-rendered port note: the engine (translate-based sliding, loop, autoplay) is JS; the equivalent no-JS base is a CSS scroll-snap container (`overflow-x-auto snap-x snap-mandatory` on the viewport, `snap-start` + flex basis on items), which keeps native touch swipe and can be progressively enhanced with arrows/dots.
