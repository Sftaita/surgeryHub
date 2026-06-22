<?php

namespace App\Entity;

use App\Enum\NotificationType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user, per-notification-type channel preferences. Absence of a row for a given
 * (user, type) pair means "use the hardcoded defaults" — see
 * DefaultNotificationPreferenceResolver — not an error; rows are only created once a
 * user actually changes a default via a future settings UI.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_notification_preference_user_type', columns: ['user_id', 'notification_type']),
])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(enumType: NotificationType::class, length: 32)]
    private NotificationType $notificationType;

    #[ORM\Column(type: 'boolean')]
    private bool $inAppEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $emailEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $pushEnabled = false;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getNotificationType(): NotificationType { return $this->notificationType; }
    public function setNotificationType(NotificationType $notificationType): static { $this->notificationType = $notificationType; return $this; }

    public function isInAppEnabled(): bool { return $this->inAppEnabled; }
    public function setInAppEnabled(bool $inAppEnabled): static { $this->inAppEnabled = $inAppEnabled; return $this; }

    public function isEmailEnabled(): bool { return $this->emailEnabled; }
    public function setEmailEnabled(bool $emailEnabled): static { $this->emailEnabled = $emailEnabled; return $this; }

    public function isPushEnabled(): bool { return $this->pushEnabled; }
    public function setPushEnabled(bool $pushEnabled): static { $this->pushEnabled = $pushEnabled; return $this; }
}
