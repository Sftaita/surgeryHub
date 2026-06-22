<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken extends BaseRefreshToken
{
    #[ORM\Column(name: 'remember_me', type: 'boolean', options: ['default' => false])]
    private bool $rememberMe = false;

    public function isRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function setRememberMe(bool $rememberMe): static
    {
        $this->rememberMe = $rememberMe;

        return $this;
    }
}
