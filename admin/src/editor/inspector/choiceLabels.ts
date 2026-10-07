// What a choice reads as, where the stored value is not the word for it. A property absent here
// shows its values as they are stored.
const percent = (values: string[]): Record<string, string> =>
  Object.fromEntries(values.map((v) => [v, `${v}%`]))

/** `fade-up` reads "Fade up": the stored value, spaced and capitalised. */
const words = (values: string[]): Record<string, string> =>
  Object.fromEntries(
    values.map((v) => [v, (v.charAt(0).toUpperCase() + v.slice(1)).replace(/-/g, ' ')]),
  )

export const CHOICE_LABELS: Record<string, Record<string, string>> = {
  'typography.letter_spacing': words(['tight', 'normal', 'wide', 'wider']),
  'typography.transform': words(['none', 'uppercase', 'lowercase', 'capitalize']),
  'typography.decoration': { none: 'None', underline: 'Underline', 'line-through': 'Line-through' },
  'logos.height': { sm: 'Small', md: 'Medium', lg: 'Large', xl: 'Extra large' },
  'logos.max_width': { sm: 'Narrow', md: 'Medium', lg: 'Wide', xl: 'Extra wide' },
  'typography.style': words(['normal', 'italic']),
  'footer.divider_width': words(['none', 'thin', 'medium', 'thick']),
  'footer.divider_style': words(['solid', 'dashed', 'dotted']),
  'colors.surface_opacity': percent(['100', '90', '80', '70', '60', '50']),
  opacity: percent(['100', '90', '80', '70', '60', '50']),
  'hover.opacity': percent(['100', '90', '80', '70', '60', '50']),
  'motion.entrance': words([
    'none',
    'fade',
    'fade-up',
    'fade-down',
    'slide-left',
    'slide-right',
    'zoom-in',
  ]),
  'motion.duration': words(['fast', 'normal', 'slow']),
  'motion.delay': words(['none', 'short', 'medium', 'long']),
  // "always" is the stored word; what it MEANS is every time the block scrolls into view.
  'motion.repeat': { once: 'Once', always: 'Every time' },
  'motion.stagger': words(['none', 'short', 'medium', 'long']),
  'motion.ken_burns': words(['none', 'zoom-in', 'zoom-out', 'pan-left', 'pan-right']),
}
