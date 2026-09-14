<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Fragments\TemplateDependencies;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/** The dependency scan over an in-memory theme: includes followed, guards read, unknowns conservative. */
final class TemplateDependenciesTest extends TestCase
{
    /** @param array<string,string> $templates */
    private static function env(array $templates): Environment
    {
        $env = new Environment(new ArrayLoader($templates));
        foreach (['claim_priority_image', 'media_image', 'entries', 'block_script', 'facets'] as $name) {
            $env->addFunction(new TwigFunction($name, static fn (): string => ''));
        }
        return $env;
    }

    public function testConstantIncludesAreFollowedAndGuardsAreReadThroughThem(): void
    {
        $deps = new TemplateDependencies(self::env([
            'blocks/card.twig' => '<div>{% include "partials/media.twig" %}{{ include("partials/foot.twig") }}</div>',
            'partials/media.twig' => '{% set img = data.cover ? media_image(data.cover, [1]) : null %}'
                . '{% if img %}{% set p = claim_priority_image() %}{% endif %}',
            'partials/foot.twig' => '{{ block_script("card") }}',
            'blocks/plain.twig' => '<p>{{ data.text }}</p>',
            'blocks/listing.twig' => '{% for e in entries("post") %}{{ e.title }}{% endfor %}',
            'blocks/nav.twig' => '<a class="{% if current_path == "/" %}on{% endif %}">x</a>',
            'blocks/nth.twig' => '<p>{{ index }}</p>',
            'blocks/dynamic.twig' => '{% include data.tpl %}',
            'blocks/bare.twig' => '{% set p = claim_priority_image() %}',
            'blocks/broken.twig' => '{% if %}',
        ]));
        $followed = ['blocks/card.twig', 'partials/media.twig', 'partials/foot.twig'];
        self::assertSame($followed, $deps->templatesFor('card'));
        self::assertTrue($deps->pageOrderDependent('card'));
        self::assertSame(['cover'], $deps->claimFields('card'));
        self::assertTrue($deps->needsAssets('card'));
        self::assertFalse($deps->pageDependent('card'));

        self::assertFalse($deps->pageOrderDependent('plain'));
        self::assertSame([], $deps->claimFields('plain'));
        self::assertTrue($deps->pageDependent('listing'));
        self::assertTrue($deps->pageDependent('nav'), 'reads current_path');
        self::assertTrue($deps->pageOrderDependent('nth'), 'reads its list index');
        self::assertSame([], $deps->claimFields('nth'), 'order-dependent, but never claims');

        // Unknowable: a computed include counts as every dependency; a bare claim is always reachable.
        self::assertTrue($deps->pageOrderDependent('dynamic'));
        self::assertTrue($deps->pageDependent('dynamic'));
        self::assertNull($deps->claimFields('dynamic'));
        self::assertNull($deps->claimFields('bare'));

        // Missing or unparsable templates: nothing to depend on, nothing to verify.
        self::assertSame([], $deps->templatesFor('missing'));
        self::assertNull($deps->templateHash('blocks/missing.twig'));
        self::assertSame([], $deps->templatesFor('broken'));
        self::assertSame(hash('sha256', '<p>{{ data.text }}</p>'), $deps->templateHash('blocks/plain.twig'));
    }
}
