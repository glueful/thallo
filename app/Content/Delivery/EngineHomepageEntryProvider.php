<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Settings\GeneralSettings;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Lemma\Contracts\Delivery\HomepageEntryProvider;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Psr\Log\LoggerInterface;

use function config;

/**
 * SOURCE-AWARE homepage lookup (homepage-setting spec §0): the DB site setting
 * wins ONLY while it currently resolves to published public content — an
 * override that broke after it was written (entry unpublished/deleted) is
 * logged and skipped every request, so the env fallback shows through with no
 * restart and the site never 500s on a valid-at-write setting. Whatever this
 * returns, the renderer treats like deploy config: an unresolvable value is a
 * LOUD config error — by construction that can only be env-sourced.
 */
final class EngineHomepageEntryProvider implements HomepageEntryProvider
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly GeneralSettings $settings,
        private readonly PublicRouteResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function homepageEntry(): string
    {
        $override = $this->settings->homepageEntryOverride();
        if ($override !== null && $override !== '') {
            if (($this->resolver->resolveEntry($override)['kind'] ?? null) === 'content') {
                return $override;
            }
            $this->logger->warning(
                'lemma: the homepage_entry site setting no longer resolves to published '
                . 'public content — falling back to the deploy default',
                ['entry' => $override],
            );
        }
        return (string) config($this->context, 'lemma_render.homepage_entry', '');
    }
}
