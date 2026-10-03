---
title: "Package a feature as an extension"
slug: make-an-extension
section: guides
order: 22
summary: "Ship a Composer package that appears in Extensions, turns on with one action, and adds its own blocks and permissions."
---

At the end of this page you have a Composer package that adds a feature to Thallo: it appears in
**Extensions › Capabilities** as soon as it is installed, turns on with the same one action as
Commerce, adds its own block type in every workspace, and grants its own permission to the
superuser and administrator roles. Turning it off hides it and keeps its data.

You need a PHP package you can `composer require` into a Thallo project, and a working
knowledge of Glueful extensions: a provider class and migrations. The example is a booking
feature, `acme/bookings`.

## How Thallo sees your package

Your package is a **Glueful extension**: Composer type `glueful-extension`, with a provider class
and migrations in `extra.glueful`. On top of that it declares a **capability**, the switch an
operator sees, in `extra.thallo.capabilities`. Thallo reads the declaration from Composer's
metadata, so the capability is listed while your extension is still disabled.

Each capability is managed in one of three ways, its **mode**:

| Mode | What the operator gets |
|---|---|
| `simple` | A plain switch on the capability's card. The default when you leave `mode` out. |
| `activation` | One action that enables and migrates your extension, checks it in a fresh request, adds its blocks, grants its permissions, then switches the capability on. Your extension is managed by the capability: the generic **Enable** and **Disable** refuse it. |
| `external_flow` | A link to a page of yours that runs its own flow, named by `destination`. |

Use `activation` when your feature has an extension of its own to enable. The rest of this page
builds one.

## Declare the capability

`composer.json` in your package:

```json
{
    "name": "acme/bookings",
    "type": "glueful-extension",
    "autoload": {
        "psr-4": { "Acme\\Bookings\\": "src/" },
        "classmap": ["migrations/"]
    },
    "extra": {
        "glueful": {
            "provider": "Acme\\Bookings\\BookingsServiceProvider",
            "requires": { "glueful": ">=1.79.0", "extensions": [] },
            "migrations": [
                { "id": "default", "path": "migrations", "priority": "dependent", "mode": "on_enable" }
            ]
        },
        "thallo": {
            "capabilities": [
                {
                    "id": "acme.bookings",
                    "label": "Bookings",
                    "description": "Appointments your visitors book from your pages.",
                    "mode": "activation",
                    "copy": {
                        "turn_on": "This prepares Bookings and adds its block. Your existing content is kept.",
                        "turn_off": "The Bookings block is hidden. Your bookings are kept, and you can turn it on again.",
                        "links": [{ "label": "Block types", "to": "/settings/block-types" }]
                    }
                }
            ]
        }
    }
}
```

The keys of a capability:

| Key | Required | What it does |
|---|---|---|
| `id` | yes | The capability's id. Use your vendor as the prefix; `thallo.` belongs to Thallo. |
| `label`, `description` | no | What the card shows. |
| `mode` | no | `simple`, `activation` or `external_flow`. |
| `requires` | no | Ids of capabilities this one needs on. |
| `copy` | no | `turn_on` and `turn_off`, the text of the confirmation dialogs, and `links`, each a `label` and an admin path `to`, shown on the card once it is on. Generic wording fills in what you leave out. |
| `destination` | for `external_flow` | `path` and `label` of the page that runs your flow. |

The capability belongs to the package that declares it: an `activation` capability's engine is
always your own package. Migrations with `"mode": "on_enable"` run when the activation enables
your extension, not when Composer installs it.

A provider can declare capabilities in code instead, by implementing
`Thallo\Contracts\Capability\DeclaresCapabilities`, whose `capabilities()` returns a list of
`Thallo\Contracts\Capability\Capability`. Thallo's own packs do this. Composer metadata is the
better choice for an extension, because it is read while the extension is disabled.

## Add a block type

Contribute block types through `Thallo\Contracts\Starter\StarterBlockTypeContributor`, and set
`requiresCapability` to your capability's id. The activation seeds them, in every workspace when
workspaces are on, and Thallo hides them while the capability is off, without deleting them:

```php
<?php

namespace Acme\Bookings;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

final class BookingBlockTypes implements StarterBlockTypeContributor
{
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'acme-bookings:booking-form',
                slug: 'booking_form',
                label: 'Booking form',
                icon: 'i-lucide-calendar-check',
                category: 'forms',
                description: 'Lets visitors book an appointment.',
                schema: [['name' => 'heading', 'type' => 'string']],
                requiresCapability: 'acme.bookings',
            ),
        ];
    }
}
```

## Register it, and declare a permission

Your provider registers the contributor with `Thallo\Contracts\Starter\StarterBlockTypeRegistry`
when it boots (once: boot can run more than once in a process), and declares its permissions in
`permissions()`. The activation grants them to the `superuser` and `administrator` roles; a
permission an operator revokes later stays revoked. Until your capability's activation runs,
provision syncs your permissions but grants them to no one, even when your extension is already
enabled.

```php
<?php

namespace Acme\Bookings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\Permission;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;

final class BookingsServiceProvider extends ServiceProvider
{
    public function boot(ApplicationContext $context): void
    {
        $container = $context->getContainer();
        if (!$container->has(StarterBlockTypeRegistry::class)) {
            return;
        }
        $registry = $container->get(StarterBlockTypeRegistry::class);
        foreach ($registry->all() as $contributor) {
            if ($contributor instanceof BookingBlockTypes) {
                return;                                    // registered already
            }
        }
        $registry->register(new BookingBlockTypes());
    }

    public function permissions(): array
    {
        return [
            Permission::define('bookings.manage')
                ->label('Manage bookings')
                ->category('bookings')
                ->resource('bookings')
                ->managedBy('acme/bookings'),
        ];
    }
}
```

Depend on `glueful/thallo-contracts` only, never on Thallo's other packages: the contracts are the
part of Thallo an extension can rely on.

## Check that it is on

Your extension stays enabled while its capability is off, so check the capability before doing
anything a visitor sees. Resolve `Thallo\Contracts\Capability\CapabilityRegistry` from the
container and ask `isEnabled('acme.bookings')`. An `activation` capability reads off until its
activation finishes, even when your extension is already enabled.

## Install it and turn it on

```bash
$ composer require acme/bookings
```

Open **Extensions › Capabilities**. **Bookings** is listed with its switch, and
**Extensions › Installed** shows `acme/bookings` as managed by it. Switch it on, confirm, and the
card ends on what was added, with your links beside it. From a shell:

```bash
$ php glueful thallo:capabilities:enable acme.bookings
```

Turning Bookings off hides the block and keeps your extension enabled, its tables, and its data.

## When Thallo refuses a declaration

Thallo blocks a capability, and the packages it claims, when its declaration conflicts or can't be
read:

- an entry in `extra.thallo.capabilities` is invalid (a missing id, an unknown mode);
- the same id is declared differently by two sources, two packages or a package and Thallo;
- two capabilities that are not `simple` claim the same package, including one that is blocked for
  another reason;
- an `activation` capability's package is one Thallo requires;
- an `activation` capability's package isn't installed.

A blocked capability has no switch, every path refuses it, and `php glueful thallo:doctor` fails its
`capability-declarations` check with the reason. See
[troubleshooting](../operations/05-troubleshooting.md#a-capability-is-misconfigured).

## Check it worked

```bash
$ php glueful thallo:capabilities:status
```

`acme.bookings` is listed as on, and **Settings › Block Types** shows **Booking form**. The card in
**Extensions › Capabilities** says how many blocks were added and permissions granted.
