<?php

namespace App\Service;

use App\Entity\User;

interface WebPushServiceInterface
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void;

    /** @param User[] $users */
    public function sendToUsers(array $users, string $title, string $body, array $data = []): void;
}
