<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateWorkflowReviewStatesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('workflow_review_states')) {
            return;
        }
        $schema->createTable('workflow_review_states', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            // draft | in_review | approved | changes_requested; an absent row ≡ draft.
            $table->string('state', 24)->default('draft');
            $table->string('submitted_by', 12)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reviewed_by', 12)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['entry_uuid', 'locale'], 'uniq_workflow_state_entry_locale');
            $table->index('state');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('workflow_review_states');
    }

    public function getDescription(): string
    {
        return 'Create workflow_review_states (per entry+locale review state).';
    }
}
