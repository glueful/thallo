<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The theme runtime knows it is on a stage by the `<html data-thallo-canvas>` marker
 * (regions-stage spec §4.4), not by finding an annotated block: the header & footer stage with
 * both regions empty and its page body untagged has none, and must still count as a stage.
 * Executes the served runtime under node against a stubbed DOM.
 */
final class RuntimeCanvasMarkerTest extends AppTestCase
{
    private function node(): ?string
    {
        $env = getenv('THALLO_NODE_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));
        return $which !== '' ? $which : null;
    }

    /** @return bool whether a canvas-skipping module enhanced its component */
    private function enhanced(?string $marker): bool
    {
        $node = $this->node();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the runtime');
        }
        $src = json_encode(
            (string) file_get_contents(
                $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/runtime.js',
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $markerJs = json_encode($marker);
        $js = <<<JS
        'use strict';
        global.console = { error: function () {}, log: console.log };
        var marker = {$markerJs};
        var root = {
          dataset: {},
          matches: function () { return false; },
          querySelectorAll: function () { return []; },
          querySelector: function () { return null; },
          getAttribute: function (name) { return name === 'data-thallo-canvas' ? marker : null; },
          hasAttribute: function (name) { return name === 'data-thallo-canvas' && marker !== null; },
          setAttribute: function () {},
          addEventListener: function () {},
          dispatchEvent: function () { return true; }
        };
        global.document = {
          readyState: 'loading',
          addEventListener: function () {},
          querySelector: function () { return null; }, // no annotated block anywhere
          querySelectorAll: function () { return []; },
          documentElement: root
        };
        global.window = global;
        eval({$src});
        var ran = false;
        var attrs = {};
        var comp = {
          matches: function () { return true; },
          querySelectorAll: function () { return []; },
          getAttribute: function (n) { return n in attrs ? attrs[n] : null; },
          setAttribute: function (n, v) { attrs[n] = String(v); },
          removeAttribute: function (n) { delete attrs[n]; }
        };
        window.ThalloRuntime.register('probe', { selector: '.probe', enhance: function () { ran = true; } });
        window.ThalloRuntime.enhance(comp);
        console.log(ran ? 'RAN' : 'SKIPPED');
        JS;
        $file = sys_get_temp_dir() . '/thallo_canvas_marker_' . getmypid() . '.cjs';
        file_put_contents($file, $js);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            return str_contains(implode("\n", $out), 'RAN');
        } finally {
            @unlink($file);
        }
    }

    public function testTheMarkerAloneMakesItAStage(): void
    {
        self::assertFalse($this->enhanced('regions'), 'a module ran on the header & footer stage');
        self::assertFalse($this->enhanced('entry'), 'a module ran on the Design view stage');
    }

    public function testWithoutTheMarkerModulesRun(): void
    {
        self::assertTrue($this->enhanced(null));
    }
}
