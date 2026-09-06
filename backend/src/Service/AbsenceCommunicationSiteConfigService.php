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
 *
 * Revue post-déploiement : cette config ne porte plus que des réglages de comportement
 * (activé/désactivé + délai) — les coordonnées "gestion du bloc" (To/CC) vivent désormais
 * sur `Hospital` (`blockManagementContactEmail`/`blockManagementContactCc`), gérées par
 * `SiteController`, jamais ici.
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
     * les clés présentes dans `$patch` sont appliquées. La validation porte sur l'état
     * RÉSULTANT après application du patch, jamais seulement sur les champs littéralement
     * envoyés — un patch qui ne touche que `blockManagementDelayDays` reste invalide si
     * l'établissement n'a toujours pas de contact "gestion du bloc" valide.
     *
     * Toggle OFF (`notifyBlockManagementEnabled = false`) ne touche jamais aux coordonnées de
     * l'établissement (elles ne sont même plus portées ici) ni au délai déjà configuré —
     * décision actée, permet un ré-enable sans reconfiguration.
     *
     * Garde-fou technique (§ revue post-déploiement) : active `notifyBlockManagementEnabled`
     * exige que l'établissement possède déjà un contact valide — jamais le parcours normal
     * (le frontend doit empêcher l'envoi de ce patch tant que ce n'est pas le cas, voir
     * `AbsenceCommunicationSettings.tsx`), seulement un filet de sécurité serveur.
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
        if (array_key_exists('blockManagementDelayDays', $patch)) {
            $raw = $patch['blockManagementDelayDays'];
            $config->setBlockManagementDelayDays($raw !== null ? (int) $raw : null);
        }

        $this->validate($site, $config);
        $this->em->flush();

        return $config;
    }

    private function validate(Hospital $site, AbsenceCommunicationSiteConfig $config): void
    {
        if (!$config->isNotifyBlockManagementEnabled()) {
            return;
        }

        $to = $site->getBlockManagementContactEmail();
        if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new BadRequestHttpException("L'établissement doit avoir une adresse de gestion du bloc valide (fiche établissement) avant d'activer cette fonctionnalité.");
        }

        $delay = $config->getBlockManagementDelayDays();
        if ($delay === null) {
            throw new BadRequestHttpException('blockManagementDelayDays est requis quand la gestion du bloc est activée.');
        }
        if ($delay < 0 || $delay > 365) {
            throw new BadRequestHttpException('blockManagementDelayDays doit être un entier compris entre 0 et 365.');
        }
    }
}
