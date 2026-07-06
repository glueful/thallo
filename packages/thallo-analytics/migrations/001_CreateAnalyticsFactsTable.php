<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsFactsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_facts')) {
            return;
        }
        $schema->createTable('analytics_facts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->timestamp('occurred_at');
            $table->string('event', 64);
            $table->string('category', 32);
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->text('metadata')->nullable();
            $table->index('occurred_at');
            $table->index(['event', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_facts');
    }

    public function getDescription(): string
    {
        return 'Create analytics_facts (append-only raw analytics events).';
    }
}
