import { describe, expect, it } from 'vitest'
import { normalizeLinkUrl } from '@/components/linkUrl'

// What a person types into a link box, as the link they meant: `www.example.com` alone would be a
// page on this site. The stage bridge's normalizeLinkUrl follows the same rules.
describe('normalizeLinkUrl', () => {
  it.each([
    ['www.example.com', 'https://www.example.com'],
    ['scentnoirofficial.com/shop', 'https://scentnoirofficial.com/shop'],
    ['  example.com  ', 'https://example.com'],
    ['hello@example.com', 'mailto:hello@example.com'],
    ['+233 59 747 8403', 'tel:+233597478403'],
    ['0597478403', 'tel:0597478403'],
  ])('%s becomes %s', (typed, linked) => {
    expect(normalizeLinkUrl(typed)).toBe(linked)
  })

  it.each([
    'https://example.com',
    'http://example.com',
    'mailto:a@b.co',
    'tel:+233597478403',
    'sms:+233597478403',
    '/about',
    '#contact',
    '?q=1',
    'about',
    '',
  ])('%s is left as typed', (typed) => {
    expect(normalizeLinkUrl(typed)).toBe(typed)
  })
})
