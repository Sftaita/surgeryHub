<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
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
 */
class UserEmailChangeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserAuditService $userAuditService,
        private readonly EmailService $emailService,
        private readonly ValidatorInterface $validator,
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

        $displayName = self::displayName($target);

        $target->setEmail($newEmail);
        $this->userAuditService->userEmailChanged($actor, $target, $oldEmail, $newEmail);
        $this->em->flush();

        $warnings = [];

        try {
            $this->emailService->sendTemplatedEmail(
                to: $oldEmail,
                subject: 'SurgicalHub — Votre adresse email a été modifiée',
                htmlTemplate: 'emails/user_email_changed_old_address.html.twig',
                context: [
                    'displayName' => $displayName,
                    'oldEmail' => $oldEmail,
                    'newEmail' => $newEmail,
                ],
                textTemplate: 'emails/user_email_changed_old_address.txt.twig',
            );
        } catch (\Throwable) {
            $warnings[] = [
                'code' => 'EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED',
                'recipient' => 'old',
                'message' => 'The email address was changed, but the notification to the previous address could not be queued.',
            ];
        }

        try {
            $this->emailService->sendTemplatedEmail(
                to: $newEmail,
                subject: 'SurgicalHub — Votre adresse email est confirmée',
                htmlTemplate: 'emails/user_email_changed_new_address.html.twig',
                context: [
                    'displayName' => $displayName,
                    'newEmail' => $newEmail,
                ],
                textTemplate: 'emails/user_email_changed_new_address.txt.twig',
            );
        } catch (\Throwable) {
            $warnings[] = [
                'code' => 'EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED',
                'recipient' => 'new',
                'message' => 'The email address was changed, but the notification to the new address could not be queued.',
            ];
        }

        return ['user' => $target, 'warnings' => $warnings];
    }

    private static function displayName(User $user): string
    {
        $name = trim((string) ($user->getFirstname() ?? '') . ' ' . (string) ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
