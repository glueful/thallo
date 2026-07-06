<?php

declare(strict_types=1);

namespace Thallo\Seo\Sitemap;

/** Renders robots.txt from config groups, appending the Sitemap: line from the origin. */
final class RobotsBuilder
{
    /**
     * @param list<array{user_agent?:string,allow?:list<string>,disallow?:list<string>}> $groups
     */
    public function __construct(
        private readonly array $groups,
        private readonly string $origin,
    ) {
    }

    public function hasOrigin(): bool
    {
        return trim($this->origin) !== '';
    }

    public function render(): string
    {
        $lines = [];
        foreach ($this->groups as $group) {
            $lines[] = 'User-agent: ' . (string) ($group['user_agent'] ?? '*');
            foreach ($group['allow'] ?? [] as $path) {
                $lines[] = 'Allow: ' . (string) $path;
            }
            foreach ($group['disallow'] ?? [] as $path) {
                $lines[] = 'Disallow: ' . (string) $path;
            }
            $lines[] = '';
        }
        $lines[] = 'Sitemap: ' . rtrim($this->origin, '/') . '/sitemap.xml';
        return implode("\n", $lines) . "\n";
    }
}
