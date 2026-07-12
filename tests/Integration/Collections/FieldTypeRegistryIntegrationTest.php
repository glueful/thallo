<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Thallo\Contracts\Schema\FieldTypeRegistry;

/**
 * Verifies that ThalloServiceProvider binds FieldTypeRegistry to the container and
 * that EditorialFieldTypes seeds the registry with all content.* type definitions.
 */
final class FieldTypeRegistryIntegrationTest extends CollectionsTestCase
{
    public function testRegistryResolvesFromContainer(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        self::assertInstanceOf(FieldTypeRegistry::class, $registry);
    }

    public function testContentTextIsRegistered(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        self::assertTrue($registry->has('content.text'));
        self::assertSame('content.text', $registry->get('content.text')->key());
    }

    public function testAllRegisteredKeysHaveKnownNamespacePrefix(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        // The shared registry may hold multiple namespaces (e.g. collections.*).
        // Assert that every key which belongs to the content domain uses the right prefix,
        // and that no content.* key leaks into another namespace.
        foreach (array_keys($registry->all()) as $key) {
            $prefix = explode('.', $key, 2)[0];
            self::assertMatchesRegularExpression(
                '/^(content|collections)\\./',
                $key,
                "Key '{$key}' uses an unrecognised namespace prefix '{$prefix}'.",
            );
        }
    }

    public function testAllCoreContentTypesAreRegistered(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        $expectedTypes = [
            'content.string',
            'content.text',
            'content.number',
            'content.boolean',
            'content.datetime',
            'content.enum',
            'content.reference',
            'content.asset',
            'content.json',
        ];

        foreach ($expectedTypes as $type) {
            self::assertTrue($registry->has($type), "Expected '{$type}' to be registered");
        }
    }
}
