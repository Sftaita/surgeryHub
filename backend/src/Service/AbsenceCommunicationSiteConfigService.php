<?php

namespace App\Service;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Find-or-create par site (Lot A/D-114) — une ligne AbsenceCommunicationSiteConfig par site,
 * créée à la demande plutôt que pré-seedée (un site sans ligne équivaut à toutes les
 * fonctions désactivées, valeurs par défaut de l'entité).
 */
class AbsenceCommunicationSiteConfigService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findOrCreate(Hospital $site): AbsenceCommunicationSiteConfig
    {
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        if ($config !== null) {
            return $config;
        }

        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    /** @return AbsenceCommunicationSiteConfig[] une ligne par site déjà configuré (pas de virtuel pour un site sans ligne — voir le controller pour la vue combinée). */
    public function listConfigured(): array
    {
        return $this->em->createQueryBuilder()
            ->select('c')
            ->from(AbsenceCommunicationSiteConfig::class, 'c')
            ->getQuery()
            ->getResult();
    }

    public function setNotifyColleaguesEnabled(Hospital $site, bool $enabled): AbsenceCommunicationSiteConfig
    {
        $config = $this->findOrCreate($site);
        $config->setNotifyColleaguesEnabled($enabled);
        $this->em->flush();

        return $config;
    }

    /**
     * Communication des absences chirurgiens — Lot B (D-114). Mise à jour partielle : seules
     * les clés présentes dans `$patch` sont appliquées (permet de ne toucher que « Libération
     * de salle » ou que « Gestion du bloc » indépendamment). La validation porte sur l'état
     * RÉSULTANT après application du patch, jamais seulement sur les champs littéralement
     * envoyés — un patch qui ne touche que `blockManagementEmailCc` reste valide si
     * `blockManagementEmailTo`/`blockManagementDelayDays` étaient déjà correctement définis
     * auparavant.
     *
     * Toggle OFF (`notifyBlockManagementEnabled = false`) conserve les valeurs déjà
     * configurées (To/CC/délai) sans y toucher — décision actée, permet un ré-enable sans
     * ressaisie.
     */
    public function updateSettings(Hospital $site, array $patch): AbsenceCommunicationSiteConfig
    {
        $config = $this->findOrCreate($site);

        if (array_key_exists('notifyColleaguesEnabled', $patch)) {
            $config->setNotifyColleaguesEnabled((bool) $patch['notifyColleaguesEnabled']);
        }
        if (array_key_exists('notifyBlockManagementEnabled', $patch)) {
            $config->setNotifyBlockManagementEnabled((bool) $patch['notifyBlockManagementEnabled']);
        }
        if (array_key_exists('blockManagementEmailTo', $patch)) {
            $raw = $patch['blockManagementEmailTo'];
            $config->setBlockManagementEmailTo($raw !== null && trim((string) $raw) !== '' ? trim((string) $raw) : null);
        }
        if (array_key_exists('blockManagementEmailCc', $patch)) {
            $config->setBlockManagementEmailCc(self::normalizeCc(
                is_array($patch['blockManagementEmailCc']) ? $patch['blockManagementEmailCc'] : [],
                $config->getBlockManagementEmailTo(),
            ));
        }
        if (array_key_exists('blockManagementDelayDays', $patch)) {
            $raw = $patch['blockManagementDelayDays'];
            $config->setBlockManagementDelayDays($raw !== null ? (int) $raw : null);
        }

        $this->validate($config);
        $this->em->flush();

        return $config;
    }

    private function validate(AbsenceCommunicationSiteConfig $config): void
    {
        foreach ($config->getBlockManagementEmailCc() as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL) === false) {
                throw new BadRequestHttpException(sprintf('Adresse CC invalide : %s', $cc));
            }
        }

        if (!$config->isNotifyBlockManagementEnabled()) {
            return;
        }

        $to = $config->getBlockManagementEmailTo();
        if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new BadRequestHttpException('blockManagementEmailTo (email valide) est requis quand la gestion du bloc est activée.');
        }

        $delay = $config->getBlockManagementDelayDays();
        if ($delay === null) {
            throw new BadRequestHttpException('blockManagementDelayDays est requis quand la gestion du bloc est activée.');
        }
        if ($delay < 0 || $delay > 365) {
            throw new BadRequestHttpException('blockManagementDelayDays doit être un entier compris entre 0 et 365.');
        }
    }

    /**
     * Trim, dédoublonnage insensible à la casse, retire l'adresse principale si elle a été
     * dupliquée dans les CC — jamais un rejet pour une variante triviale (§5), une adresse
     * réellement invalide reste rejetée par validate().
     *
     * @param list<string> $rawList
     * @return list<string>
     */
    private static function normalizeCc(array $rawList, ?string $primary): array
    {
        $seen = $primary !== null ? [mb_strtolower(trim($primary))] : [];
        $result = [];

        foreach ($rawList as $raw) {
            $address = trim((string) $raw);
            if ($address === '') {
                continue;
            }
            $key = mb_strtolower($address);
            if (in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $result[] = $address;
        }

        return $result;
    }
}
