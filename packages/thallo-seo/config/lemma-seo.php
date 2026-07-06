<?php

declare(strict_types=1);

return [
    // NOTE: enable/disable is NOT configured here — the capability switchboard in the app's
    // config/lemma.php ('capabilities' => ['lemma.seo' => false]) is the only gate.

    // Per-type fallback field mapping: which entry field feeds each meta slot when there
    // is no per-entry override. Keyed by content-type slug.
    //   'blog' => ['title_field' => 'title', 'description_field' => 'excerpt', 'image_field' => 'cover'],
    'fallbacks' => [],

    // Site-wide defaults used when neither an override nor a fallback field is present.
    'defaults' => [
        'site_name' => env('SEO_SITE_NAME', 'Lemma'),
        'default_og_image' => env('SEO_DEFAULT_OG_IMAGE', ''),
        'title_template' => env('SEO_TITLE_TEMPLATE', '{title} — {site_name}'),
    ],

    // robots.txt groups. Each: user_agent + allow[] + disallow[]. The Sitemap: line is
    // appended automatically from config('lemma.seo.public_url_base').
    'robots' => [
        ['user_agent' => '*', 'allow' => ['/'], 'disallow' => []],
    ],
];
