<?php

namespace App\Entity;

use App\Enum\EncodingReminderStage;
use App\Enum\EncodingSituation;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-143 — historique des relances AUTOMATIQUES d'encodage : une ligne par (mission,
 * échéance J+1/J+2/J+4), jamais deux. La contrainte UNIQUE est la garantie
 * d'idempotence (double cron, worker redémarré, redéploiement) : la ligne est réservée
 * par un INSERT avant tout envoi, une seconde exécution échoue sur la contrainte et
 * n'envoie rien (EncodingReminderService::reserve()).
 *
 * Les canaux réellement utilisés (et la raison d'un e-mail) sont figés ici après
 * l'envoi ; le détail technique par canal reste dans OutboundNotification (D-084).
 * Les relances MANUELLES d'un manager restent tracées par AuditEvent (D-120).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mission_encoding_reminder')]
#[ORM\UniqueConstraint(name: 'uniq_mission_encoding_reminder_stage', columns: ['mission_id', 'stage'])]
class MissionEncodingReminder
{
    public const CHANNEL_IN_APP = 'IN_APP';
    public const CHANNEL_PUSH   = 'PUSH';
    public const CHANNEL_EMAIL  = 'EMAIL';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Mission $mission = null;

    #[ORM\Column(length: 8, enumType: EncodingReminderStage::class)]
    private ?EncodingReminderStage $stage = null;

    /** Échéance théorique (09:00 Europe/Brussels) — jamais l'heure du cron. */
    #[ORM\Column(name: 'due_at', type: 'business_datetime_immutable')]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'sent_at', type: 'business_datetime_immutable')]
    private ?\DateTimeImmutable $sentAt = null;

    /** Destinataire au moment de la relance (une réaffectation ultérieure ne réécrit rien). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $instrumentist = null;

    #[ORM\Column(length: 20, enumType: EncodingSituation::class)]
    private ?EncodingSituation $situation = null;

    /** @var list<string> */
    #[ORM\Column(name: 'missing_items', type: 'json')]
    private array $missingItems = [];

    /** @var list<string> canaux effectivement utilisés vers l'instrumentiste */
    #[ORM\Column(name: 'instrumentist_channels', type: 'json')]
    private array $instrumentistChannels = [];

    /** PUSH_NOT_AVAILABLE / PUSH_FAILED (repli) ou MANDATORY (J+4) — null sans e-mail. */
    #[ORM\Column(name: 'email_reason', length: 32, nullable: true)]
    private ?string $emailReason = null;

    /** J+4 : nombre de managers/admins alertés ; null aux autres échéances. */
    #[ORM\Column(name: 'managers_notified', nullable: true)]
    private ?int $managersNotified = null;

    /** Erreur technique résumée (code contrôlé, jamais un message brut) si l'envoi a échoué. */
    #[ORM\Column(name: 'failure', length: 64, nullable: true)]
    private ?string $failure = null;

    public function getId(): ?int { return $this->id; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(Mission $mission): static { $this->mission = $mission; return $this; }

    public function getStage(): ?EncodingReminderStage { return $this->stage; }
    public function setStage(EncodingReminderStage $stage): static { $this->stage = $stage; return $this; }

    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(\DateTimeImmutable $dueAt): static { $this->dueAt = $dueAt; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(?User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }

    public function getSituation(): ?EncodingSituation { return $this->situation; }
    public function setSituation(EncodingSituation $situation): static { $this->situation = $situation; return $this; }

    /** @return list<string> */
    public function getMissingItems(): array { return $this->missingItems; }
    /** @param list<string> $missingItems */
    public function setMissingItems(array $missingItems): static { $this->missingItems = array_values($missingItems); return $this; }

    /** @return list<string> */
    public function getInstrumentistChannels(): array { return $this->instrumentistChannels; }
    /** @param list<string> $channels */
    public function setInstrumentistChannels(array $channels): static { $this->instrumentistChannels = array_values(array_unique($channels)); return $this; }

    public function getEmailReason(): ?string { return $this->emailReason; }
    public function setEmailReason(?string $emailReason): static { $this->emailReason = $emailReason; return $this; }

    public function getManagersNotified(): ?int { return $this->managersNotified; }
    public function setManagersNotified(?int $managersNotified): static { $this->managersNotified = $managersNotified; return $this; }

    public function getFailure(): ?string { return $this->failure; }
    public function setFailure(?string $failure): static { $this->failure = $failure; return $this; }
}
