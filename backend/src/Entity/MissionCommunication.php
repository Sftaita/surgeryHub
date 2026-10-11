<?php

namespace App\Entity;

use App\Enum\MissionCommunicationSource;
use App\Enum\MissionCommunicationWithdrawal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * H4 (D-130) — durable answer to "had this Mission really been presented to this person as
 * part of her planning, since when, and when was it taken away from her?".
 *
 * A row is only ever created once at least one channel ACTUALLY reached the person (in-app
 * item created, push or email accepted by the provider) — never for a mere attempt, a
 * queued email, or a dispatch whose channels were all skipped / failed. A self action
 * (CLAIM) is its own proof. Rows are never deleted: a withdrawal (cancel / release /
 * reassignment) sets withdrawnAt + withdrawnReason, and a later re-communication opens a
 * new row. The Mission FK is SET NULL with a snapshot of its id and start, so the history
 * stays readable if the Mission ever disappears.
 *
 * This registry never governs the N1/N2 absence information (habitual organisation / DRAFT):
 * those are a separate flow (D-127). It qualifies level-3 (published) changes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mission_communication')]
#[ORM\Index(name: 'idx_mission_communication_mission_user', columns: ['mission_id_snapshot', 'user_id'])]
#[ORM\Index(name: 'idx_mission_communication_user', columns: ['user_id', 'communicated_at'])]
class MissionCommunication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mission::class)]
    #[ORM\JoinColumn(name: 'mission_id', nullable: true, onDelete: 'SET NULL')]
    private ?Mission $mission = null;

    #[ORM\Column(name: 'mission_id_snapshot')]
    private int $missionIdSnapshot;

    // A copy of Mission::startAt — a business wall clock (D-066), same column type.
    #[ORM\Column(name: 'mission_start_snapshot', type: 'business_datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $missionStartSnapshot = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'user_name_snapshot', length: 255, nullable: true)]
    private ?string $userNameSnapshot = null;

    /** INSTRUMENTIST | SURGEON */
    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(length: 24, enumType: MissionCommunicationSource::class)]
    private MissionCommunicationSource $source;

    #[ORM\Column(name: 'communicated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $communicatedAt;

    #[ORM\Column(name: 'correlation_id', length: 36, nullable: true, options: ['fixed' => true])]
    private ?string $correlationId = null;

    /** The dispatch whose successful delivery proved the communication (null for a self action). */
    #[ORM\ManyToOne(targetEntity: NotificationDispatch::class)]
    #[ORM\JoinColumn(name: 'notification_dispatch_id', nullable: true, onDelete: 'SET NULL')]
    private ?NotificationDispatch $dispatch = null;

    #[ORM\Column(name: 'withdrawn_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(name: 'withdrawn_reason', length: 24, nullable: true, enumType: MissionCommunicationWithdrawal::class)]
    private ?MissionCommunicationWithdrawal $withdrawnReason = null;

    public function getId(): ?int { return $this->id; }
    public function getMission(): ?Mission { return $this->mission; }
    public function getMissionIdSnapshot(): int { return $this->missionIdSnapshot; }
    public function getMissionStartSnapshot(): ?\DateTimeImmutable { return $this->missionStartSnapshot; }
    public function getUser(): ?User { return $this->user; }
    public function getUserNameSnapshot(): ?string { return $this->userNameSnapshot; }
    public function getRole(): string { return $this->role; }
    public function getSource(): MissionCommunicationSource { return $this->source; }
    public function getCommunicatedAt(): \DateTimeImmutable { return $this->communicatedAt; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getDispatch(): ?NotificationDispatch { return $this->dispatch; }
    public function getWithdrawnAt(): ?\DateTimeImmutable { return $this->withdrawnAt; }
    public function getWithdrawnReason(): ?MissionCommunicationWithdrawal { return $this->withdrawnReason; }
    public function isActive(): bool { return $this->withdrawnAt === null; }

    public static function open(
        Mission $mission,
        User $user,
        string $userName,
        string $role,
        MissionCommunicationSource $source,
        \DateTimeImmutable $at,
        ?string $correlationId,
        ?NotificationDispatch $dispatch,
    ): self {
        $c = new self();
        $c->mission              = $mission;
        $c->missionIdSnapshot    = (int) $mission->getId();
        $c->missionStartSnapshot = $mission->getStartAt();
        $c->user                 = $user;
        $c->userNameSnapshot     = $userName;
        $c->role                 = $role;
        $c->source               = $source;
        $c->communicatedAt       = $at;
        $c->correlationId        = $correlationId;
        $c->dispatch             = $dispatch;

        return $c;
    }

    public function withdraw(MissionCommunicationWithdrawal $reason, \DateTimeImmutable $at): void
    {
        if ($this->withdrawnAt === null) {
            $this->withdrawnAt     = $at;
            $this->withdrawnReason = $reason;
        }
    }
}
