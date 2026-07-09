<?php

declare(strict_types=1);

namespace App\Content\Forms;

/** The sealed payload (form-block spec §4). form_key groups submissions; recipient never leaves the seal. */
final class FormDescriptor
{
    public const VERSION = 1;

    /** @param list<FieldDef> $fields */
    public function __construct(
        public readonly int $v,
        public readonly string $formKey,
        public readonly string $formName,
        public readonly array $fields,
        public readonly string $recipient,
        public readonly string $successMessage,
        public readonly ?string $redirectUrl,
        public readonly string $honeypotField,
        public readonly int $minSeconds,
        public readonly int $spamVersion,
        public readonly int $issuedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['v' => $this->v, 'form_key' => $this->formKey, 'form_name' => $this->formName,
            'fields' => array_map(static fn (FieldDef $f) => $f->toArray(), $this->fields),
            'recipient' => $this->recipient, 'success_message' => $this->successMessage,
            'redirect_url' => $this->redirectUrl, 'honeypot_field' => $this->honeypotField,
            'min_seconds' => $this->minSeconds, 'spam_version' => $this->spamVersion,
            'issued_at' => $this->issuedAt];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['v'],
            (string) $a['form_key'],
            (string) $a['form_name'],
            array_map(static fn ($f) => FieldDef::fromArray((array) $f), (array) $a['fields']),
            (string) $a['recipient'],
            (string) $a['success_message'],
            isset($a['redirect_url']) && $a['redirect_url'] !== null ? (string) $a['redirect_url'] : null,
            (string) $a['honeypot_field'],
            (int) $a['min_seconds'],
            (int) $a['spam_version'],
            (int) $a['issued_at'],
        );
    }
}
