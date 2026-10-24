<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * D-157 — one row per generation of a device session. All the generations of one login share a
 * `familyId` (the device session); a refresh rotates the presented generation (`rotatedAt`,
 * `replacedBy` = its successor's token) and the successor carries the sliding expiry.
 * Revoking a session = deleting every row of its family.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'IDX_REFRESH_TOKENS_FAMILY', columns: ['family_id'])]
#[ORM\Index(name: 'IDX_REFRESH_TOKENS_USERNAME', columns: ['username'])]
class RefreshToken extends BaseRefreshToken
{
    #[ORM\Column(name: 'remember_me', type: 'boolean', options: ['default' => false])]
    private bool $rememberMe = false;

    /** Null only on rows issued before D-157 (each is then its own family). */
    #[ORM\Column(name: 'family_id', length: 64, nullable: true)]
    private ?string $familyId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'rotated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $rotatedAt = null;

    #[ORM\Column(name: 'replaced_by', length: 128, nullable: true)]
    private ?string $replacedBy = null;

    public function isRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function setRememberMe(bool $rememberMe): static
    {
        $this->rememberMe = $rememberMe;

        return $this;
    }

    public function getFamilyId(): ?string
    {
        return $this->familyId;
    }

    public function setFamilyId(?string $familyId): static
    {
        $this->familyId = $familyId;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getRotatedAt(): ?\DateTimeInterface
    {
        return $this->rotatedAt;
    }

    public function getReplacedBy(): ?string
    {
        return $this->replacedBy;
    }

    public function markRotated(\DateTimeInterface $at, string $successorToken): static
    {
        $this->rotatedAt = $at;
        $this->replacedBy = $successorToken;

        return $this;
    }
}
