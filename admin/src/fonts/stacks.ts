// The named built-in font stacks, mirroring `Thallo\Contracts\Style\FontStacks` (block typeface spec
// §2.1): the Typeface control sets each built-in option, and each uploaded family's fallback, in the
// stack the site will use.
export const FONT_STACKS: Record<string, string> = {
  serif: '"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif',
  humanist: 'Seravek,"Gill Sans Nova",Ubuntu,Calibri,"DejaVu Sans",source-sans-pro,sans-serif',
  geometric: 'Avenir,Montserrat,Corbel,"URW Gothic",source-sans-pro,sans-serif',
  slab: 'Rockwell,"Rockwell Nova","Roboto Slab","DejaVu Serif","Sitka Small",serif',
  mono: 'ui-monospace,"Cascadia Code","Source Code Pro",Menlo,Consolas,"DejaVu Sans Mono",monospace',
  system: 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
}

const FALLBACK_STACKS: Record<string, string> = {
  'sans-serif': FONT_STACKS.system!,
  serif: FONT_STACKS.serif!,
  monospace: FONT_STACKS.mono!,
  cursive: '"Snell Roundhand","Segoe Script","Brush Script MT",cursive',
  'system-ui': FONT_STACKS.system!,
}

/** The named stack an uploaded family falls back to (FontStacks::forFallback). */
export function fallbackStack(generic: string | null): string {
  return FALLBACK_STACKS[generic ?? 'sans-serif'] ?? FONT_STACKS.system!
}

/** The family name the admin registers the theme's own face under (loadFamilyFaces). */
export const THEME_FACE_FAMILY = 'thallo-theme-face'
