<?php

declare(strict_types=1);

namespace Acme\Bookings;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/** The one block Bookings adds, shown only while its capability is on. */
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
                schema: [
                    ['name' => 'heading', 'type' => 'string'],
                ],
                requiresCapability: 'acme.bookings',
            ),
        ];
    }
}
