<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateLinter;

final class TemplateLinterTest extends AppTestCase
{
    private function linter(): TemplateLinter
    {
        return $this->container()->get(TemplateLinter::class);
    }

    /** The representative valid template: every allowlisted construct family at once. */
    public function testRepresentativeValidTemplateLintsClean(): void
    {
        $source = <<<'TWIG'
        {% extends "layout.twig" %}
        {% block content %}
          {% set items = entry.fields.tags|default([]) %}
          {% if items is not empty and items|length > 1 %}
            <ul>
            {% for item in items|sort %}
              <li class="{{ cycle(['odd','even'], loop.index0) }}">
                {{ item.name|upper }} — {{ item.count|number_format }}
                <a href="{{ path(item.uuid) ?? '#' }}">{{ item.title|default('Untitled') }}</a>
              </li>
            {% endfor %}
            </ul>
          {% endif %}
          {% include "partials/card.twig" %}
          {{ include("partials/footer.twig") }}
          <img src="{{ asset('img/logo.svg') }}" alt="{{ menu('main')|length }}">
          {% verbatim %}{{ not twig }}{% endverbatim %}
          {{ min(1, 2) + max(3, 4) }} {{ range(1, 3)|join(',') }}
          {{ "now"|date("Y") }} {{ entry.title ?? 'x' }}
          {{ loop is defined ? 'y' : 'n' }}
        {% endblock %}
        TWIG;
        self::assertSame([], $this->linter()->lint($source));
    }

    public function testDeniedConstructsEachViolateWithLineNumbers(): void
    {
        // [source, expected message fragment]
        $cases = [
            ["{% macro x() %}{% endmacro %}", 'macro'],
            ["{{ entry.title|raw }}", 'raw'],
            ["{{ constant('PHP_VERSION') }}", 'constant'],
            ["{{ attribute(entry, 'title') }}", 'attribute'],
            ["{{ source('layout.twig') }}", 'source'],
            ["{{ entry.getTitle() }}", 'Method calls'],
            ["{% apply upper %}x{% endapply %}", 'apply'],
            ["{{ [1,2]|map(v => v) }}", 'not allowed'],
            ["{% include var_name %}", 'constant string'],
            ["{% extends var_name %}", 'constant string'],
            // Default-deny proof: SpreadUnary lives in the "safe-looking"
            // Expression\Unary\ namespace but is NOT in the exact-class allowlist —
            // an unreviewed node is denied regardless of its namespace.
            ["{{ [0, ...[1, 2]]|join(',') }}", 'not allowed'],
        ];
        foreach ($cases as [$source, $fragment]) {
            $violations = $this->linter()->lint($source);
            self::assertNotSame([], $violations, "expected violation for: {$source}");
            self::assertSame(1, $violations[0]['line'], "line for: {$source}");
            self::assertStringContainsStringIgnoringCase(
                $fragment,
                $violations[0]['message'],
                "message for: {$source}",
            );
        }
    }

    public function testSyntaxErrorReportsItsLine(): void
    {
        $violations = $this->linter()->lint("ok\n{% if x %}\nno endif");
        self::assertCount(1, $violations);
        self::assertGreaterThanOrEqual(2, $violations[0]['line']);
    }

    public function testAllViolationsReportedAtOnce(): void
    {
        $violations = $this->linter()->lint("{{ a|raw }}\n{{ constant('X') }}");
        self::assertCount(2, $violations);
        self::assertSame(1, $violations[0]['line']);
        self::assertSame(2, $violations[1]['line']);
    }
}
