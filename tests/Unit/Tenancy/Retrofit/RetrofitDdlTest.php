<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Retrofit;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\Retrofit\PostgresRetrofitDdl;
use Thallo\Tenancy\Retrofit\RetrofitDdl;
use Thallo\Tenancy\Retrofit\RetrofitDdlFactory;
use Thallo\Tenancy\Retrofit\UnsupportedRetrofitDriverException;

final class RetrofitDdlTest extends TestCase
{
    private function pg(): RetrofitDdl
    {
        return (new RetrofitDdlFactory())->for('pgsql');
    }

    public function testFactoryReturnsPostgresForPgsql(): void
    {
        self::assertInstanceOf(PostgresRetrofitDdl::class, $this->pg());
    }

    public function testFactoryThrowsForMysql(): void
    {
        $this->expectException(UnsupportedRetrofitDriverException::class);
        (new RetrofitDdlFactory())->for('mysql');
    }

    public function testFactoryThrowsForSqlite(): void
    {
        $this->expectException(UnsupportedRetrofitDriverException::class);
        (new RetrofitDdlFactory())->for('sqlite');
    }

    public function testFactoryThrowsForOracle(): void
    {
        $this->expectException(UnsupportedRetrofitDriverException::class);
        (new RetrofitDdlFactory())->for('oracle');
    }

    public function testDriverName(): void
    {
        self::assertSame('pgsql', $this->pg()->driver());
    }

    public function testQuote(): void
    {
        self::assertSame('"users"', $this->pg()->quote('users'));
    }

    public function testQuoteEscapesEmbeddedQuoteChars(): void
    {
        self::assertSame('"a""b"', $this->pg()->quote('a"b'));
    }

    public function testAddNullableColumn(): void
    {
        self::assertSame(
            'ALTER TABLE "content_types" ADD COLUMN "tenant_uuid" CHAR(12)',
            $this->pg()->addNullableColumn('content_types', 'tenant_uuid', 'CHAR(12)'),
        );
    }

    public function testSetNotNull(): void
    {
        self::assertSame(
            'ALTER TABLE "content_types" ALTER COLUMN "tenant_uuid" SET NOT NULL',
            $this->pg()->setNotNull('content_types', 'tenant_uuid'),
        );
    }

    public function testDropUniqueCandidates(): void
    {
        self::assertSame(
            [
                'ALTER TABLE "content_types" DROP CONSTRAINT IF EXISTS "content_types_slug_unique"',
                'DROP INDEX IF EXISTS "content_types_slug_unique"',
            ],
            $this->pg()->dropUniqueCandidates('content_types', 'content_types_slug_unique'),
        );
    }

    public function testCreateUnique(): void
    {
        self::assertSame(
            'ALTER TABLE "content_types" ADD CONSTRAINT "ct_tenant_slug_unique" UNIQUE ("tenant_uuid", "slug")',
            $this->pg()->createUnique('content_types', 'ct_tenant_slug_unique', ['tenant_uuid', 'slug']),
        );
    }

    public function testCreateIndex(): void
    {
        self::assertSame(
            'CREATE INDEX "ct_tenant_idx" ON "content_types" ("tenant_uuid")',
            $this->pg()->createIndex('content_types', 'ct_tenant_idx', ['tenant_uuid']),
        );
    }

    public function testRenameTable(): void
    {
        self::assertSame(
            'ALTER TABLE "regions" RENAME TO "regions_new"',
            $this->pg()->renameTable('regions', 'regions_new'),
        );
    }

    public function testAutoIncrementPk(): void
    {
        self::assertSame('"id" BIGSERIAL PRIMARY KEY', $this->pg()->autoIncrementPk('id'));
    }
}
