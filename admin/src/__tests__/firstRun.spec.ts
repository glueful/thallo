import { describe, it, expect } from 'vitest'
import { pickFirstRunType, singularize } from '@/utils/firstRun'

// The dashboard's first-run card told a fresh install to "Create your first categorie": the
// picker looked for slug `page` (the seed is `pages`) and fell through to the first type
// alphabetically, and the singular was made by chopping a trailing "s".

describe('pickFirstRunType', () => {
  const types = [
    { slug: 'categories', name: 'Categories' },
    { slug: 'pages', name: 'Pages' },
    { slug: 'posts', name: 'Posts' },
  ]

  it('prefers the seeded pages type', () => {
    expect(pickFirstRunType(types)?.slug).toBe('pages')
  })

  it('falls back to posts, then to any non-taxonomy type', () => {
    expect(pickFirstRunType(types.filter((t) => t.slug !== 'pages'))?.slug).toBe('posts')
    expect(
      pickFirstRunType([
        { slug: 'categories', name: 'Categories' },
        { slug: 'articles', name: 'Articles' },
      ])?.slug,
    ).toBe('articles')
  })

  it('only offers a taxonomy when nothing else exists', () => {
    expect(pickFirstRunType([{ slug: 'categories', name: 'Categories' }])?.slug).toBe('categories')
    expect(pickFirstRunType([])).toBeUndefined()
  })
})

describe('singularize', () => {
  it.each([
    ['Pages', 'Page'],
    ['Posts', 'Post'],
    ['Categories', 'Category'],
    ['Stories', 'Story'],
    ['Series', 'Series'],
    ['News', 'News'],
    ['Article', 'Article'],
  ])('%s → %s', (plural, singular) => {
    expect(singularize(plural)).toBe(singular)
  })
})
