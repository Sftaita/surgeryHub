<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Réglage par site de la communication des absences chirurgiens (Lot A/D-114). Une seule
 * ligne par site (upsert via find-or-create dans AbsenceCommunicationSiteConfigService),
 * façon ShiftPeriodConfig. Les 4 champs "gestion du bloc" sont créés dès ce lot pour
 * stabiliser le schéma mais ne sont exploités qu'à partir du Lot B — leur contrat API n'est
 * pas exposé avant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'absence_communication_site_config')]
class AbsenceCommunicationSiteConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['planning:read'])]
    private bool $notifyColleaguesEnabled = false;

    /** Lot B — inutilisé en Lot A. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['planning:read'])]
    private bool $notifyBlockManagementEnabled = false;

    /** Lot B — inutilisé en Lot A. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['planning:read'])]
    private ?string $blockManagementEmailTo = null;

    /** Lot B — inutilisé en Lot A. @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['planning:read'])]
    private array $blockManagementEmailCc = [];

    /** Lot B — inutilisé en Lot A. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['planning:read'])]
    private ?int $blockManagementDelayDays = null;

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function isNotifyColleaguesEnabled(): bool { return $this->notifyColleaguesEnabled; }
    public function setNotifyColleaguesEnabled(bool $notifyColleaguesEnabled): static { $this->notifyColleaguesEnabled = $notifyColleaguesEnabled; return $this; }

    public function isNotifyBlockManagementEnabled(): bool { return $this->notifyBlockManagementEnabled; }
    public function setNotifyBlockManagementEnabled(bool $notifyBlockManagementEnabled): static { $this->notifyBlockManagementEnabled = $notifyBlockManagementEnabled; return $this; }

    public function getBlockManagementEmailTo(): ?string { return $this->blockManagementEmailTo; }
    public function setBlockManagementEmailTo(?string $blockManagementEmailTo): static { $this->blockManagementEmailTo = $blockManagementEmailTo; return $this; }

    /** @return list<string> */
    public function getBlockManagementEmailCc(): array { return $this->blockManagementEmailCc; }
    /** @param list<string> $blockManagementEmailCc */
    public function setBlockManagementEmailCc(array $blockManagementEmailCc): static { $this->blockManagementEmailCc = $blockManagementEmailCc; return $this; }

    public function getBlockManagementDelayDays(): ?int { return $this->blockManagementDelayDays; }
    public function setBlockManagementDelayDays(?int $blockManagementDelayDays): static { $this->blockManagementDelayDays = $blockManagementDelayDays; return $this; }
}
