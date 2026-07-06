<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsDailyTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_daily')) {
            return;
        }
        $schema->createTable('analytics_daily', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->date('day');
            $table->string('event', 64);
            // NOT NULL; the per-event daily total uses the sentinel '__total__'.
            $table->string('subject', 191)->default('__total__');
            $table->bigInteger('count')->default(0);
            $table->unique(['day', 'event', 'subject']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_daily');
    }

    public function getDescription(): string
    {
        return 'Create analytics_daily (per-day event-count rollups).';
    }
}
