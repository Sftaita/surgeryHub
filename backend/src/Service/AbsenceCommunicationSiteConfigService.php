<?php

namespace App\Service;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;

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
}
