<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Session\DeviceSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Order is load-bearing (see class docblock on the caller-facing contract):
 * validation -> mutate User -> create AuditEvent -> flush -> dispatch Messenger.
 * The old address is captured BEFORE mutation — never re-derived after flush().
 * Emails are sent even to a suspended target: the point is account security, not
 * whether the account can currently log in. A failed dispatch never rolls back the
 * (already-flushed) email change; it only surfaces as a warning to the caller.
 *
 * H5a (category C) — both emails go through NotificationDispatcher with a MANDATORY email
 * (never subject to preferences), the explicit address recorded on each delivery, one
 * episode per change and side: the notice to the previous address is traceable as such.
 */
class UserEmailChangeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserAuditService $userAuditService,
        private readonly NotificationService $notifications,
        private readonly ValidatorInterface $validator,
        private readonly DeviceSessionService $sessions,
    ) {
    }

    /**
     * @return array{user: User, warnings: list<array{code: string, recipient: string, message: string}>}
     */
    public function changeEmail(User $actor, User $target, string $newEmail): array
    {
        $newEmail = trim($newEmail);

        if ($newEmail === '') {
            throw new BadRequestHttpException('email must not be empty.');
        }

        $oldEmail = (string) $target->getEmail();

        if (strcasecmp($newEmail, $oldEmail) === 0) {
            throw new BadRequestHttpException('The new email must be different from the current one.');
        }

        $violations = $this->validator->validate($newEmail, [new Assert\Email()]);
        if (count($violations) > 0) {
            throw new UnprocessableEntityHttpException((string) $violations);
        }

        $existing = $this->userRepository->findOneByEmailInsensitive($newEmail);
        if ($existing !== null && $existing->getId() !== $target->getId()) {
            throw new ConflictHttpException('This email address is already used by another account.');
        }

        $target->setEmail($newEmail);
        $this->userAuditService->userEmailChanged($actor, $target, $oldEmail, $newEmail);
        $this->em->flush();

        // D-063 — un changement d'e-mail termine les sessions ouvertes (reconnexion voulue).
        // D-157 — fermeture explicite (refresh tokens supprimés) au lieu de sessions orphelines
        // qui échouaient seulement au refresh suivant.
        $this->sessions->revokeAllForIdentifier($oldEmail);

        $warnings = [];

        $changeRef = bin2hex(random_bytes(8));
        foreach (['old' => $oldEmail, 'new' => $newEmail] as $side => $address) {
            try {
                $queued = $this->notifications->sendEmailChangeNotice($target, $address, $side, $changeRef, $oldEmail, $newEmail);
            } catch (\Throwable) {
                $queued = false;
            }
            if (!$queued) {
                $warnings[] = [
                    'code' => 'EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED',
                    'recipient' => $side,
                    'message' => sprintf('The email address was changed, but the notification to the %s address could not be queued.', $side === 'old' ? 'previous' : 'new'),
                ];
            }
        }

        return ['user' => $target, 'warnings' => $warnings];
    }
}
