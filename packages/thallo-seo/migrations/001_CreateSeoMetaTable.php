<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSeoMetaTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('seo_meta')) {
            return;
        }
        $schema->createTable('seo_meta', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 191);
            $table->string('locale', 12);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 1024)->nullable();
            $table->string('twitter_card', 50)->nullable();
            $table->string('robots', 50)->default('index');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['entry_uuid', 'locale']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('seo_meta');
    }

    public function getDescription(): string
    {
        return 'Create seo_meta (per-entry+locale SEO overrides).';
    }
}
