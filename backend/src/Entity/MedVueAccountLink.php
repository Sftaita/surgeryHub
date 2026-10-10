<?php

namespace App\Entity;

use App\Enum\MedVueLinkRevocationSource;
use App\Repository\MedVueAccountLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-140 — association technique entre un compte SurgicalHub et un compte MedVue.
 *
 * `medvueLinkId` est l'identifiant opaque attribué par MedVue au moment de l'échange du code
 * (POST .../link-codes/redeem) : c'est la seule clé connue des deux applications. MedVue renvoie
 * le MÊME linkId quand une même paire est reconfirmée — une ligne révoquée ici peut donc être
 * suivie d'une nouvelle ligne active portant le même linkId. L'historique n'est jamais réécrit :
 * révoquer pose `revokedAt`, relier crée une nouvelle ligne.
 *
 * Unicités « au plus une ligne active » émulées sans index partiel (MySQL) : `activeUserId` et
 * `activeLinkId` valent la clé tant que la ligne est active et NULL une fois révoquée ; un index
 * UNIQUE tolère plusieurs NULL.
 */
#[ORM\Entity(repositoryClass: MedVueAccountLinkRepository::class)]
#[ORM\Table(name: 'medvue_account_link')]
#[ORM\UniqueConstraint(name: 'uniq_medvue_link_active_user', columns: ['active_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_medvue_link_active_link', columns: ['active_link_id'])]
#[ORM\Index(name: 'idx_medvue_link_link_id', columns: ['medvue_link_id'])]
class MedVueAccountLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $medvueLinkId;

    #[ORM\Column(nullable: true)]
    private ?int $activeUserId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $activeLinkId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $linkedBy;

    #[ORM\Column]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $revokedBy = null;

    #[ORM\Column(length: 20, nullable: true, enumType: MedVueLinkRevocationSource::class)]
    private ?MedVueLinkRevocationSource $revokedVia = null;

    public function __construct(User $user, string $medvueLinkId, User $linkedBy, \DateTimeImmutable $linkedAt)
    {
        if ($user->getId() === null) {
            throw new \LogicException('MedVueAccountLink requires a persisted User.');
        }

        $this->user = $user;
        $this->medvueLinkId = $medvueLinkId;
        $this->activeUserId = $user->getId();
        $this->activeLinkId = $medvueLinkId;
        $this->linkedBy = $linkedBy;
        $this->linkedAt = $linkedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getMedvueLinkId(): string { return $this->medvueLinkId; }
    public function getLinkedBy(): ?User { return $this->linkedBy; }
    public function getLinkedAt(): \DateTimeImmutable { return $this->linkedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getRevokedBy(): ?User { return $this->revokedBy; }
    public function getRevokedVia(): ?MedVueLinkRevocationSource { return $this->revokedVia; }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    /** Idempotent : une ligne déjà révoquée garde sa première révocation. */
    public function revoke(?User $by, MedVueLinkRevocationSource $via, \DateTimeImmutable $at): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->revokedAt = $at;
        $this->revokedBy = $by;
        $this->revokedVia = $via;
        $this->activeUserId = null;
        $this->activeLinkId = null;
    }
}
