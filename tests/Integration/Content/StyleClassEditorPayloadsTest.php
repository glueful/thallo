<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Http\Response;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\StyleClassController;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Container-layout spec §12.5: preservation is the editor's, persistence is the server's.
 *
 * `tests/fixtures/style-classes/editor-payloads.json` is read by BOTH suites. The admin's vitest
 * proves the class editor emits these shapes; this holds the real controller to them, so what is
 * proven emitted there is what is proven accepted — or refused — here. Three kinds of payload:
 *
 *  - what the editor AUTHORS (`valid`, `bare_reset`) must be accepted and read back unchanged;
 *  - what it PRESERVES (`invalid_value`, `unknown_path`) is emitted on purpose and refused on
 *    purpose, naming the field — the validator is not relaxed for it;
 *  - what it must NEVER author (`wrapped_non_responsive`) is refused, which is why it must not.
 *
 * Its own class, so nothing here can disturb another test's listing of style classes.
 */
final class StyleClassEditorPayloadsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function api(): StyleClassController
    {
        return $this->container()->get(StyleClassController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array<string,mixed> */
    private function json(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    /** @return array<string,mixed> */
    private function payload(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/fixtures/style-classes/editor-payloads.json';
        $all = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($all[$name] ?? null, "the fixture has no payload \"{$name}\"");
        return $all[$name];
    }

    /** @param array<string,mixed> $style */
    private function store(string $name, array $style): Response
    {
        $data = $this->container()->get(RequestDataHydrator::class)
            ->hydrate(StyleClassData::class, ['name' => $name, 'style' => $style]);
        return $this->api()->store($data, $this->req());
    }

    /** @param array<string,mixed> $style */
    private function patch(string $id, int $version, array $style): Response
    {
        $data = $this->container()->get(RequestDataHydrator::class)
            ->hydrate(UpdateStyleClassData::class, ['version' => $version, 'style' => $style]);
        return $this->api()->update($data, $this->req(), $id);
    }

    public function testWhatTheEditorAuthorsIsAcceptedOnCreateAndUpdateAndReadBackUnchanged(): void
    {
        foreach (['valid', 'bare_reset'] as $name) {
            $style = $this->payload($name);

            $created = $this->store("Authored {$name}", $style);
            self::assertSame(201, $created->getStatusCode(), "{$name}: " . $created->getContent());
            $class = $this->json($created)['data']['style_class'];
            // Equal, not identical: the stored document does not keep key order.
            self::assertEquals($style, $class['style'], "{$name}: created");

            $shown = $this->json($this->api()->show($this->req(), $class['id']))['data']['style_class'];
            self::assertEquals($style, $shown['style'], "{$name}: reloaded");

            // Saved again over itself — the editor's save after a no-op, or an edit and its inverse.
            $updated = $this->patch($class['id'], $class['version'], $style);
            self::assertSame(200, $updated->getStatusCode(), "{$name}: " . $updated->getContent());
            self::assertEquals($style, $this->json($updated)['data']['style_class']['style'], "{$name}: updated");
        }
    }

    public function testAnExplicitResetAndABareNonResponsiveValueKeepTheirShape(): void
    {
        // The two shapes §12.4 turns on, named rather than left inside a deep comparison: a reset is
        // stored as a reset — not dropped as if it were an absence — and a non-responsive value is
        // stored bare, under no breakpoint.
        $created = $this->store('Shapes', $this->payload('valid'));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $style = $this->json($created)['data']['style_class']['style'];

        self::assertSame(['type' => 'reset'], $style['layout']['columns']['md']);
        self::assertSame(['type' => 'choice', 'value' => 'hidden'], $style['layout']['overflow']);
        self::assertArrayNotHasKey('base', $style['layout']['overflow']);
        self::assertSame(['type' => 'token', 'value' => 'radius.md'], $style['radius']);
    }

    public function testWhatTheEditorPreservesIsRefusedNamingTheField(): void
    {
        $invalid = $this->store('Preserved invalid', $this->payload('invalid_value'));
        self::assertSame(422, $invalid->getStatusCode(), (string) $invalid->getContent());
        $details = $this->json($invalid)['error']['details'];
        // The admin's save-error test quotes this key and this message: held here so it cannot drift.
        self::assertSame('must be one of flex, grid', $details['style.layout.display.md'] ?? null);
        // The valid declaration beside it is not what was refused.
        self::assertArrayNotHasKey('style.spacing.padding.top.base', $details);
        self::assertArrayNotHasKey('style.spacing.padding.top', $details);

        $unknown = $this->store('Preserved unknown', $this->payload('unknown_path'));
        self::assertSame(422, $unknown->getStatusCode(), (string) $unknown->getContent());
        $details = $this->json($unknown)['error']['details'];
        self::assertSame('unknown style property', $details['style.layout.nonesuch'] ?? null);
    }

    public function testANonResponsivePropertyUnderABreakpointIsRefused(): void
    {
        $wrapped = $this->store('Wrapped', $this->payload('wrapped_non_responsive'));
        self::assertSame(422, $wrapped->getStatusCode(), (string) $wrapped->getContent());
        $details = $this->json($wrapped)['error']['details'];
        self::assertSame('is not responsive', $details['style.layout.overflow'] ?? null);
    }

    public function testARefusedUpdateLeavesTheStoredClassAsItWasAndTheRepairThenSaves(): void
    {
        // The admin's "rejected save, repair, save" against the real controller: the refusal writes
        // nothing and moves no version, and the same draft — repaired — saves over it.
        $created = $this->store('Repairable', $this->payload('valid'));
        $class = $this->json($created)['data']['style_class'];

        $draft = $this->payload('invalid_value');
        $refused = $this->patch($class['id'], $class['version'], $draft);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getContent());
        $after = $this->json($this->api()->show($this->req(), $class['id']))['data']['style_class'];
        self::assertSame($class['version'], $after['version']);
        self::assertEquals($this->payload('valid'), $after['style']);

        $draft['layout']['display']['md'] = ['type' => 'choice', 'value' => 'flex']; // Replace with Flex
        $saved = $this->patch($class['id'], $class['version'], $draft);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertEquals($draft, $this->json($saved)['data']['style_class']['style']);
    }
}
