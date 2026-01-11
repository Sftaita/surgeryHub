<?php

namespace App\Dto\Request\Response;

final class UserSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $firstname,
        public readonly ?string $lastname,
        public readonly ?bool $active = null,
        public readonly ?string $employmentType = null,
    ) {}

    public function displayName(): string
    {
        $name = trim((string) ($this->firstname ?? '') . ' ' . (string) ($this->lastname ?? ''));
        return $name !== '' ? $name : $this->email;
    }
}
