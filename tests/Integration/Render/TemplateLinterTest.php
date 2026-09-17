<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Templates\TemplateLinter;

final class TemplateLinterTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

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
          {{ min(1, 2) + max(3, 4) }} {{ [1, 2, 3]|join(',') }}
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
            // NOTE: a bare "{% macro %}" is no longer in this table — macro/MacroNode
            // joined the allowlist (gate-audit amendment, task 7); it lints clean now.
            // What stays denied is a non-self import target — see
            // testNonSelfImportAndOtherGateAuditDenialsArePinned() below.
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

    public function testNewlyAllowlistedFunctionsLintClean(): void
    {
        $source = <<<'TWIG'
        {{ json_script({'a': 1}) }}
        <a href="{{ shop_product_url('slug') }}">{{ shop_category_url('c') }}{{ shop_index_url() }}</a>
        {% set posts = entries('posts', {limit: 3}) %}
        {% if is_preview() %}preview{% endif %}
        {% set img = media_image('u-1', [320, 640]) %}
        {{ claim_priority_image() ? 'first' : 'later' }}
        {% if color_mode_enabled() %}{{ color_mode_script() }}{% endif %}
        {{ theme_colors_style() }}
        {% set scope = theme_style_scope('blue', 'slate') %}{{ scope.class }}{{ scope.style }}
        TWIG;
        self::assertSame([], $this->linter()->lint($source));
    }

    /**
     * Gate-audit amendment (task 7): the vocabulary the dry run found missing from
     * policy v17 — editable_text/style_hook filters, hex_color/numeric_clamp bounded
     * helpers, {% for %}…{% else %}, and the self-import + macro-call shape every
     * shipped macro user (navigation.twig, blog_posts.twig, pricing_table.twig,
     * container.twig) already relies on.
     */
    public function testGateAuditVocabularyLintsClean(): void
    {
        $source = <<<'TWIG'
        {{ entry.title|editable_text('title') }}
        {{ entry.opacity|numeric_clamp(0, 200) }}
        {% for item in entry.items|default([]) %}
          {{ item }}
        {% else %}
          empty
        {% endfor %}
        {% import _self as m %}
        {% macro m1(x) %}{{ x }}{% endmacro %}
        {{ m.m1('hi') }}
        TWIG;
        self::assertSame([], $this->linter()->lint($source));
    }

    /**
     * Denial pins (gate-audit amendment, task 7): |matches/MatchesBinary stays denied
     * (ReDoS posture — the equivalent checks now live in the hex_color/numeric_clamp PHP
     * helpers, never in template source); import is sanctioned ONLY in the self-import
     * shape, so any other target is still denied; dynamic include targets stay denied
     * (pre-existing rule, re-pinned here alongside the new import rule); |raw stays
     * denied (already pinned in testDeniedConstructsEachViolateWithLineNumbers — kept
     * here too as an explicit "assertNotSame" pin per the task 7 requirement).
     */
    public function testNonSelfImportAndOtherGateAuditDenialsArePinned(): void
    {
        self::assertNotSame(
            [],
            $this->linter()->lint("{{ entry.color matches '/^#[0-9A-Fa-f]{3,6}$/' }}"),
            'matches expression must stay denied',
        );
        self::assertNotSame(
            [],
            $this->linter()->lint("{% import 'layout.twig' as x %}"),
            'non-self import target must be denied',
        );
        self::assertNotSame(
            [],
            $this->linter()->lint("{% set name = 'card' %}{% include 'a/' ~ name ~ '.twig' %}"),
            'dynamic include target must be denied',
        );
        self::assertNotSame([], $this->linter()->lint("{{ entry.title|raw }}"), '|raw must stay denied');
    }

    public function testNonSelfImportViolationMentionsSelf(): void
    {
        $violations = $this->linter()->lint("{% import 'layout.twig' as x %}");
        self::assertNotSame([], $violations);
        self::assertStringContainsString('_self', $violations[0]['message']);
        self::assertSame(1, $violations[0]['line']);
    }

    public function testRawConstantAndRangeStayDenied(): void
    {
        self::assertNotSame([], $this->linter()->lint("{{ {'a':1}|json_encode|raw }}"));
        self::assertNotSame([], $this->linter()->lint("{{ constant('JSON_HEX_TAG') }}"));
        self::assertNotSame([], $this->linter()->lint("{{ range(1, 1000000000)|length }}"));
        self::assertNotSame([], $this->linter()->lint("{{ (1..1000000000)|length }}"));
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

    public function testBlockScriptIsAllowlisted(): void
    {
        self::assertSame([], $this->linter()->lint("{{ block_script('gallery') }}"));
    }

    /** Visual builder spec §2.5: the target rules apply to a block template whose type declares targets. */
    public function testStyleTargetRulesApplyToDeclaredBlockTemplates(): void
    {
        $this->syncBlockStyleDeclarations();
        $linter = $this->linter();
        $good = '<div class="x{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}>'
            . '<a class="y{{ style_classes(\'control\') }}"{{ style_attrs(\'control\') }}></a></div>';
        self::assertSame([], $linter->lint($good, 'blocks/button.twig'));

        $missing = $linter->lint('<div class="x{{ style_classes(\'root\') }}"></div>', 'blocks/button.twig');
        self::assertCount(1, $missing);
        self::assertStringContainsString('Declared style target "control" is never styled', $missing[0]['message']);

        $undeclared = $linter->lint($good . '{{ style_classes(\'nope\') }}', 'blocks/button.twig');
        self::assertCount(1, $undeclared);
        self::assertStringContainsString('Style target "nope" is not declared', $undeclared[0]['message']);

        $computed = $linter->lint($good . '{{ style_attrs(data.t) }}', 'blocks/button.twig');
        self::assertCount(1, $computed);
        self::assertStringContainsString('must be a constant string', $computed[0]['message']);

        // Outside a declared block template the helpers are allowed but no target rule applies.
        self::assertSame([], $linter->lint('{{ style_classes(\'anything\') }}', 'partials/x.twig'));
        self::assertSame([], $linter->lint('<div></div>', 'blocks/quote.twig'), 'no block type, no rules');
        $token = $linter->lint($good . "{{ token_class('colors.text', data.c) }}", 'blocks/button.twig');
        self::assertSame([], $token, 'token_class takes any value expression');
    }

    /** Visual builder spec §5.4: a block type with blocks fields names each slot's element. */
    public function testALayoutItemTargetMustBeTheTemplatesOutermostElement(): void
    {
        // Container-layout spec §3.6: item properties land on the element that participates in the
        // parent's layout. The linter sees Twig, not HTML, so it holds the item target to being
        // styled first — the outermost element is rendered first in every block template.
        $linter = $this->linter();

        // cta declares root (which carries layout.item), title and panel.
        $good = '<aside class="c{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}>'
            . '<div class="i{{ style_classes(\'panel\') }}"{{ style_attrs(\'panel\') }}>'
            . '<h2 class="t{{ style_classes(\'title\') }}"{{ style_attrs(\'title\') }}></h2>'
            . '<div{{ slot_attrs(\'links\') }}>{{ blocks(data.links) }}</div></div></aside>';
        self::assertSame([], $linter->lint($good, 'blocks/cta.twig'));

        $inner = '<aside class="c">'
            . '<div class="i{{ style_classes(\'panel\') }}"{{ style_attrs(\'panel\') }}>'
            . '<h2 class="t{{ style_classes(\'title\') }}"{{ style_attrs(\'title\') }}></h2>'
            . '<div class="r{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}></div>'
            . '<div{{ slot_attrs(\'links\') }}>{{ blocks(data.links) }}</div></div></aside>';
        $violations = $linter->lint($inner, 'blocks/cta.twig');
        self::assertCount(1, $violations);
        self::assertStringContainsString(
            'layout.item target "root" must be the template\'s outermost element',
            $violations[0]['message'],
        );
        self::assertStringContainsString('"panel" is styled first', $violations[0]['message']);
    }

    public function testSlotRulesApplyToTypesWithBlocksFields(): void
    {
        $this->syncBlockStyleDeclarations();
        $linter = $this->linter();
        $root = '<div class="c{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}>';
        $good = $root . '<div{{ slot_attrs(\'content\') }}>{{ blocks(data.content) }}</div></div>';
        self::assertSame([], $linter->lint($good, 'blocks/container.twig'));

        $missing = $linter->lint($root . '{{ blocks(data.content) }}</div>', 'blocks/container.twig');
        self::assertCount(1, $missing);
        self::assertStringContainsString('Slot "content" has no element', $missing[0]['message']);

        $unknown = $linter->lint($good . '{{ slot_attrs(\'nope\') }}', 'blocks/container.twig');
        self::assertCount(1, $unknown);
        self::assertStringContainsString('Slot "nope" is not a blocks field', $unknown[0]['message']);

        $computed = $linter->lint($root . '<div{{ slot_attrs(data.s) }}></div></div>', 'blocks/container.twig');
        self::assertStringContainsString('must be a constant string', $computed[0]['message']);

        // Tabs render their items' data inline: no slot element, no rule.
        // tabs declares a second target, panels — the one content area — which must be styled too.
        $tabs = '<div class="t{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}>'
            . '<div class="p{{ style_classes(\'panels\') }}"{{ style_attrs(\'panels\') }}></div></div>';
        self::assertSame([], $linter->lint($tabs, 'blocks/tabs.twig'));
    }

    /** Visual builder spec §2.5: no template writes an inline style; the three emitters are functions. */
    public function testInlineStylesAreDenied(): void
    {
        $linter = $this->linter();
        $attribute = $linter->lint('<div class="x" style="color: {{ data.color }}"></div>');
        self::assertCount(1, $attribute);
        self::assertStringContainsString('Inline style attributes are not allowed', $attribute[0]['message']);
        self::assertCount(1, $linter->lint("<p STYLE='margin:0'>x</p>"), 'case and quoting do not matter');

        $element = $linter->lint('<style>.x { color: red }</style><div></div>');
        self::assertCount(1, $element);
        self::assertStringContainsString('Inline <style> elements are not allowed', $element[0]['message']);

        // Not inline styles: a data attribute, a class name, the block's own style helpers and the
        // enumerated emitters.
        $clean = <<<'TWIG'
        <div data-style="a" class="thallo-block-style thallo-style-promo{{ style_classes('root') }}"></div>
        {{ theme_colors_style() }}
        {{ font_faces_style('Figtree', 'fonts/r.woff2') }}
        {% set scope = theme_style_scope('rose', 'zinc') %}{{ scope.style }}
        TWIG;
        self::assertSame([], $linter->lint($clean));
    }
}
