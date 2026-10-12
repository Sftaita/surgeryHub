<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Exception\CatalogueRequestException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-146 bis — construction des entités de référentiel (firme, type d'intervention,
 * matériel, prestation firme × type) lors de la RÉSOLUTION d'une demande catalogue, dans
 * la transaction de l'appelant (persist, jamais flush) : la résolution reste atomique.
 *
 * Mêmes règles que les écrans catalogue existants (FirmController, InterventionTypeController,
 * MaterialCatalogController, FirmServiceOfferingController) — nom/code/libellé obligatoires,
 * unicité nom de firme / code de type / (firme, prestation) / (firme, référence) — plus un
 * contrôle de nom de firme insensible à la casse (une firme « smith & nephew » ne doit pas
 * doubler « Smith & Nephew »). Aucun tarif n'est jamais créé ici : un matériel naît
 * `UNSPECIFIED` (non décidé), une prestation n'existe qu'avec un `feeApplicable` choisi
 * explicitement par le manager.
 */
final class CatalogueFactory
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function normalizeName(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }

    public function findFirmByName(string $name): ?Firm
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        return $this->em->getRepository(Firm::class)->createQueryBuilder('f')
            ->where('LOWER(f.name) = :name')
            ->setParameter('name', mb_strtolower($normalized))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @throws CatalogueRequestException 422 nom vide / 409 firme déjà existante */
    public function createFirm(string $name): Firm
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_REQUIRED, 'Le nom de la nouvelle firme est obligatoire.');
        }

        $existing = $this->findFirmByName($normalized);
        if ($existing !== null) {
            throw new CatalogueRequestException(409, CatalogueRequestException::FIRM_ALREADY_EXISTS, sprintf(
                'La firme « %s » existe déjà : sélectionnez-la plutôt que d\'en créer une nouvelle.',
                $existing->getName(),
            ), ['existingFirm' => ['id' => $existing->getId(), 'name' => $existing->getName(), 'active' => $existing->isActive()]]);
        }

        $firm = new Firm();
        $firm->setName(mb_substr($normalized, 0, 255));
        $firm->setActive(true);
        $this->em->persist($firm);

        return $firm;
    }

    /** @throws CatalogueRequestException 422 code/libellé vides / 409 code déjà utilisé */
    public function createInterventionType(string $code, string $label): InterventionType
    {
        $code = self::normalizeName($code);
        $label = self::normalizeName($label);
        if ($code === '' || $label === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::INTERVENTION_TYPE_REQUIRED, 'Le code et le libellé de la nouvelle intervention sont obligatoires.');
        }

        $existing = $this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code]);
        if ($existing instanceof InterventionType) {
            throw new CatalogueRequestException(409, CatalogueRequestException::INTERVENTION_TYPE_CODE_EXISTS, sprintf(
                'Le code « %s » est déjà utilisé par « %s » : sélectionnez cette intervention existante ou choisissez un autre code.',
                $existing->getCode(),
                $existing->getLabel(),
            ), ['existingInterventionType' => ['id' => $existing->getId(), 'code' => $existing->getCode(), 'label' => $existing->getLabel(), 'active' => $existing->isActive()]]);
        }

        $type = new InterventionType();
        $type->setCode($code);
        $type->setLabel($label);
        $type->setActive(true);
        $this->em->persist($type);

        return $type;
    }

    /**
     * Nouveau matériel : toujours `UNSPECIFIED` (valeur par défaut de l'entité) — aucune
     * décision de facturation ni aucun tarif n'est jamais pris à la place du manager.
     */
    public function createMaterialItem(Firm $firm, string $label, string $unit, ?string $referenceCode, bool $isImplant): MaterialItem
    {
        $label = self::normalizeName($label);
        $unit = self::normalizeName($unit);
        if ($label === '' || $unit === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::LABEL_REQUIRED, 'Le nom et l\'unité du matériel sont obligatoires.');
        }

        $item = new MaterialItem();
        $item->setFirm($firm);
        $item->setLabel($label);
        $item->setUnit($unit);
        $item->setReferenceCode(self::normalizeName($referenceCode));
        $item->setIsImplant($isImplant);
        $this->em->persist($item);

        return $item;
    }

    /**
     * Prestation firme × type créée explicitement par le manager : `feeApplicable` est
     * TOUJOURS fourni par l'appelant (jamais « Pas de forfait » par défaut) ; aucune
     * règle tarifaire n'est créée — le montant se configure dans Catalogue > Prestations.
     * La politique du délégué garde les valeurs par défaut de l'entité (question non
     * posée) : elle se configure elle aussi explicitement dans le catalogue.
     */
    public function createOffering(Firm $firm, InterventionType $type, bool $feeApplicable): FirmServiceOffering
    {
        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $offering->setActive(true);
        $offering->setFeeApplicable($feeApplicable);
        $this->em->persist($offering);

        return $offering;
    }

    public function findOffering(Firm $firm, InterventionType $type): ?FirmServiceOffering
    {
        if ($firm->getId() === null || $type->getId() === null) {
            return null;
        }

        return $this->em->getRepository(FirmServiceOffering::class)->findOneBy(['firm' => $firm, 'interventionType' => $type]);
    }
}
