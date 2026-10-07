<?php

namespace App\Service\FirmBilling;

use App\Entity\FirmBillingLineEvent;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\User;
use App\Enum\FinancialDocumentType;
use App\Enum\FirmBillingLineEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-134 — seul point d'écriture du journal append-only FirmBillingLineEvent. Appelé par
 * les services qui font réellement changer le parcours documentaire d'une ligne
 * (FirmInvoiceService, DocumentPaymentService), dans leur transaction : l'événement est
 * persisté puis flushé par l'appelant, jamais ailleurs.
 *
 * Une facture ne journalise que ses lignes rattachées à une source métier (intervention ou
 * matériel) ; les lignes legacy sans source n'ont pas d'historique de ligne. Seuls les
 * documents STANDARD sont journalisés (les notes de crédit/débit relèvent du mécanisme de
 * correction existant).
 */
final class FirmBillingLineEventRecorder
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Un événement par ligne de la facture, avec les snapshots du document au moment du fait. */
    public function recordForInvoice(FirmInvoice $invoice, FirmBillingLineEventType $type, ?User $actor, ?array $details = null): void
    {
        if ($invoice->getDocumentType() !== FinancialDocumentType::STANDARD) {
            return;
        }

        foreach ($invoice->getLines() as $line) {
            $event = $this->eventFor($line, $invoice, $type, $actor, $details);
            if ($event !== null) {
                $this->em->persist($event);
            }
        }
    }

    /** Un événement pour une seule ligne (mouvements de brouillon). */
    public function recordForLine(FirmInvoiceLine $line, FirmInvoice $invoice, FirmBillingLineEventType $type, ?User $actor, ?array $details = null): void
    {
        $event = $this->eventFor($line, $invoice, $type, $actor, $details);
        if ($event !== null) {
            $this->em->persist($event);
        }
    }

    private function eventFor(FirmInvoiceLine $line, FirmInvoice $invoice, FirmBillingLineEventType $type, ?User $actor, ?array $details): ?FirmBillingLineEvent
    {
        $financialLine = $line->getFinancialCalculationLine();
        $material = $line->getMaterialLine() ?? $financialLine?->getMaterialLine();
        $intervention = $material === null ? ($line->getMissionIntervention() ?? $financialLine?->getMissionIntervention()) : null;
        $mission = $line->getMission() ?? $financialLine?->getFinancialCalculation()?->getMission();

        if (($material === null && $intervention === null) || $mission === null) {
            return null; // ligne legacy sans source métier : pas d'historique de ligne
        }

        $firm = $invoice->getFirm();

        return new FirmBillingLineEvent(
            sourceType: $material !== null ? FirmBillingLineEvent::SOURCE_MATERIAL : FirmBillingLineEvent::SOURCE_INTERVENTION,
            sourceId: (int) ($material?->getId() ?? $intervention->getId()),
            missionId: (int) $mission->getId(),
            eventType: $type,
            financialCalculationLineId: $financialLine?->getId(),
            firmId: $firm?->getId(),
            firmNameSnapshot: $firm?->getName(),
            invoiceId: $invoice->getId(),
            invoiceNumberSnapshot: $invoice->getNumber(),
            invoiceStatusSnapshot: $invoice->getStatus()->value,
            amountSnapshot: $line->getTotalAmount(),
            currencySnapshot: $invoice->getCurrency(),
            actorId: $actor?->getId(),
            actorNameSnapshot: $actor !== null ? $this->displayName($actor) : null,
            details: $details,
        );
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
