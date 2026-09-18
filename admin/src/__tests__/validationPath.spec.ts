import { describe, it, expect } from 'vitest'
import { blockAtValidationPath } from '@/editor/validationPath'

// The API names a refused field by its path through the document: field, index, then the
// block's data field, index, … ending in the field. The editor turns that back into the block
// so it can be selected and the field named.
const fields = {
  title: 'Home',
  body: [
    { id: 'hero000000001', type: 'hero', data: { title: 'Hi' }, settings: {} },
    {
      id: 'cont000000002',
      type: 'container',
      data: {
        content: [
          {
            id: 'colb000000003',
            type: 'container',
            data: {
              content: [
                { id: 'feat000000004', type: 'feature', data: {}, settings: {} },
                { id: 'feat000000005', type: 'feature', data: { title: 'B' }, settings: {} },
              ],
            },
            settings: {},
          },
        ],
      },
      settings: {},
    },
  ],
}

describe('blockAtValidationPath', () => {
  it('walks field, index and nested data lists down to the block and names the field', () => {
    expect(blockAtValidationPath(fields, 'body.1.content.0.content.0.title')).toEqual({
      id: 'feat000000004',
      type: 'feature',
      field: 'title',
    })
    expect(blockAtValidationPath(fields, 'body.0.title')).toEqual({
      id: 'hero000000001',
      type: 'hero',
      field: 'title',
    })
  })

  it('answers null for a top-level field, a missing index or a path that leaves the tree', () => {
    expect(blockAtValidationPath(fields, 'title')).toBeNull()
    expect(blockAtValidationPath(fields, 'body.9.title')).toBeNull()
    expect(blockAtValidationPath(fields, 'body.1.content.0.nowhere.0.title')).toBeNull()
    expect(blockAtValidationPath(fields, 'body.1.nope')).toEqual({
      id: 'cont000000002',
      type: 'container',
      field: 'nope',
    })
  })
})
