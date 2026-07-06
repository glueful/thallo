<?php

declare(strict_types=1);

namespace Thallo\Seo\Meta;

use Glueful\Validation\Rules\InArray;
use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Regex;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;

/**
 * Validated carrier for one seo_meta upsert. PARTIAL-UPDATE semantics: only keys present in
 * the request body are validated and carried — an absent key is untouched, an explicit null
 * clears the field. (A typed RequestData DTO can't distinguish absent from null, which is why
 * this is a manual Validator DTO.) Every rule passes null through, so `"title": null` is valid.
 */
final class SeoMetaUpsertDTO
{
    /** Vocabulary shared with the admin SPA's SeoPanel — keep in sync. */
    private const TWITTER_CARDS = ['summary', 'summary_large_image', 'app', 'player'];
    private const ROBOTS = ['index', 'noindex', 'noindex,nofollow'];

    /**
     * @param array<string, string|null> $fields Present writable columns only (null clears).
     */
    public function __construct(
        public readonly string $locale,
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, mixed> $body Decoded request body
     * @throws ValidationException
     */
    public static function fromRequest(string $locale, array $body): self
    {
        $rules = [
            // locale lands in a 12-char column; an oversize/garbage value must be a 422,
            // not a database error.
            'locale' => [new Required(), new Regex('/^[A-Za-z0-9_-]{1,12}$/')],
        ];
        $data = ['locale' => $locale];

        // Length caps mirror the seo_meta columns so overlong input 422s instead of erroring
        // at the database; Type('string') rejects arrays/objects/bools the same way.
        $fieldRules = [
            'title' => [new Type('string'), new Length(0, 255)],
            'description' => [new Type('string'), new Length(0, 5000)],
            'og_title' => [new Type('string'), new Length(0, 255)],
            'og_description' => [new Type('string'), new Length(0, 5000)],
            'og_image' => [new Type('string'), new Length(0, 1024)],
            'twitter_card' => [new InArray(self::TWITTER_CARDS)],
            'robots' => [new InArray(self::ROBOTS)],
        ];
        foreach ($fieldRules as $col => $colRules) {
            if (array_key_exists($col, $body)) {
                $rules[$col] = $colRules;
                $data[$col] = $body[$col];
            }
        }

        $validator = new Validator($rules);
        $errors = $validator->validate($data);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $clean = $validator->filtered();
        $cleanLocale = (string) $clean['locale'];
        unset($clean['locale']);

        /** @var array<string, string|null> $clean */
        return new self($cleanLocale, $clean);
    }
}
