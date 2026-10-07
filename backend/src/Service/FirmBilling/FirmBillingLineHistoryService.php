<?php

namespace App\Service\FirmBilling;

use App\Entity\FirmBillingLineEvent;
use App\Entity\FirmInvoiceLine;
use App\Entity\MaterialLine;
use App\Entity\MissionIntervention;
use App\Enum\FinancialDocumentType;
use App\Enum\FirmBillingLineEventType;
use App\Enum\InvoiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-134 — historique d'une ligne de facturation firme : lu EXCLUSIVEMENT dans le journal
 * append-only FirmBillingLineEvent (jamais reconstitué depuis les FirmInvoiceLine, qui
 * disparaissent à l'annulation d'une facture), plus l'état documentaire COURANT, servi à
 * part : une ligne libre peut avoir un passé.
 */
final class FirmBillingLineHistoryService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return array<string, mixed> */
    public function history(string $sourceType, int $sourceId): array
    {
        /** @var FirmBillingLineEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(FirmBillingLineEvent::class, 'e')
            ->where('e.sourceType = :type')
            ->andWhere('e.sourceId = :id')
            ->setParameter('type', $sourceType)
            ->setParameter('id', $sourceId)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return [
            'sourceKey' => $sourceType . ':' . $sourceId,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'label' => $this->sourceLabel($sourceType, $sourceId),
            'currentInvoice' => $this->currentInvoice($sourceType, $sourceId),
            'history' => array_map($this->serialize(...), $events),
        ];
    }

    private function serialize(FirmBillingLineEvent $e): array
    {
        $number = $e->getInvoiceNumberSnapshot() ?? ($e->getInvoiceId() !== null ? '#' . $e->getInvoiceId() : null);
        $description = match ($e->getEventType()) {
            FirmBillingLineEventType::INVOICE_GENERATED => sprintf('Facture %s générée', $number),
            FirmBillingLineEventType::INVOICE_SENT => sprintf('Facture %s envoyée', $number),
            FirmBillingLineEventType::PAYMENT_RECORDED => sprintf(
                'Paiement enregistré sur la facture %s (%s %s)%s',
                $number,
                number_format((float) ($e->getDetails()['paymentAmount'] ?? 0), 2, ',', ' '),
                ($e->getDetails()['paymentCurrency'] ?? 'EUR') === 'EUR' ? '€' : ($e->getDetails()['paymentCurrency'] ?? ''),
                !empty($e->getDetails()['fullyPaid']) ? ' — facture soldée' : '',
            ),
            FirmBillingLineEventType::INVOICE_PAID => sprintf('Facture %s payée', $number),
            FirmBillingLineEventType::INVOICE_CANCELLED => sprintf('Facture %s annulée — ligne de nouveau libre', $number),
            FirmBillingLineEventType::ADDED_TO_DRAFT => sprintf('Ajoutée au brouillon %s', $number),
            FirmBillingLineEventType::REMOVED_FROM_DRAFT => sprintf('Retirée du brouillon %s', $number),
            FirmBillingLineEventType::MOVED_TO_DRAFT => sprintf('Déplacée vers le brouillon %s', $number),
        };

        return [
            'id' => $e->getId(),
            'eventType' => $e->getEventType()->value,
            'label' => $e->getEventType()->label(),
            'description' => $description,
            'occurredAt' => $e->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'actorName' => $e->getActorNameSnapshot(),
            'firmName' => $e->getFirmNameSnapshot(),
            'invoice' => $e->getInvoiceId() !== null ? [
                'id' => $e->getInvoiceId(),
                'number' => $e->getInvoiceNumberSnapshot(),
                'statusAtEvent' => $e->getInvoiceStatusSnapshot(),
            ] : null,
            'amount' => $e->getAmountSnapshot(),
            'currency' => $e->getCurrencySnapshot(),
        ];
    }

    /** Appartenance actuelle à un document STANDARD (une ligne snapshot existe). */
    private function currentInvoice(string $sourceType, int $sourceId): ?array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('l', 'i', 'f')
            ->from(FirmInvoiceLine::class, 'l')
            ->join('l.invoice', 'i')
            ->join('i.firm', 'f')
            ->where('i.documentType = :standard')
            ->andWhere('i.status != :cancelled')
            ->setParameter('standard', FinancialDocumentType::STANDARD)
            ->setParameter('cancelled', InvoiceStatus::CANCELLED)
            ->setMaxResults(1);
        $qb->andWhere($sourceType === FirmBillingLineEvent::SOURCE_MATERIAL ? 'IDENTITY(l.materialLine) = :id' : 'IDENTITY(l.missionIntervention) = :id')
            ->setParameter('id', $sourceId);

        /** @var FirmInvoiceLine|null $line */
        $line = $qb->getQuery()->getOneOrNullResult();
        if ($line === null) {
            return null;
        }
        $invoice = $line->getInvoice();

        return [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus()->value,
            'firmName' => $invoice->getFirm()?->getName(),
            'editable' => $invoice->getStatus() === InvoiceStatus::DRAFT,
        ];
    }

    private function sourceLabel(string $sourceType, int $sourceId): ?string
    {
        if ($sourceType === FirmBillingLineEvent::SOURCE_MATERIAL) {
            return $this->em->find(MaterialLine::class, $sourceId)?->getItem()?->getLabel();
        }

        return $this->em->find(MissionIntervention::class, $sourceId)?->getLabel();
    }
}
