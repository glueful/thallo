// What the Features page says about each feature with an activation flow. The summaries after a
// turn-on are built from the activation's result (activationSummary), never written here.

export interface FeatureCopy {
  /** The turn-on confirmation, after "Turn on <label>?". */
  turnOn: string
  /** The turn-off confirmation, after "Turn off <label>?". */
  turnOff: string
  /** Where to go once it is on. */
  links: { label: string; to: string }[]
}

const COPY: Record<string, FeatureCopy> = {
  'thallo.commerce': {
    turnOn:
      'This prepares your store and adds products, orders, shop blocks and templates. Your existing content is kept.',
    turnOff:
      "Commerce's pages, blocks and menu are hidden. Products, orders and your content are kept, and you can turn it on again.",
    links: [
      { label: 'Products', to: '/commerce/products' },
      { label: 'Block types', to: '/settings/block-types' },
    ],
  },
  'thallo.subscriptions': {
    turnOn:
      'This prepares billing and adds plans, subscriptions and their blocks. Your existing content is kept.',
    turnOff:
      "Subscriptions' pages, blocks and menu are hidden. Plans, subscribers and your content are kept, and you can turn it on again.",
    links: [
      { label: 'Plans', to: '/subscriptions/plans' },
      { label: 'Block types', to: '/settings/block-types' },
    ],
  },
}

export function featureCopy(id: string, label: string): FeatureCopy {
  return (
    COPY[id] ?? {
      turnOn: `This prepares ${label} and adds what it needs. Your existing content is kept.`,
      turnOff: `${label}'s pages, blocks and menu are hidden. Your content is kept, and you can turn it on again.`,
      links: [],
    }
  )
}
