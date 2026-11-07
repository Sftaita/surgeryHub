<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-183 — interventions sans firme. Une intervention (InterventionType, catalogue global)
 * existe indépendamment des firmes ; son rattachement à une firme est une prestation
 * (FirmServiceOffering firme × type). Rien n'est stocké pour dire « sans firme » : tout est
 * calculé ici à partir des rattachements réels, pour ne jamais se désynchroniser.
 *
 *  - Rattachement EFFECTIF : prestation active d'une firme active.
 *  - « Interventions sans firme » (Catalogue › Prestations) : type actif sans aucun
 *    rattachement effectif.
 *  - Sélectionnable « Aucune firme » à l'encodage : type actif sans rattachement effectif,
 *    OU explicitement autorisé sans firme par le manager (D-178, InterventionType::
 *    $allowedWithoutFirm, jamais modifié automatiquement par un rattachement).
 *
 * Jamais de firme fictive, jamais de prestation sans firme ou à tarif vide : retirer un
 * rattachement le désactive (historique, tarifs et facturation intacts), le rétablir le
 * réactive, sans jamais dupliquer l'intervention ni la prestation.
 */
final class InterventionTypeFirmLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActiveFirmResolver $firmResolver,
    ) {}

    /**
     * Nombre de rattachements effectifs par type, en une seule requête groupée. Un type
     * absent de la map n'en a aucun.
     *
     * @return array<int, int>
     */
    public function effectiveLinkCounts(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(o.interventionType) AS typeId', 'COUNT(DISTINCT f.id) AS cnt')
            ->from(FirmServiceOffering::class, 'o')
            ->innerJoin('o.firm', 'f')
            ->andWhere('o.active = true')
            ->andWhere('f.active = true')
            ->groupBy('o.interventionType')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r['typeId']] = (int) $r['cnt'];
        }

        return $counts;
    }

    public function effectiveLinkCount(InterventionType $type): int
    {
        if ($type->getId() === null) {
            return 0;
        }

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT f.id)')
            ->from(FirmServiceOffering::class, 'o')
            ->innerJoin('o.firm', 'f')
            ->andWhere('o.interventionType = :t')->setParameter('t', $type)
            ->andWhere('o.active = true')
            ->andWhere('f.active = true')
            ->getQuery()->getSingleScalarResult();
    }

    public function hasEffectiveLink(InterventionType $type): bool
    {
        return $this->effectiveLinkCount($type) > 0;
    }

    /** Sélectionnable « Aucune firme » à l'encodage (création, correction, résolution d'une demande). */
    public function isSelectableWithoutFirm(InterventionType $type): bool
    {
        return $type->isActive() && ($type->isAllowedWithoutFirm() || !$this->hasEffectiveLink($type));
    }

    /** Même règle pour un type dont le nombre de rattachements effectifs est déjà connu (listes). */
    public static function selectableWithoutFirm(InterventionType $type, int $effectiveLinks): bool
    {
        return $type->isActive() && ($type->isAllowedWithoutFirm() || $effectiveLinks === 0);
    }

    /**
     * Même règle, en condition DQL sur l'alias `$typeAlias` d'un InterventionType (requêtes
     * agrégées, ex. suggestions d'encodage).
     */
    public function andWhereSelectableWithoutFirm(QueryBuilder $qb, string $typeAlias): QueryBuilder
    {
        $linked = $this->em->createQueryBuilder()
            ->select('1')
            ->from(FirmServiceOffering::class, 'swf_o')
            ->innerJoin('swf_o.firm', 'swf_f')
            ->andWhere(sprintf('swf_o.interventionType = %s', $typeAlias))
            ->andWhere('swf_o.active = true')
            ->andWhere('swf_f.active = true');

        return $qb
            ->andWhere(sprintf('%s.active = true', $typeAlias))
            ->andWhere(sprintf('(%s.allowedWithoutFirm = true OR NOT EXISTS (%s))', $typeAlias, $linked->getDQL()));
    }

    /**
     * Rattache le type à une firme active : crée la prestation, ou réactive celle qui avait
     * été retirée (jamais de doublon — contrainte unique firme × type). Paramètres de la
     * prestation (forfait applicable, délégué, matériel, choix) : ceux d'une prestation
     * créée depuis la firme (valeurs par défaut), ou ceux conservés à la réactivation.
     *
     * @return array{offering: FirmServiceOffering, reactivated: bool}
     */
    public function link(InterventionType $type, int $firmId): array
    {
        if ($type->getMergedInto() !== null || !$type->isActive()) {
            throw new UnprocessableEntityHttpException('Cette intervention est désactivée : réactivez-la avant de la rattacher à une firme.');
        }
        $firm = $this->firmResolver->resolveActive($firmId);

        $existing = $this->em->getRepository(FirmServiceOffering::class)->findOneBy(['firm' => $firm, 'interventionType' => $type]);
        if ($existing instanceof FirmServiceOffering) {
            if ($existing->isActive()) {
                throw new ConflictHttpException(sprintf('« %s » est déjà rattachée à %s.', $type->getLabel(), $firm->getName()));
            }
            $existing->setActive(true);
            $this->em->flush();

            return ['offering' => $existing, 'reactivated' => true];
        }

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $offering->setActive(true);
        $this->em->persist($offering);
        $this->em->flush();

        return ['offering' => $offering, 'reactivated' => false];
    }

    /**
     * Retire le rattachement : la prestation est désactivée, jamais supprimée (tarifs,
     * encodages et facturation historiques intacts). L'intervention globale reste.
     */
    public function unlink(InterventionType $type, int $offeringId): FirmServiceOffering
    {
        $offering = $this->em->find(FirmServiceOffering::class, $offeringId);
        if (!$offering instanceof FirmServiceOffering || $offering->getInterventionType()?->getId() !== $type->getId()) {
            throw new UnprocessableEntityHttpException('Ce rattachement n\'appartient pas à cette intervention.');
        }
        if ($offering->isActive()) {
            $offering->setActive(false);
            $this->em->flush();
        }

        return $offering;
    }
}
