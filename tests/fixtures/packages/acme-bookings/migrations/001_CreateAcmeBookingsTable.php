<?php

declare(strict_types=1);

namespace Acme\Bookings\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAcmeBookingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('acme_bookings')) {
            $schema->createTable('acme_bookings', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12)->default('');
                $table->string('name', 255);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('acme_bookings');
    }

    public function getDescription(): string
    {
        return 'Create the acme_bookings table';
    }
}
