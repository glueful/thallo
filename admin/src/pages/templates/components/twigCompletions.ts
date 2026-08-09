import type { Completion, CompletionContext, CompletionResult } from '@codemirror/autocomplete'
import { snippetCompletion } from '@codemirror/autocomplete'

// The sandbox vocabulary — a MIRROR of the render pack's TemplatePolicy
// allowlists (packages/thallo-render/src/Templates/TemplatePolicy.php).
// Completions therefore never suggest something the save-time linter would
// reject. Keep in sync when the policy gains entries (CACHE_VERSION bumps
// are the tell).

const FUNCTIONS = [
  'menu',
  'path',
  'asset',
  'facets',
  'blocks',
  'media',
  'site_logo',
  'video_embed',
  'icon',
  'region_blocks',
  'region_settings',
  'site_favicon',
  'custom_css',
  'form_render',
  'runtime_script',
  'seo_head',
  'font_faces_style',
  'shop_wishlist_scope',
  'shop_wishlist_url',
  'shop_styles_url',
  'shop_product_url',
  'shop_category_url',
  'shop_index_url',
  'json_script',
  'block_script',
  'entries',
  'is_preview',
  'media_image',
  'claim_priority_image',
  'color_mode_enabled',
  'color_mode_script',
  'theme_colors_style',
  'theme_style_scope',
  'include',
  'parent',
  'block',
  'cycle',
  'date',
  'min',
  'max',
  'plan_checkout_url',
]

const FILTERS = [
  'abs',
  'batch',
  'br_tokens',
  'capitalize',
  'column',
  'date',
  'date_modify',
  'default',
  'editable_text',
  'escape',
  'e',
  'first',
  'format',
  'hex_color',
  'join',
  'json_encode',
  'keys',
  'last',
  'length',
  'lower',
  'merge',
  'nl2br',
  'number_format',
  'numeric_clamp',
  'replace',
  'reverse',
  'round',
  'safe_html',
  'safe_url',
  'slice',
  'sort',
  'split',
  'striptags',
  'style_hook',
  'title',
  'trim',
  'upper',
  'url_encode',
]

const TESTS = [
  'defined',
  'empty',
  'even',
  'iterable',
  'null',
  'odd',
  'true',
  'same as',
  'divisible by',
  'sequence',
  'mapping',
]

const SNIPPETS: Completion[] = [
  snippetCompletion('{% if ${condition} %}\n\t${}\n{% endif %}', {
    label: '{% if %}',
    detail: 'condition block',
    type: 'keyword',
  }),
  snippetCompletion('{% for ${item} in ${items} %}\n\t${}\n{% endfor %}', {
    label: '{% for %}',
    detail: 'loop block',
    type: 'keyword',
  }),
  snippetCompletion('{% set ${name} = ${value} %}', {
    label: '{% set %}',
    detail: 'assignment',
    type: 'keyword',
  }),
  snippetCompletion("{% block ${name} %}\n\t${}\n{% endblock %}", {
    label: '{% block %}',
    detail: 'template block',
    type: 'keyword',
  }),
  snippetCompletion("{% extends '${layout.twig}' %}", {
    label: '{% extends %}',
    detail: 'layout inheritance',
    type: 'keyword',
  }),
  snippetCompletion("{% include '${partial.twig}' %}", {
    label: '{% include %}',
    detail: 'partial include',
    type: 'keyword',
  }),
]

const OPTIONS: Completion[] = [
  ...FUNCTIONS.map((label): Completion => ({ label, type: 'function', detail: 'function' })),
  ...FILTERS.map((label): Completion => ({ label, type: 'method', detail: 'filter' })),
  ...TESTS.map((label): Completion => ({ label, type: 'constant', detail: 'test' })),
  ...SNIPPETS,
]

/** Word-triggered completion over the sandbox-allowlisted Twig vocabulary. */
export function twigCompletions(context: CompletionContext): CompletionResult | null {
  const word = context.matchBefore(/[\w{%]+/)
  if (!word || (word.from === word.to && !context.explicit)) return null
  return { from: word.from, options: OPTIONS, validFor: /^[\w{%]*$/ }
}
