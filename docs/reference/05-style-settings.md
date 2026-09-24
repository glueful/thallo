---
title: "Style settings reference"
slug: style-settings
section: reference
order: 5
summary: "Every setting in the Style and Layout tabs, its choices, and the CSS it becomes."
---

Every managed style setting Thallo has: the name it carries in the inspector, its path in an
entry's data, the values it takes, whether it can differ by screen width, the class it compiles
to and the CSS that class declares. This page is for theme authors, and for anyone who wants to
know exactly what a setting does to a page. To use the settings rather than look them up, read
[the Design view](../concepts/03-design-view.md).

## The vocabulary

A token setting never holds a CSS value. It holds a name from one of six domains, and the
[theme](../guides/13-make-a-theme.md) maps every name to a value in `theme.json`. The names are
ordinal scales, not promises about pixels.

| Domain | Names |
|---|---|
| `spacing` | `none` `xs` `sm` `md` `lg` `xl` `2xl` `3xl` |
| `width` | `narrow` `content` `container` `full` |
| `radius` | `none` `sm` `md` `lg` `full` |
| `color` | `background` `surface` `surface-2` `text` `muted` `line` `accent` `accent-contrast` `transparent` `white` |
| `shadow` | `none` `xs` `sm` `md` `lg` `xl` |
| `typography.size` | `xs` `sm` `md` `lg` `xl` `2xl` `3xl` |

The compiler turns each mapping into one custom property on `:root`, the token's name with its
dots replaced by hyphens and `--t-` in front: `spacing.lg` becomes `--t-spacing-lg`,
`typography.size.2xl` becomes `--t-typography-size-2xl`, `color.accent` becomes
`--t-color-accent`. Those are the variables the utilities below read, so a theme that re-maps a
token re-skins every block that used it.

`color.white` fills itself in as `#ffffff` when a theme omits it; every other name has to be
mapped, or the theme fails validation. There are no extra tokens: a document may reference
baseline names only.

## The breakpoints

There are three, and they are mobile-first.

| Breakpoint | From | Stage preset |
|---|---|---|
| `base` | 0px | Mobile |
| `md` | 768px | Tablet |
| `lg` | 1024px | Desktop |

A responsive setting holds a sparse map: a value at any of the three, or none. To resolve one at
a target breakpoint, Thallo walks down from it — `lg`, then `md`, then `base` — and takes the
first declaration it finds, so a value set at `base` reaches every width. A setting the tables
below mark "no" is not responsive: it resolves at `base` only, holds one value for every screen
width, and its row in the inspector offers no breakpoints to choose between.

## How a setting becomes CSS

Settings live on the block, in `settings.style`, as typed values under the property's path. A
responsive property nests its values under breakpoint keys; a non-responsive one carries the
typed value directly.

```json
{
  "style": {
    "spacing": { "padding": { "top": { "base": { "type": "token", "value": "spacing.md" },
                                        "lg": { "type": "token", "value": "spacing.2xl" } } } },
    "radius": { "type": "token", "value": "radius.lg" },
    "typography": { "weight": { "base": { "type": "choice", "value": "bold" } } }
  }
}
```

The three value kinds are `token`, `choice` and `reset`. A token must be a baseline name of the
property's own domain; a choice must be one of the property's listed values.

The renderer resolves each property through the cascade — the block's style classes in list
order, then the block's own settings — and emits one utility class per breakpoint where the
result is declared exactly. An inherited value emits nothing, because the compiled artifact is
already mobile-first. The layout properties and **Distribute** are the exception: they emit at
every breakpoint they resolve to anything, because the rules for grid spans and for a mode's
dormant settings have to read the parent's state at each width.

A class is `t-` plus the property's stem plus the value's last segment, with a breakpoint prefix
above `base`: `t-pt-lg`, `md:t-pt-lg`, `lg:t-radius-full`. A slash in a value becomes a hyphen,
so `layout.basis` `1/3` emits `t-basis-1-3`. In the stylesheet the colon is escaped —
`.md\:t-pt-lg`.

Three stylesheets deliver all of it, in this order:

```text
/_thallo/layers.css                  @layer theme, settings;
/theme-assets/theme-{hash}.css       @layer theme    — the theme's own CSS
/theme-assets/settings-{hash}.css    @layer settings — :root {--t-*}, then the utilities
```

The utilities are emitted base first, then inside `@media (min-width: 768px)`, then inside
`@media (min-width: 1024px)`. Because `settings` is the later layer, a setting always beats the
theme, whatever the selectors say — which is why the theme build refuses `!important` on a
managed property in any rule that names `.thallo-block`. The site's own custom CSS is unlayered,
so it still wins over both.

Both artifacts are served by content hash, so changing a theme's CSS or its vocabulary re-keys
every cached page.

## How a reset works

**Reset to theme** writes `{"type": "reset"}` at the breakpoint you are on. Two things follow.

In the data, resolution stops there: nothing from a narrower breakpoint and nothing from a style
class shows through, and the property falls to the theme.

In the CSS, the block gets `t-{stem}-reset` — `t-radius-reset`, `md:t-pt-reset` — whose rule
sets every CSS property that setting writes to `revert-layer`, handing the property back to
`@layer theme` from that breakpoint up.

Six settings have an empty reset rule, and nothing to revert. **Border sides** and **Background
opacity** modify what another setting declares and share its CSS properties, so reverting would
undo that setting too; **Entrance**, **Repeat** and **Stagger children** carry no declaration of
their own on the element. Their reset still stops resolution.

**Clear**, beside it, is different: it removes the declaration entirely and lets whatever is
underneath — a narrower breakpoint, a style class, the theme — show through.

## The Style tab

The tab shows a group only where the block declares at least one of its settings; a block that
declares none says **This block declares no styling**. Padding and margin are each drawn as one
row of cells, labelled **Padding** and **Margin**.

### Spacing

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Padding top | `spacing.padding.top` | yes | `t-pt-{name}` | `padding-top: var(--t-spacing-{name})` |
| Padding right | `spacing.padding.right` | yes | `t-pr-{name}` | `padding-right: var(--t-spacing-{name})` |
| Padding bottom | `spacing.padding.bottom` | yes | `t-pb-{name}` | `padding-bottom: var(--t-spacing-{name})` |
| Padding left | `spacing.padding.left` | yes | `t-pl-{name}` | `padding-left: var(--t-spacing-{name})` |
| Margin top | `spacing.margin.top` | yes | `t-mt-{name}` | `margin-top: var(--t-spacing-{name})` |
| Margin bottom | `spacing.margin.bottom` | yes | `t-mb-{name}` | `margin-bottom: var(--t-spacing-{name})` |

All six take a `spacing` token. There is no left or right margin: horizontal placement is
**Placement**, on the Layout tab.

### Text

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Text alignment | `alignment.text` | yes | `t-text-{start,center,end}` | `text-align: start` / `center` / `end` |

### Typography

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Size | `typography.size` | yes | `t-size-{name}` | `font-size: var(--t-typography-size-{name})` |
| Weight | `typography.weight` | yes | `t-weight-{value}` | `font-weight:` `regular` 400, `medium` 500, `semibold` 600, `bold` 700 |
| Line height | `typography.line_height` | yes | `t-leading-{value}` | `line-height:` `tight` 1.1, `snug` 1.25, `normal` 1.5, `relaxed` 1.65, `loose` 1.9 |

The line height is unitless, so it follows whatever the Size setting beside it resolves to.

### Colours

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Background | `colors.surface` | no | `t-bg-{name}` | `--t-surface: var(--t-color-{name}); background: var(--t-color-{name})` |
| Text colour | `colors.text` | no | `t-fg-{name}` | `color: var(--t-color-{name})` |
| Border colour | `colors.border` | no | `t-bc-{name}` | `border-color: var(--t-color-{name})` |
| Background opacity | `colors.surface_opacity` | no | `t-bgo-{100,90,80,70,60,50}` | `background: color-mix(in srgb, var(--t-surface, var(--t-surface-default, transparent)) {n}%, transparent)` |
| Backdrop blur | `backdrop.blur` | no | `t-blur-{value}` | `backdrop-filter` and `-webkit-backdrop-filter:` `none`, `blur(4px)`, `blur(12px)`, `blur(24px)` |

Background compiles to the `background` shorthand, so it owns the whole background: a theme rule
that paints the block with a gradient or an image yields to it, `color.transparent` clears it,
and a reset gives the theme's background back. It also names its own colour in `--t-surface` so
that Background opacity has something to mix; failing that, the opacity mixes whatever the theme
named in `--t-surface-default` on the element, and failing that it changes nothing. Neither
variable inherits, so a child given only an opacity never mixes its parent's colour.

A blur only shows through a background that is not opaque. Background opacity and Backdrop blur
are the `backdrop` group, which a block type opts into separately from `colors`.

### Effects

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Shadow | `shadow` | yes | `t-shadow-{name}` | `box-shadow: var(--t-shadow-{name})` |
| Corners | `radius` | no | `t-radius-{name}` | `border-radius: var(--t-radius-{name})` |
| Border width | `border.width` | no | `t-bw-{value}` | `border-width:` `none` 0, `thin` 1px, `thick` 2px |
| Border style | `border.style` | no | `t-bs-{solid,dashed}` | `border-style: solid` / `dashed` |
| Border sides | `border.sides` | no | `t-bsides-{value}` | `all` declares nothing; a side sets `border-{other}-width: 0` on the other three |

Border sides is the last property in the contract's table, so its rule follows the width's: at
equal specificity the later rule takes three of the four sides away.

### Marker

The Feature block's icon chip or number badge is a target of its own, so it has its own corners
and shadow beside the card's.

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Corners | `marker.radius` | no | `t-mradius-{name}` | `border-radius: var(--t-radius-{name})` |
| Shadow | `marker.shadow` | yes | `t-mshadow-{name}` | `box-shadow: var(--t-shadow-{name})` |

### Tabs

The Tabs block's strip, beside the panels area, which the block's own Corners setting governs.

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Bar corners | `tabs.bar_radius` | no | `t-barradius-{name}` | `border-radius: var(--t-radius-{name})` |
| Active tab corners | `tabs.tab_radius` | no | `t-tabradius-{name}` | `border-radius: var(--t-radius-{name})` |

### Aside

The Hero block's aside — the blocks in its media column — is a target of its own, so it can be
padded and filled as a panel beside the band's own spacing and background. Its corners and shadow
are the block's Corners and Shadow, which already land on the aside.

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Padding top | `aside.padding.top` | yes | `t-apadt-{name}` | `padding-top: var(--t-spacing-{name})` |
| Padding right | `aside.padding.right` | yes | `t-apadr-{name}` | `padding-right: var(--t-spacing-{name})` |
| Padding bottom | `aside.padding.bottom` | yes | `t-apadb-{name}` | `padding-bottom: var(--t-spacing-{name})` |
| Padding left | `aside.padding.left` | yes | `t-apadl-{name}` | `padding-left: var(--t-spacing-{name})` |
| Background | `aside.surface` | no | `t-abg-{name}` | `background: var(--t-color-{name})` |

The padding is set a side at a time, in the same four-cell box as the block's own Padding.
1.0.0-beta.56 and 57 stored it as one value, `aside.padding`; that path is retired, so a stored
value has no effect and is dropped the next time the page is saved.

### Motion

None of these is responsive: an entrance is one event, not a layout. See
[animate blocks as they scroll into view](../guides/06-animation.md) for what they look like.

| Setting | Path | Class | Declaration |
|---|---|---|---|
| Entrance | `motion.entrance` | `t-enter-{value}` | `--t-enter-transform:` `fade` none, `fade-up` `translateY(1.5rem)`, `fade-down` `translateY(-1.5rem)`, `slide-left` `translateX(2rem)`, `slide-right` `translateX(-2rem)`, `zoom-in` `scale(0.92)`; `none` declares nothing |
| Duration | `motion.duration` | `t-enterdur-{value}` | `--t-enter-duration:` `fast` 300ms, `normal` 600ms, `slow` 1000ms |
| Delay | `motion.delay` | `t-enterdelay-{value}` | `--t-enter-delay:` `none` 0ms, `short` 150ms, `medium` 300ms, `long` 600ms |
| Repeat | `motion.repeat` | `t-enterrepeat-{once,always}` | none; the page's script reads the class |
| Stagger children | `motion.stagger` | `t-stagger-{value}` | none on the element; it sets `--t-enter-stagger` on the children |
| Ken Burns | `motion.ken_burns` | `t-kenburns-{value}` | `overflow: clip` on the frame; `none` declares nothing |

What makes an entrance move is one shared rule, which applies only where the visitor has not
asked for reduced motion, the page's script has set `data-thallo-motion` on `<html>`, and the
block has not entered yet. Without JavaScript nothing is ever hidden.

Stagger sets `--t-enter-stagger` on each child after the first, in steps of 80ms (`short`),
150ms (`medium`) or 250ms (`long`), and stops growing at the twelfth child. A `<script>` among
the children is not counted. The delay a block waits is its own Delay plus its stagger.

Ken Burns drifts the `img`, `picture` or `video` that is the direct child of the element
carrying the frame: `zoom-in` runs `scale(1)` to `scale(1.15)`, `zoom-out` the reverse,
`pan-left` and `pan-right` a `scale(1.12)` with a 3% translation each way, over a 20-second
alternating loop.

### Visibility

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Visibility | `visibility` | yes | `t-vis-{visible,hidden}` | `display: revert-layer` / `display: none` |

Visibility is the only setting that writes `display` on a block. Minimum height deliberately
does not, so a hidden band stays hidden however tall it is told to be.

## The Layout tab

Three sections, all driven by what the block declares: **Container**, how it arranges its
children; **Box**, its own size and place; and **As an item**, how it sits inside its parent. A
block that declares none of them says **This block declares no layout**.

### Container

The mode's own controls sit under **Layout**, and the tab shows only the ones that mode uses. A
mode switch deletes nothing: the tab lists what is being kept and ignored.

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Layout | `layout.display` | yes | `t-display-{flex,grid}` | `display: flex` / `grid` |
| Content width | `layout.content_width` | yes | `t-cw-{name}` | `max-width: var(--t-width-{name}); margin-inline: auto; --thallo-default-gutter: var(--t-spacing-lg)` — for `full`, `max-width: none` and a `0px` gutter |
| Gutter | `layout.gutter` | yes | `t-gutter-{name}` | `padding-inline: var(--t-spacing-{name})` |
| Columns | `layout.columns` | yes | `t-cols-{value}` | `grid-template-columns` |
| Direction | `layout.direction` | yes | `t-dir-{value}` | `flex-direction: row` / `column` / `row-reverse` / `column-reverse` |
| Wrap | `layout.wrap` | yes | `t-wrap-{nowrap,wrap}` | `flex-wrap: nowrap` / `wrap` |
| Distribute | `alignment.content` | yes | `t-content-{value}` | `justify-content:` `start` flex-start, `center`, `end` flex-end, `between` space-between, `around` space-around, `evenly` space-evenly |
| Align | `layout.align_items` | yes | `t-items-{value}` | `align-items:` `start` flex-start, `center`, `end` flex-end, `stretch`, `baseline` |
| Gap, column | `layout.gap.column` | yes | `t-gapx-{name}` | `column-gap: var(--t-spacing-{name})` |
| Gap, row | `layout.gap.row` | yes | `t-gapy-{name}` | `row-gap: var(--t-spacing-{name})` |

There are two modes and no third: a container's children are block-level boxes, so the theme's
default — a flex column with a `spacing.xl` gap — is what block flow used to be.

The column presets and the tracks each compiles to:

| Value | `grid-template-columns` |
|---|---|
| `1` | `minmax(0, 1fr)` |
| `2` `3` `4` `6` `12` | `repeat(n, minmax(0, 1fr))` |
| `1-2` `2-1` `1-3` `3-1` | the two ratios, as `minmax(0, {n}fr)` each |
| `1-2-1` `1-1-2` `2-1-1` | the three ratios, the same way |
| `auto` | `grid-template-columns: none` |

`auto` is not a choice in the tab. It is the default track state, and a container that declares
no column count still emits it at every breakpoint so that a child's Span has something to pair
with.

Content width also carries the gutter's default, on the element itself, so a boxed ancestor's
gutter never reaches a full-width container nested inside it.

### Box

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Width | `width` | yes | `t-w-{name}` | `max-width: var(--t-width-{name}); width: 100%` — for `full`, `max-width: none` |
| Placement | `alignment.self` | yes | `t-self-{value}` | `margin-inline:` `start` `0 auto`, `center` `auto`, `end` `auto 0` |
| Minimum height | `layout.min_height` | yes | `t-minh-{value}` | `min-height:` `auto` auto, `half` 50vh, `screen` 100vh, with `--thallo-root-layout: block` for `auto` and `flex` otherwise |
| Overflow | `layout.overflow` | no | `t-overflow-{visible,hidden,auto}` | `overflow: visible` / `hidden` / `auto` |
| Content alignment | `alignment.content` | yes | `t-content-{value}` | as above |

Width means "fill the available space, up to this maximum", which is why it asks for
`width: 100%` as well. That renders as `auto` did in block flow only under `box-sizing:
border-box`, which is the theme's to provide. In a flex row the width is the item's starting
size, so it sizes items and can wrap them.

Overflow is the one layout setting that is not responsive: an overflow that changed with the
viewport would hide content at one width and not another. The tab says **Applies at all sizes**.

**Content alignment** is the same property as **Distribute**, shown here instead for a block
that distributes its own content without arranging children: the Navigation, Button, Color mode and
Social links blocks.

### As an item

These resolve against the parent's mode at the same breakpoint, so a grid parent offers Span and
Align self, and a flex parent offers Basis, Grow, Shrink and Align self.

| Setting | Path | Responsive | Class | Declaration |
|---|---|---|---|---|
| Span | `layout.span` | yes | `t-span-{1..12,full}` | `grid-column`, paired with the parent's track class |
| Basis | `layout.basis` | yes | `t-basis-{value}` | `flex-basis:` `auto`, `1-4` 25%, `1-3` 33.333%, `1-2` 50%, `2-3` 66.667%, `3-4` 75%, `full` 100% |
| Grow | `layout.grow` | yes | `t-grow-{0,1}` | `flex-grow: 0` / `1` |
| Shrink | `layout.shrink` | yes | `t-shrink-{0,1}` | `flex-shrink: 0` / `1` |
| Align self | `layout.align_self` | yes | `t-aself-{value}` | `align-self:` `start` flex-start, `center`, `end` flex-end, `stretch` |

Span never has a rule of its own. The compiler writes one rule for every pairing of a track
count with a span, at each breakpoint, so exactly one rule matches and the later one wins:

```css
.t-cols-3 > .t-span-2 { grid-column: span 2; }
.t-cols-3 > .t-span-4 { grid-column: 1 / -1; }
.t-cols-3 > .t-span-full { grid-column: 1 / -1; }
.t-cols-3 > .t-span-reset { grid-column: revert-layer; }
```

A span wider than the parent's tracks is clamped to the whole row. Each rule is written twice,
the second reaching through the `.thallo-preview-block` wrapper the stage puts around every
block.

## Which settings a block offers

A block type declares two things. `style_capabilities` is the list of property paths it
supports, written as exact paths or as group names that expand to them; undeclared means the
setting is not offered, and there is no wildcard. `style_targets` names the parts of the block's
template that can take settings, gives each part a kind, and maps each capability to one of
them.

The group names a capability list may use are the contract's own: `spacing`, `width`,
`alignment`, `typography`, `visibility`, `shadow`, `radius`, `colors`, `border`, `layout`,
`layout.item`, `marker`, `tabs`, `backdrop`, `motion`, `motion.children`, `motion.media`.

A target's kind decides what may land on it, and the declaration is refused otherwise.

| Kind | What it is | Settings only it may take |
|---|---|---|
| `text` | a run of text | Text alignment |
| `row` | a line of items the block itself arranges | Distribute |
| `stack` | a content area that arranges child blocks | Layout, Direction, Wrap, Align, Columns, both Gaps, Content width, Gutter |
| `box` | any other element | Minimum height, Overflow |

Placement needs a `box` or a `text` target; the item settings need a `box`, `row` or `text`
target; Distribute needs a `row` or a `stack`. Everything else may land on any kind.

A template styles a target by putting `{{ style_classes('name') }}` inside that element's class
attribute and `{{ style_attrs('name') }}` on its tag. The template lint holds it to the
declaration: every declared target is styled, no undeclared target is used, and no template
writes a `style` attribute or a `<style>` element. Which blocks declare what is in
[the block library](04-block-library.md).

The Advanced tab's four values go through the same map, each owned by exactly one target: the
anchor becomes that element's `id`, `data-*` attributes are copied to it, and the accessibility
label becomes its `aria-label`. Your own CSS class names are appended to the same element's
classes. A `data-thallo-` prefix is reserved.

### A block type made in the admin

A block type created under **Settings › Block Types** has one target — `root`, a box: its
outermost element — because nobody is there to say which element each setting should land on.
Its editor has a **Style settings** card, and the groups it may tick are these fourteen, in this
order: **Spacing**, **Width**, **Placement**, **Typography**, **Colours**, **Backdrop**,
**Corners**, **Border**, **Shadow**, **Visibility**, **Minimum height**, **Overflow**, **Sizing
in a parent layout**, **Motion**.

Text alignment is not offered, because it needs a `text` target, and neither are the groups that
arrange a container's children. A block that needs those, or more than one target, is declared
in code. A block type Thallo or a pack declares shows its groups read-only: they are set in code
and re-synced on every upgrade. See
[make your own block type](../guides/14-make-a-block-type.md).

### Regions and pages

The header and footer take a smaller set: `spacing`, `shadow`, `radius`, `colors` and `border`,
plus the `backdrop` pair. Not visibility, not layout, not typography. They have two targets —
the bar itself, and the content inside it held to the container's measure — and padding lands on
the content, everything else on the bar. See
[the header and the footer](../guides/02-header-and-footer.md).

A page's own style frame, on the **Page** tab, takes `spacing` and `colors.surface` only.

## Style classes declare the same things

A [style class](../guides/05-style-classes.md) holds a `style` object in exactly the schema
above, sparse breakpoints and resets included, and it is validated against the whole contract
rather than against any block's capabilities. Applied to a block, each declaration lands where
the block has the capability and lies dormant everywhere else, so a theme never sees classes as
such — only the utilities the cascade resolves to.

The cascade resolves per property, not per group. A class that sets Grid and a direction
contributes that direction to a block whose effective mode is Flex, because the block itself or
a later class set it so.

## What the browser must support

The public site needs cascade layers, `revert-layer` and `color-mix()`: Chrome 111, Firefox 113
and Safari 16.2 or newer.
