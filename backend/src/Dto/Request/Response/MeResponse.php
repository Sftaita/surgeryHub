<?php
// src/Dto/Response/MeResponse.php

namespace App\Dto\Response;

final class MeResponse
{
    /**
     * @param array<int, array{id:int, name:string, timezone:string}> $sites
     */
    public function __construct(
        public int $id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public string $role, // INSTRUMENTIST | SURGEON | MANAGER | ADMIN
        public ?InstrumentistProfileResponse $instrumentistProfile,
        public array $sites,
        public ?int $activeSiteId,
    ) {}
}
