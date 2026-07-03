<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

/**
 * The merged template listing (spec §6): pack-default files + app-theme files + active
 * DB rows, with per-path origin (db > theme > default — loader precedence). Also reads
 * a FILESYSTEM source (theme file first, pack default second) as the editor's
 * copy-from-disk starting point.
 */
final class TemplateCatalog
{
    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly string $appThemesDir,
        private readonly string $packThemesDir,
    ) {
    }

    /** @return list<array{path:string,origin:string,overridden:bool,updated_at:?string}> */
    public function list(string $theme): array
    {
        $files = [];
        foreach ($this->walk($this->packThemesDir . '/default/templates') as $p) {
            $files[$p] = 'default';
        }
        if ($theme !== 'default') {
            foreach ($this->walk(rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates') as $p) {
                $files[$p] = 'theme';
            }
        }
        $rows = $this->repo->listActive($theme);

        $paths = array_unique([...array_keys($files), ...array_keys($rows)]);
        sort($paths);
        $out = [];
        foreach ($paths as $p) {
            $out[] = [
                'path' => $p,
                'origin' => isset($rows[$p]) ? 'db' : $files[$p],
                'overridden' => isset($rows[$p]),
                'updated_at' => isset($rows[$p]) && $rows[$p] !== '' ? $rows[$p] : null,
            ];
        }
        return $out;
    }

    /** @return array{source:string,origin:string}|null filesystem read (db handled by caller) */
    public function readFile(string $theme, string $path): ?array
    {
        if ($theme !== 'default') {
            $themeFile = rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates/' . $path;
            if (is_file($themeFile)) {
                return ['source' => (string) file_get_contents($themeFile), 'origin' => 'theme'];
            }
        }
        $default = $this->packThemesDir . '/default/templates/' . $path;
        if (is_file($default)) {
            return ['source' => (string) file_get_contents($default), 'origin' => 'default'];
        }
        return null;
    }

    /** @return list<string> theme-relative *.twig paths under $dir */
    private function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $out[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
            }
        }
        sort($out);
        return $out;
    }
}
