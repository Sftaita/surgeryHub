<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\AbsenceCommunicationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une ligne = un envoi réel à un destinataire (Lot A/D-114). Pour « Libération de salle », un
 * `SurgeonAbsenceCommunication` (la décision logique) donne lieu à autant de deliveries que
 * de chirurgiens collègues éligibles — chacun avec son propre statut, jamais un statut
 * agrégé au niveau parent : l'échec d'un envoi à un collègue ne doit jamais masquer le
 * succès des autres, ni inversement.
 *
 * `status` ne passe à SENT/FAILED que par confirmation réelle du handler d'envoi
 * (SendTemplatedEmailMessageHandler après un `$mailer->send()` qui n'a pas levé, ou
 * OutboundNotificationEmailFailureListener une fois les retries Messenger épuisés) — jamais
 * de manière optimiste au moment du dispatch, qui ne prouve rien côté SMTP.
 *
 * `recipient` est nullable : les destinataires « Libération de salle » (Lot A) sont toujours
 * des `User` réels, mais les destinataires « gestion du bloc » (Lot B) sont une adresse
 * mailbox configurée par le manager, sans compte SurgicalHub associé — `recipientEmailSnapshot`
 * est donc la seule source de vérité systématiquement renseignée.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['status'], name: 'idx_absence_comm_delivery_status')]
class SurgeonAbsenceCommunicationDelivery
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'deliveries')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?SurgeonAbsenceCommunication $communication = null;

    /** Toujours renseigné en Lot A (chirurgien collègue) ; nullable pour la gestion du bloc (Lot B). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?User $recipient = null;

    #[ORM\Column(length: 255)]
    #[Groups(['planning:read'])]
    private string $recipientEmailSnapshot;

    /** Lot B — inutilisé en Lot A (CC de la gestion du bloc). @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['planning:read'])]
    private array $recipientCcSnapshot = [];

    #[ORM\Column(enumType: AbsenceCommunicationStatus::class, length: 20)]
    #[Groups(['planning:read'])]
    private AbsenceCommunicationStatus $status;

    /** Lot B — inutilisé en Lot A. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    /** Lot B — inutilisé en Lot A. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['planning:read'])]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['planning:read'])]
    private ?string $lastError = null;

    /**
     * Lot B (D-114) — garde-fou de claim atomique pour `SendScheduledAbsenceCommunicationsCommand` :
     * `status` seul ne suffit PAS à empêcher un second run (cron concurrent, ou un run qui
     * suit de près) de redispatcher la même communication programmée, puisque `status` reste
     * volontairement `SCHEDULED` tant que le pipeline d'envoi réel n'a pas confirmé `SENT`/
     * `FAILED` — il existe donc une fenêtre entre "dispatché" et "confirmé" où `status` seul
     * ne distingue pas "jamais tenté" de "déjà en cours d'envoi". Posé sous le même verrou
     * pessimiste que le claim, filtré par la commande (`dispatchClaimedAt IS NULL`) — jamais
     * réinitialisé (même en cas d'échec final, `lastError`/`status=FAILED` suffisent alors à
     * comprendre l'état ; un nouvel envoi pour la même communication passe toujours par une
     * NOUVELLE ligne, jamais une remise à zéro de celle-ci).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $dispatchClaimedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getCommunication(): ?SurgeonAbsenceCommunication { return $this->communication; }
    public function setCommunication(SurgeonAbsenceCommunication $communication): static { $this->communication = $communication; return $this; }

    public function getRecipient(): ?User { return $this->recipient; }
    public function setRecipient(?User $recipient): static { $this->recipient = $recipient; return $this; }

    public function getRecipientEmailSnapshot(): string { return $this->recipientEmailSnapshot; }
    public function setRecipientEmailSnapshot(string $recipientEmailSnapshot): static { $this->recipientEmailSnapshot = $recipientEmailSnapshot; return $this; }

    /** @return list<string> */
    public function getRecipientCcSnapshot(): array { return $this->recipientCcSnapshot; }
    /** @param list<string> $recipientCcSnapshot */
    public function setRecipientCcSnapshot(array $recipientCcSnapshot): static { $this->recipientCcSnapshot = $recipientCcSnapshot; return $this; }

    public function getStatus(): AbsenceCommunicationStatus { return $this->status; }
    public function setStatus(AbsenceCommunicationStatus $status): static { $this->status = $status; return $this; }

    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static { $this->scheduledAt = $scheduledAt; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }

    public function getAttemptCount(): int { return $this->attemptCount; }
    public function setAttemptCount(int $attemptCount): static { $this->attemptCount = $attemptCount; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): static { $this->lastError = $lastError; return $this; }

    public function getDispatchClaimedAt(): ?\DateTimeImmutable { return $this->dispatchClaimedAt; }
    public function setDispatchClaimedAt(?\DateTimeImmutable $dispatchClaimedAt): static { $this->dispatchClaimedAt = $dispatchClaimedAt; return $this; }
}
