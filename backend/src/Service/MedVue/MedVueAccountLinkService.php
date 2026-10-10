<?php

namespace App\Service\MedVue;

use App\Entity\MedVueAccountLink;
use App\Entity\User;
use App\Enum\MedVueLinkRevocationSource;
use App\Repository\MedVueAccountLinkRepository;
use App\Service\UserAuditService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * D-140 — association / révocation d'un compte SurgicalHub avec un compte MedVue.
 *
 * L'autorisation (soi-même, ou ROLE_ADMIN pour autrui) est décidée en amont par
 * MedVueIntegrationVoter::MANAGE_LINK ; ce service applique les règles d'association :
 *   1. au plus une liaison active par compte SurgicalHub (refus local, aucun appel à MedVue) ;
 *   2. limitation des essais par acteur et par compte ciblé, AVANT tout appel à MedVue ;
 *   3. le code est normalisé puis transmis une seule fois à MedVue, qui seul peut le vérifier
 *      (haché, à usage unique, 10 minutes) : SurgicalHub ne le stocke ni ne le journalise jamais ;
 *   4. chaque issue est tracée dans UserAuditEvent (acteur, compte visé, date, résultat).
 */
class MedVueAccountLinkService
{
    /** Alphabet base32 de Crockford (sans I, L, O, U), 12 caractères = 60 bits. */
    private const CODE_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{12}$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly MedVueAccountLinkRepository $links,
        private readonly MedVueLinkCodeRedeemer $redeemer,
        private readonly UserAuditService $audit,
        #[Autowire(service: 'limiter.medvue_link_actor')] private readonly RateLimiterFactory $actorLimiter,
        #[Autowire(service: 'limiter.medvue_link_target')] private readonly RateLimiterFactory $targetLimiter,
    ) {}

    public function isConfigured(): bool
    {
        return $this->redeemer->isConfigured();
    }

    public function findActive(User $user): ?MedVueAccountLink
    {
        return $this->links->findActiveForUser($user);
    }

    /**
     * Majuscules, sans espaces ni tirets, et les confusions usuelles de Crockford (O→0, I/L→1),
     * exactement comme MedVue. Renvoie null si le résultat n'est pas un code possible.
     */
    public static function normalizeCode(string $raw): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/u', '', $raw) ?? '');
        $code = strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);

        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    /** @throws MedVueLinkException */
    public function link(User $actor, User $target, string $rawCode): MedVueAccountLink
    {
        if (!$this->redeemer->isConfigured()) {
            throw new MedVueLinkException(MedVueLinkException::NOT_CONFIGURED);
        }

        if ($this->links->findActiveForUser($target) !== null) {
            throw new MedVueLinkException(MedVueLinkException::ALREADY_LINKED_LOCALLY);
        }

        // Les deux compteurs sont consommés avant même de regarder le code : un essai, valide ou
        // non, bien formé ou non, compte toujours.
        foreach ([
            $this->actorLimiter->create('actor-' . $actor->getId()),
            $this->targetLimiter->create('target-' . $target->getId()),
        ] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $this->failed($actor, $target, MedVueLinkException::RATE_LIMITED);
                throw new MedVueLinkException(
                    MedVueLinkException::RATE_LIMITED,
                    max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                );
            }
        }

        $code = self::normalizeCode($rawCode);
        if ($code === null) {
            $this->failed($actor, $target, MedVueLinkException::INVALID_CODE_FORMAT);
            throw new MedVueLinkException(MedVueLinkException::INVALID_CODE_FORMAT);
        }

        try {
            $result = $this->redeemer->redeem(
                normalizedCode: $code,
                surgicalHubUserId: (string) $target->getId(),
                surgicalHubDisplayName: self::displayName($target),
                actorDisplayName: self::displayName($actor),
                actorIsAdministrator: $actor->getId() !== $target->getId(),
            );
        } catch (MedVueLinkException $e) {
            $this->failed($actor, $target, $e->reason);
            throw $e;
        }

        $linkId = $result['linkId'];

        // MedVue renvoie le même linkId pour une même paire reconfirmée : une ligne révoquée du
        // même titulaire est normale (historique). Le même linkId actif ailleurs ne l'est jamais.
        $activeElsewhere = $this->links->findActiveByLinkId($linkId);
        if ($activeElsewhere !== null) {
            $this->failed($actor, $target, MedVueLinkException::LINK_ID_CONFLICT);
            throw new MedVueLinkException(MedVueLinkException::LINK_ID_CONFLICT);
        }

        $link = new MedVueAccountLink($target, $linkId, $actor, new \DateTimeImmutable());
        $this->em->persist($link);
        $this->audit->medvueLinked($actor, $target, $linkId);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux associations simultanées pour le même compte : la première a gagné.
            $this->doctrine->resetManager();
            throw new MedVueLinkException(MedVueLinkException::ALREADY_LINKED_LOCALLY);
        }

        return $link;
    }

    /** Révocation depuis SurgicalHub (titulaire ou ADMIN). Aucun appel à MedVue : il l'apprend à sa lecture suivante (410). */
    public function revokeFromSurgicalHub(User $actor, User $target): MedVueAccountLink
    {
        $link = $this->links->findActiveForUser($target);
        if ($link === null) {
            throw new MedVueLinkException(MedVueLinkException::NOT_LINKED);
        }

        $link->revoke($actor, MedVueLinkRevocationSource::SURGICALHUB, new \DateTimeImmutable());
        $this->audit->medvueUnlinked($actor, $target, $link->getMedvueLinkId(), MedVueLinkRevocationSource::SURGICALHUB->value);
        $this->em->flush();

        return $link;
    }

    /**
     * Révocation demandée par MedVue (appel machine). Idempotente : true si une liaison active a
     * été révoquée, false si elle l'était déjà. L'acteur d'audit est le titulaire (voir UserAuditService).
     */
    public function revokeFromMedVue(MedVueAccountLink $link): bool
    {
        if (!$link->isActive()) {
            return false;
        }

        $owner = $link->getUser();
        $link->revoke(null, MedVueLinkRevocationSource::MEDVUE, new \DateTimeImmutable());
        $this->audit->medvueUnlinked($owner, $owner, $link->getMedvueLinkId(), MedVueLinkRevocationSource::MEDVUE->value);
        $this->em->flush();

        return true;
    }

    private function failed(User $actor, User $target, string $reason): void
    {
        $this->audit->medvueLinkFailed($actor, $target, $reason);
        $this->em->flush();
    }

    public static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
