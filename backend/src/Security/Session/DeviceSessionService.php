<?php

namespace App\Security\Session;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * D-157 — persistent, sliding device sessions on top of the Gesdinet refresh-token table.
 *
 * - A login opens a session (one "family"); every refresh rotates the presented refresh token
 *   and gives its successor a fresh inactivity window (30 days "rester connecté", 1 day
 *   otherwise): a user active at least once per window is never logged out.
 * - Concurrency: a token already rotated is answered with the current token of its session when
 *   it is presented again within ROTATION_GRACE_SECONDS (two tabs / two requests refreshing at
 *   once), or when its successor was never used (the client lost the previous response to a
 *   network failure). Otherwise it is a replay of a stolen token: the whole session is revoked.
 * - Access tokens carry the session id (claim SESSION_CLAIM): SessionAccessTokenListener refuses
 *   an access token whose session was revoked, even before it expires.
 * - Refresh token values are never logged.
 */
class DeviceSessionService
{
    public const TTL_REMEMBER_ME = 2592000; // 30 days without any use
    public const TTL_DEFAULT = 86400;       // 1 day without any use (shared device)
    public const ROTATION_GRACE_SECONDS = 120;
    /** JWT claim holding the device session id (family id, not a credential). */
    public const SESSION_CLAIM = 'sid';
    private const PRUNE_ROTATED_AFTER_DAYS = 7;
    private const MAX_CHAIN_HOPS = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenGeneratorInterface $generator,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    public static function ttl(bool $rememberMe): int
    {
        return $rememberMe ? self::TTL_REMEMBER_ME : self::TTL_DEFAULT;
    }

    /** Opens a new device session (password or Google login). */
    public function open(User $user, bool $rememberMe): RefreshToken
    {
        $token = $this->newGeneration($user, $rememberMe, bin2hex(random_bytes(16)));
        $this->em->flush();

        return $token;
    }

    /** Access token (JWT) bound to the device session: revoking the session invalidates it at once. */
    public function accessToken(User $user, RefreshToken|string $session): string
    {
        $sessionId = $session instanceof RefreshToken ? $session->getFamilyId() : $session;

        return $this->jwtManager->createFromPayload($user, [self::SESSION_CLAIM => (string) $sessionId]);
    }

    /**
     * Exchanges a presented refresh token for the session's current one.
     *
     * @return array{user: User, refreshToken: string, sessionId: string}
     *
     * @throws SessionRefreshException when the session cannot be restored (the client must log in again)
     */
    public function refresh(string $presented): array
    {
        // Explicit transaction, not wrapInTransaction(): a refused refresh is an expected outcome and
        // must not close the EntityManager (it closes it on any exception).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $this->refreshLocked($presented);
            $connection->commit();

            return $result;
        } catch (SessionRefreshException $e) {
            $connection->rollBack();
            $this->em->clear();
            if ($e->revokeSession) {
                $this->revokeFamily($e->familyId, $e->rowId);
            }
            $this->logger->info('Session refresh refused', ['reason' => $e->reason, 'session' => self::fingerprint($e->familyId)]);

            throw $e;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /** Explicit logout: revokes every generation of the presented token's session. Idempotent. */
    public function revoke(string $presented): int
    {
        $row = $this->em->getRepository(RefreshToken::class)->findOneBy(['refreshToken' => $presented]);
        if ($row === null) {
            return 0;
        }

        return $this->revokeFamily($row->getFamilyId(), $row->getId());
    }

    /** Security event (account suspended, password changed, compromise…): every session of the user, on every device. */
    public function revokeAllForUser(User $user): int
    {
        return $this->revokeAllForIdentifier((string) $user->getUserIdentifier());
    }

    /** Same, by login identifier — e.g. the former email after an email change (D-063). */
    public function revokeAllForIdentifier(string $identifier): int
    {
        return $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.username = :u')
            ->setParameter('u', $identifier)
            ->execute();
    }

    /** @return array{user: User, refreshToken: string, sessionId: string} */
    private function refreshLocked(string $presented): array
    {
        $now = self::now();
        $row = $this->lockByToken($presented);
        if ($row === null) {
            throw new SessionRefreshException(SessionRefreshException::NOT_FOUND);
        }
        $family = $row->getFamilyId();

        if ($row->getRotatedAt() !== null) {
            $head = $this->head($row);
            $successorUnused = $head !== null && $row->getReplacedBy() === $head->getRefreshToken();
            $inGrace = $row->getRotatedAt()->getTimestamp() >= $now->getTimestamp() - self::ROTATION_GRACE_SECONDS;
            if ($head === null || !($successorUnused || $inGrace)) {
                throw new SessionRefreshException(SessionRefreshException::REUSED, $family, $row->getId(), true);
            }
            $row = $head;
        }

        if ($row->getValid() === null || $row->getValid() < $now) {
            throw new SessionRefreshException(SessionRefreshException::EXPIRED, $family, $row->getId(), true);
        }

        $user = $this->users->findOneBy(['email' => $row->getUsername()]);
        if (!$user instanceof User || !$user->isActive()) {
            throw new SessionRefreshException(SessionRefreshException::USER_INACTIVE, $family, $row->getId(), true);
        }

        if ($row->getRotatedAt() === null && $row->getRefreshToken() !== $presented) {
            // Answer with the session's current token (concurrent refresh / lost response), and slide it.
            $row->setValid(self::after($now, self::ttl($row->isRememberMe())));
            $this->em->flush();

            return ['user' => $user, 'refreshToken' => (string) $row->getRefreshToken(), 'sessionId' => (string) $row->getFamilyId()];
        }

        $family ??= bin2hex(random_bytes(16));
        $row->setFamilyId($family);
        $successor = $this->newGeneration($user, $row->isRememberMe(), $family);
        $row->markRotated($now, (string) $successor->getRefreshToken());
        $this->em->flush();

        $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.familyId = :f AND r.rotatedAt < :cutoff')
            ->setParameter('f', $family)
            ->setParameter('cutoff', self::after($now, -self::PRUNE_ROTATED_AFTER_DAYS * 86400))
            ->execute();

        return ['user' => $user, 'refreshToken' => (string) $successor->getRefreshToken(), 'sessionId' => $family];
    }

    /** The not-yet-rotated end of the chain started by $row (locked), or null when it was pruned/revoked. */
    private function head(RefreshToken $row): ?RefreshToken
    {
        $current = $row;
        for ($i = 0; $i < self::MAX_CHAIN_HOPS && $current->getRotatedAt() !== null; ++$i) {
            $next = $current->getReplacedBy();
            $current = $next !== null ? $this->lockByToken($next) : null;
            if ($current === null) {
                return null;
            }
        }

        return $current->getRotatedAt() === null ? $current : null;
    }

    private function newGeneration(User $user, bool $rememberMe, string $family): RefreshToken
    {
        $now = self::now();
        $ttl = self::ttl($rememberMe);
        /** @var RefreshToken $token */
        $token = $this->generator->createForUserWithTtl($user, $ttl);
        $token->setValid(self::after($now, $ttl));
        $token->setRememberMe($rememberMe);
        $token->setFamilyId($family);
        $token->setCreatedAt($now);
        $this->em->persist($token);

        return $token;
    }

    private function lockByToken(string $value): ?RefreshToken
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\RefreshToken r WHERE r.refreshToken = :t')
            ->setParameter('t', $value)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    private function revokeFamily(?string $family, ?int $rowId): int
    {
        if ($family !== null) {
            return $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.familyId = :f')
                ->setParameter('f', $family)
                ->execute();
        }
        if ($rowId === null) {
            return 0;
        }

        return $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.id = :id')
            ->setParameter('id', $rowId)
            ->execute();
    }

    /**
     * Current time in PHP's default timezone: `refresh_tokens.valid` is a DATETIME without zone that
     * Doctrine reads back in that timezone, so it must be written in it too (a clock in another zone,
     * e.g. a UTC MockClock, would otherwise shift every expiry by the offset).
     */
    private static function now(): \DateTime
    {
        return \DateTime::createFromImmutable(Clock::get()->now()->setTimezone(new \DateTimeZone(date_default_timezone_get())));
    }

    /** $seconds of elapsed time later (modify('+N seconds') counts wall-clock time and gains an hour across a DST change). */
    private static function after(\DateTime $from, int $seconds): \DateTime
    {
        return (clone $from)->setTimestamp($from->getTimestamp() + $seconds);
    }

    /** Short, non-reversible session label for logs (never the token itself). */
    private static function fingerprint(?string $family): ?string
    {
        return $family !== null ? substr(hash('sha256', $family), 0, 12) : null;
    }
}
