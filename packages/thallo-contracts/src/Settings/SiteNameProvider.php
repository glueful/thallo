<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The site's name as visitors see it: `site.name` in templates, `og:site_name`, and `{site_name}`
 * in the SEO title. One source of truth — Settings › General › Site name. Render surfaces used
 * to read deploy-time config instead, so renaming the site in the admin changed no page.
 */
interface SiteNameProvider
{
    public function siteName(): string;
}
