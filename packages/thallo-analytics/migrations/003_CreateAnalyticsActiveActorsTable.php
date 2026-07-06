<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsActiveActorsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_active_actors')) {
            return;
        }
        $schema->createTable('analytics_active_actors', function ($table) {
            $table->date('day');
            $table->string('metric', 32)->default('active_users');
            $table->string('actor_type', 16);
            $table->string('actor_id_hash', 64);
            $table->unique(['day', 'metric', 'actor_type', 'actor_id_hash']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_active_actors');
    }

    public function getDescription(): string
    {
        return 'Create analytics_active_actors (distinct-actor daily presence, privacy-minimized).';
    }
}
