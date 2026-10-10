// Site › Appearance's tabs (appearance tabs spec §2): which exist, which one the URL's `?tab=`
// opens, and which tab holds each of the page's settings keys — for the unsaved and error dots.

export type AppearanceTab = 'theme' | 'colours' | 'design' | 'typefaces' | 'logos'

const ORDER: readonly AppearanceTab[] = ['theme', 'colours', 'design', 'typefaces', 'logos']

export const TAB_LABELS: Record<AppearanceTab, string> = {
  theme: 'Theme',
  colours: 'Colours',
  design: 'Design',
  typefaces: 'Typefaces',
  logos: 'Logos & site icon',
}

export const TAB_KEYS: Record<AppearanceTab, readonly string[]> = {
  theme: ['theme'],
  colours: [
    'theme_accent',
    'theme_neutral',
    'theme_neutral_custom',
    'theme_dark_base',
    'theme_brand_colors',
  ],
  design: ['theme_radius', 'theme_background'],
  typefaces: ['theme_font', 'theme_font_text_family', 'theme_font_headings_family'],
  logos: ['site_logo', 'site_logo_dark', 'site_favicon'],
}

/** The tabs shown: Theme only while there is a theme to choose. */
export function availableTabs(hasThemes: boolean): AppearanceTab[] {
  return ORDER.filter((tab) => hasThemes || tab !== 'theme')
}

/** The tab a `?tab=` value opens; anything else — absent, unknown, hidden — opens the first. */
export function tabFromQuery(value: unknown, tabs: readonly AppearanceTab[]): AppearanceTab {
  return tabs.includes(value as AppearanceTab) ? (value as AppearanceTab) : tabs[0]!
}

/** The tabs holding these fields; a nested name (`theme_brand_colors.colors.1.name`) counts as its key. */
export function tabsHolding(fields: Iterable<string>): Set<AppearanceTab> {
  const out = new Set<AppearanceTab>()
  for (const field of fields) {
    const key = field.split('.')[0]!
    for (const tab of ORDER) if (TAB_KEYS[tab].includes(key)) out.add(tab)
  }
  return out
}
