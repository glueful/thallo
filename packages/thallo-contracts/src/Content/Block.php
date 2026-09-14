<?php

declare(strict_types=1);

namespace Thallo\Contracts\Content;

/**
 * One block instance (visual builder spec §1.2): `id`, `type`, `data`, `settings`. Every path that
 * reconstructs a block goes through this object so none can forget `settings`. Nested blocks
 * live inside `data` under their `blocks` fields, as stored.
 */
final readonly class Block
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
        public array $settings = [],
    ) {
        if ($id === '' || $type === '') {
            throw new \InvalidArgumentException('a block needs an id and a type');
        }
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = $raw['id'] ?? null;
        $type = $raw['type'] ?? null;
        if (!is_string($id) || !is_string($type)) {
            throw new \InvalidArgumentException('a block needs an id and a type');
        }
        $data = $raw['data'] ?? [];
        $settings = $raw['settings'] ?? [];
        return new self($id, $type, is_array($data) ? $data : [], is_array($settings) ? $settings : []);
    }

    /** @return array{id: string, type: string, data: array<string, mixed>, settings: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'data' => $this->data, 'settings' => $this->settings];
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): self
    {
        return new self($this->id, $this->type, $data, $this->settings);
    }

    /** @param array<string, mixed> $settings */
    public function withSettings(array $settings): self
    {
        return new self($this->id, $this->type, $this->data, $settings);
    }
}
