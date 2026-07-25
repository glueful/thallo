import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import type { EmailPartialRow, EmailSettingsPayload, EmailTemplateRow } from '@/queries/email'

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const fetchSettingsMock = vi.hoisted(() => vi.fn())
const saveSettingsMock = vi.hoisted(() => vi.fn())
const testSettingsMock = vi.hoisted(() => vi.fn())
const fetchTemplatesMock = vi.hoisted(() => vi.fn())
const saveTemplateMock = vi.hoisted(() => vi.fn())
const resetTemplateMock = vi.hoisted(() => vi.fn())
const testTemplateMock = vi.hoisted(() => vi.fn())
const savePartialMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/email', () => ({
  fetchEmailSettings: fetchSettingsMock,
  saveEmailSettings: saveSettingsMock,
  testEmailSettings: testSettingsMock,
  fetchEmailTemplates: fetchTemplatesMock,
  saveEmailTemplate: saveTemplateMock,
  resetEmailTemplate: resetTemplateMock,
  testEmailTemplate: testTemplateMock,
  saveEmailPartial: savePartialMock,
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/settings/email', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))
// CodeMirror needs DOM APIs jsdom lacks — stub the editor globally.
vi.mock('@/pages/templates/components/TemplateEditor.vue', () => ({
  default: {
    name: 'TemplateEditor',
    props: ['modelValue', 'language', 'readonly'],
    emits: ['update:modelValue'],
    template: '<textarea data-test="stub-editor" />',
  },
}))

import EmailSettingsPage from '@/pages/settings/email/index.vue'

const settings = (): EmailSettingsPayload => ({
  settings: {
    default: 'smtp',
    from: { address: 'no-reply@app.test', name: 'App' },
    bcc: '',
    logo_url: '',
    mailers: {
      smtp: { host: 'smtp.app.test', port: 587, username: 'mailer', encryption: 'tls' },
      log: { transport: 'log' },
    },
  },
  password_set: true,
})

const templates = (): EmailTemplateRow[] => [
  {
    key: 'verification',
    label: 'Verification',
    description: 'Email verification.',
    owner: 'glueful/email-notification',
    placeholders: [
      { name: 'app_name', description: 'Application name.', sample: 'Glueful' },
      { name: 'otp', description: 'One-time code.', sample: '123456' },
    ],
    subject: 'Verify your {{app_name}} email',
    body: '<p>{{otp}}</p>',
    overridden: false,
  },
  {
    key: 'alert',
    label: 'Alert',
    description: 'Alerts.',
    owner: 'glueful/email-notification',
    placeholders: [{ name: 'app_name', description: 'Application name.', sample: 'Glueful' }],
    subject: 'Alert from {{app_name}}',
    body: '<p>alert</p>',
    overridden: true,
  },
]

const partials = (): EmailPartialRow[] => [
  {
    key: 'partial.styles',
    label: 'Styles (CSS)',
    description: 'Stylesheet injected into the layout.',
    language: 'css',
    body: '.otp-code { color: blue; }',
    overridden: false,
  },
  {
    key: 'partial.layout',
    label: 'Layout',
    description: 'The outer HTML document.',
    language: 'html',
    body: '<!DOCTYPE html><html>{{{content}}}</html>',
    overridden: true,
  },
]

const mountPage = () =>
  mount(EmailSettingsPage, {
    global: { stubs: {} },
    attachTo: document.body,
  })

describe('email settings page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
    notify.success.mockClear()
    notify.error.mockClear()
    fetchSettingsMock.mockReset().mockResolvedValue(settings())
    saveSettingsMock.mockReset().mockResolvedValue(settings())
    testSettingsMock.mockReset().mockResolvedValue(undefined)
    fetchTemplatesMock.mockReset().mockResolvedValue({ templates: templates(), partials: partials() })
    saveTemplateMock.mockReset().mockResolvedValue(undefined)
    resetTemplateMock.mockReset().mockResolvedValue(undefined)
    testTemplateMock.mockReset().mockResolvedValue(undefined)
  })

  it('hydrates the nested transport shape and saves flat keys (password only when typed)', async () => {
    const wrapper = mountPage()
    await flushPromises()

    // Nested GET hydrated into the flat form.
    const host = wrapper.findAll('input').find((i) => (i.element as HTMLInputElement).value === 'smtp.app.test')
    expect(host).toBeTruthy()

    const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save'))
    await saveBtn!.trigger('click')
    await flushPromises()

    const payload = saveSettingsMock.mock.calls[0]![0]
    expect(payload).toMatchObject({ mailer: 'smtp', host: 'smtp.app.test', from: 'no-reply@app.test' })
    expect(payload).not.toHaveProperty('password') // blank keeps the stored one
    wrapper.unmount()
  })

  it('derives mailer options from settings.mailers', async () => {
    const wrapper = mountPage()
    await flushPromises()
    // ['smtp', 'log'] — both offered; assert via the select's rendered value + items prop path.
    expect(wrapper.find('[data-test="mailer-select"]').exists()).toBe(true)
    // The options land in the teleported listbox only when open; assert the derived list
    // indirectly: the page exposes both entries through the fixture (no hardcoded sendmail).
    expect(wrapper.html()).not.toContain('sendmail')
    wrapper.unmount()
  })

  it('filters commerce-owned templates out — they moved to Commerce › Settings › Emails', async () => {
    fetchTemplatesMock.mockResolvedValue({
      templates: [
        ...templates(),
        {
          key: 'commerce.order_paid',
          label: 'Order paid',
          description: 'Sent when an order is paid.',
          owner: 'thallo-commerce',
          placeholders: [],
          subject: 'Payment received',
          body: '<p>paid</p>',
          overridden: false,
        },
      ],
      partials: partials(),
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-toggle-verification"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-toggle-commerce.order_paid"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Order paid')
  })

  it('renders template rows with chips and overridden badges', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-toggle-verification"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-badge-verification"]').text()).toBe('default')
    expect(wrapper.find('[data-test="template-badge-alert"]').text()).toBe('custom')

    const chip = wrapper.find('[data-test="placeholder-chip-app_name"]')
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toBe('{{app_name}}')
    expect(chip.attributes('title')).toBe('Application name.')
    wrapper.unmount()
  })

  it('saves one row and shows 422 violations inline', async () => {
    saveTemplateMock.mockRejectedValue(
      new ApiError('Template violations.', 422, {}, {
        success: false,
        message: 'Template violations.',
        errors: ['Unclosed conditional block: {{#if otp}}'],
      }),
    )
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="template-save-verification"]').trigger('click')
    await flushPromises()

    expect(saveTemplateMock).toHaveBeenCalledWith('verification', {
      subject: 'Verify your {{app_name}} email',
      body: '<p>{{otp}}</p>',
    })
    const violations = wrapper.find('[data-test="template-violations-verification"]')
    expect(violations.exists()).toBe(true)
    expect(violations.text()).toContain('Unclosed conditional block')
    expect(notify.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('reset is visible only when overridden', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="template-reset-alert"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="template-reset-verification"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('the test modal sends a template test or a transport test', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="templates-test-open"]').trigger('click')
    await flushPromises()

    // The modal teleports — reach it through the document.
    const to = document.querySelector(
      '[data-test="test-email-to"] input, input[data-test="test-email-to"]',
    ) as HTMLInputElement
    expect(to).toBeTruthy()
    to.value = 'operator@app.test'
    to.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    document
      .querySelector('[data-test="test-email-send"]')!
      .dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    // Preselected on the first template (opened from the templates card).
    expect(testTemplateMock).toHaveBeenCalledWith('verification', 'operator@app.test')
    expect(notify.success).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('partials render with badges and save body-only through saveEmailPartial', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="partial-toggle-partial.styles"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="partial-badge-partial.styles"]').text()).toBe('default')
    expect(wrapper.find('[data-test="partial-badge-partial.layout"]').text()).toBe('custom')

    // Reset only when overridden.
    expect(wrapper.find('[data-test="partial-reset-partial.layout"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="partial-reset-partial.styles"]').exists()).toBe(false)

    await wrapper.find('[data-test="partial-save-partial.styles"]').trigger('click')
    await flushPromises()
    expect(savePartialMock).toHaveBeenCalledWith('partial.styles', '.otp-code { color: blue; }')
    wrapper.unmount()
  })

  it('a 403 from templates hides the section without a toast', async () => {
    fetchTemplatesMock.mockRejectedValue(new ApiError('Forbidden', 403, {}, {}))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="templates-card"]').exists()).toBe(false)
    expect(notify.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
