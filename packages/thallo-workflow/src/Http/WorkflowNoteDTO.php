<?php

declare(strict_types=1);

namespace Thallo\Workflow\Http;

use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Sanitize;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;

/** Validates the optional/required transition note (request-changes REQUIRES one). */
final class WorkflowNoteDTO
{
    public function __construct(public readonly ?string $note)
    {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException
     */
    public static function fromRequest(array $body, bool $required): self
    {
        $rules = $required
            ? ['note' => [new Required(), new Sanitize(['trim']), new Type('string'), new Length(1, 2000)]]
            : ['note' => [new Sanitize(['trim']), new Type('string'), new Length(0, 2000)]];

        $validator = new Validator($rules);
        $errors = $validator->validate(['note' => $body['note'] ?? null]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $clean = $validator->filtered();
        $note = is_string($clean['note'] ?? null) && $clean['note'] !== '' ? $clean['note'] : null;
        return new self($note);
    }
}
