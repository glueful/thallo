<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateWorkflowTransitionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('workflow_transitions')) {
            return;
        }
        $schema->createTable('workflow_transitions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            // submit | approve | request_changes | withdraw | edit_invalidated
            // | published | published_with_bypass
            $table->string('action', 32);
            $table->string('actor_uuid', 12)->nullable();
            $table->text('note')->nullable();
            // Forward seam: source/channel enrichment if the publish event ever grows one.
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['entry_uuid', 'locale'], 'idx_workflow_transitions_entry_locale');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('workflow_transitions');
    }

    public function getDescription(): string
    {
        return 'Create workflow_transitions (append-only review history).';
    }
}
