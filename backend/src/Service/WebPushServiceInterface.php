<?php

namespace App\Service;

use App\Entity\User;

interface WebPushServiceInterface
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void;
}
