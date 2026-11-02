<?php

namespace App\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\MissionEncodingComment;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\EncodingConcurrencyException;
use App\Exception\PrestationConflictException;
use App\Message\MissionLifecycleChangedMessage;
use App\Service\Prestation\PrestationGroupResolver;
use App\Service\Prestation\PrestationGrouping;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 7 (D-070) — cycle de vie de l'encodage :
 *
 *   ASSIGNED/IN_PROGRESS ──start──▶ ENCODING_IN_PROGRESS ──complete (commentaire requis si BLOCK et 0 matériel)──▶ SUBMITTED
 *                                                                          │
 *                                                          ┌───validate───┤───reject (commentaire requis)──┐
 *                                                          ▼                                               ▼
 *                                                      VALIDATED                                ENCODING_IN_PROGRESS
 *                                                          │
 *                                                      reopen (motif requis, D-148)
 *                                                          ▼
 *                                                  ENCODING_IN_PROGRESS
 *
 * Chaque transition : vérifie l'état de départ (ConflictHttpException sinon, même idiome
 * que MissionService::approveDeclared()/rejectDeclared()), mute le statut, écrit l'AuditEvent,
 * flush (R-05), puis dispatch MissionLifecycleChangedMessage — géré par le handler existant
 * via son branchement "unhandled changeType — forward-compatible skip" (aucune notification
 * réelle branchée dans ce lot, volontairement).
 *
 * Pas de verrou pessimiste ici (contrairement à claim()/start() D-064) : ce sont des actions
 * humaines explicites (clic manager/instrumentiste), pas une tâche planifiée qui se
 * chevauche — le risque de double-transition concurrente est faible, et la vérification de
 * l'état de départ protège déjà contre un double traitement silencieux (la seconde tentative
 * trouve un statut qui ne correspond plus et échoue proprement en 409).
 */
final class MissionEncodingWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly AuditService $audit,
        private readonly MessageBusInterface $bus,
        private readonly EncodingCompletenessService $completeness,
        private readonly EncodingContentCompletenessService $contentCompleteness,
        private readonly PrestationGroupResolver $prestationGroups,
        // D-171 — optional only so plain unit tests can build the service; always autowired.
        private readonly ?EncodingTakeoverService $takeovers = null,
        // D-171 — every transition runs under the mission lock (serialized with encoding writes).
        private readonly ?MissionEncodingLock $encodingLock = null,
    ) {}

    /**
     * @template T
     * @param callable(): T $transition
     * @return T
     */
    private function locked(Mission $mission, callable $transition): mixed
    {
        return $this->encodingLock !== null ? $this->encodingLock->run($mission, $transition) : $transition();
    }

    /** ASSIGNED|IN_PROGRESS → ENCODING_IN_PROGRESS. */
    public function start(Mission $mission, User $actor): Mission
    {
        return $this->locked($mission, fn (): Mission => $this->startTransition($mission, $actor));
    }

    private function startTransition(Mission $mission, User $actor): Mission
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $actor);

        if (!in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
            throw new ConflictHttpException('Mission must be ASSIGNED or IN_PROGRESS to start encoding');
        }

        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $mission->setEncodingStartedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_STARTED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_STARTED, $actor, $payload);

        return $mission;
    }

    /**
     * DECLARED|ASSIGNED|IN_PROGRESS|ENCODING_IN_PROGRESS|SUBMITTED → SUBMITTED.
     * "Mon encodage est terminé" — l'instrumentiste peut la déclarer sans être passé par
     * start() explicitement (DECLARED/ASSIGNED/IN_PROGRESS acceptés directement, comportement
     * préexistant conservé à l'identique) ; SUBMITTED→SUBMITTED reste idempotent (liberté
     * instrumentiste déjà établie avant ce lot).
     *
     * C'est l'implémentation réelle derrière POST /api/missions/{id}/submit (legacy, inchangé
     * pour compatibilité) et POST /api/missions/{id}/encoding/complete (Lot 7) — un seul et
     * même point d'entrée métier, jamais dupliqué.
     *
     * $comment : requis uniquement pour une mission MissionType::BLOCK n'ayant aucune ligne
     * de matériel *active* encodée (toutes interventions confondues) — décrit alors ce qui a
     * été réalisé en l'absence de matériel. Une mission MissionType::CONSULTATION n'attend
     * jamais de matériel : jamais de commentaire exigé pour ce type, quel que soit le nombre
     * de lignes. Le total de lignes est toujours recalculé côté serveur
     * (countActiveMaterialLines(), jamais un flag envoyé par le client) : source de
     * vérité unique, jamais de confiance aveugle dans ce que déclare le frontend.
     *
     * $submittedWithoutMaterial est figé à CHAQUE appel (jamais dérivé après coup) : une
     * resoumission ultérieure avec du matériel repasse ce flag à false — le commentaire
     * précédent reste en base pour traçabilité mais n'est plus la justification de la
     * soumission courante (voir Mission::$noMaterialComment).
     */
    public function complete(Mission $mission, User $actor, ?string $comment = null): Mission
    {
        return $this->locked($mission, function () use ($mission, $actor, $comment): Mission {
            $this->assertNotInPrestation($mission);

            return $this->completeTransition($mission, $actor, $comment, true);
        });
    }

    /** $requireHours = false only for a manager takeover (D-171): missing hours stay missing. */
    private function completeTransition(Mission $mission, User $actor, ?string $comment, bool $requireHours): Mission
    {
        $this->assertCompletable($mission, $actor, $comment, $requireHours);
        $payload = $this->applyComplete($mission, $actor, $comment);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_COMPLETED, $actor, $payload);

        return $mission;
    }

    /**
     * D-156 — une mission d'une prestation multi-salles se soumet avec ses missions sœurs
     * (completePrestation), jamais seule : aucune mission soumise et l'autre oubliée. Les
     * décisions du manager (valider, renvoyer en correction, rouvrir, « Terminer et valider »)
     * portent elles aussi sur toute la prestation (complément D-156, *Prestation() ci-dessous).
     */
    private function assertNotInPrestation(Mission $mission): void
    {
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_REQUIRES_COMMON_SUBMIT,
                'Cette mission fait partie d\'une prestation multi-salles : soumettez la prestation complète.',
                ['missionId' => $mission->getId(), 'prestationMissionIds' => PrestationGrouping::ids($group)],
            );
        }
    }

    /**
     * D-156 — soumission commune d'une prestation regroupée multi-salles : TOUTES les
     * missions à soumettre sont contrôlées (mêmes règles que complete(), aucune exception)
     * avant qu'aucune ne change de statut, puis toutes passent SUBMITTED dans la même
     * transaction — jamais une mission soumise et l'autre oubliée. Idempotent : une mission
     * déjà SUBMITTED est laissée telle quelle. Complément D-156 : le matériel est apprécié sur
     * TOUTE la prestation — la justification « sans matériel » unique n'est exigée (et posée sur
     * chaque mission) que si aucune mission ne porte de matériel actif ; sinon aucune mission
     * n'est marquée « sans matériel ». Les messages de cycle de vie partent après le commit, un
     * par mission (traçabilité individuelle), une seule notification par prestation.
     *
     * @param \Closure(): list<Mission> $lockAndResolve résout la prestation SOUS VERROU, dans
     *        cette même transaction (contrôles de composition/empreinte de l'appelant inclus)
     * @return list<Mission> les missions effectivement soumises par cet appel
     */
    public function completePrestation(\Closure $lockAndResolve, User $actor, ?string $noMaterialComment, string $prestationKey): array
    {
        $submitted = $this->em->wrapInTransaction(function () use ($lockAndResolve, $actor, $noMaterialComment, $prestationKey): array {
            $missions = $lockAndResolve();
            $toSubmit = array_values(array_filter($missions, static fn (Mission $m) => $m->getStatus() !== MissionStatus::SUBMITTED));
            // Complément D-156 — matériel apprécié sur TOUTE la prestation : une seule
            // justification, exigée seulement si aucune mission ne porte de matériel actif.
            $withoutMaterial = $this->prestationHasNoActiveMaterial($missions);
            $justification = $withoutMaterial ? $noMaterialComment : null;
            foreach ($toSubmit as $mission) {
                $this->assertCompletable($mission, $actor, $justification, true, $withoutMaterial);
            }

            $applied = [];
            foreach ($toSubmit as $mission) {
                $payload = $this->applyComplete($mission, $actor, $justification, self::prestationPayload($missions), $withoutMaterial);
                $applied[] = [$mission, $payload];
            }
            $this->em->flush();

            return $applied;
        });

        $events = array_map(static fn (array $pair) => [$pair[0], MissionChangeType::ENCODING_COMPLETED, $pair[1]], $submitted);
        foreach (self::notifyOncePerPrestation($events) as [$mission, $changeType, $payload]) {
            $this->dispatch($mission, $changeType, $actor, $payload);
        }

        return array_map(static fn (array $pair) => $pair[0], $submitted);
    }

    /** @param list<Mission> $missions */
    private function prestationHasNoActiveMaterial(array $missions): bool
    {
        foreach ($missions as $mission) {
            if ($this->countActiveMaterialLines($mission) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Complément D-156 — contexte d'une transition appliquée à toute une prestation (audit).
     *
     * @param list<Mission> $missions
     * @return array{prestationKey: string, prestationMissionIds: list<int>}
     */
    private static function prestationPayload(array $missions): array
    {
        return [
            'prestationKey' => PrestationGrouping::key($missions),
            'prestationMissionIds' => PrestationGrouping::ids($missions),
        ];
    }

    /**
     * Complément D-156 — une seule notification par prestation, jamais une par mission : chaque
     * message garde sa mission (traçabilité), mais seul celui de la plus petite mission
     * effectivement concernée, par type de notification, est notifié
     * (`prestationNotifiedMissionId`, lu par MissionLifecycleChangedMessageHandler).
     *
     * @param list<array{0: Mission, 1: MissionChangeType, 2: array<string,mixed>}> $events
     * @return list<array{0: Mission, 1: MissionChangeType, 2: array<string,mixed>}>
     */
    private static function notifyOncePerPrestation(array $events): array
    {
        $category = static fn (MissionChangeType $t): string => in_array($t, [MissionChangeType::ENCODING_REJECTED, MissionChangeType::ENCODING_REOPENED], true) ? 'correction' : $t->value;
        $first = [];
        foreach ($events as [$mission, $changeType, $payload]) {
            if (!isset($payload['prestationKey'])) {
                continue;
            }
            $c = $category($changeType);
            $first[$c] = min($first[$c] ?? PHP_INT_MAX, (int) $mission->getId());
        }

        return array_map(static function (array $event) use ($category, $first): array {
            [$mission, $changeType, $payload] = $event;
            if (isset($first[$category($changeType)])) {
                $payload['prestationNotifiedMissionId'] = $first[$category($changeType)];
            }

            return [$mission, $changeType, $payload];
        }, $events);
    }

    /**
     * Tous les contrôles de complete(), sans aucune écriture.
     *
     * $withoutMaterial (prestation multi-salles) : absence de matériel appréciée sur toute la
     * prestation ; null = sur la seule mission (mission seule).
     */
    private function assertCompletable(Mission $mission, User $actor, ?string $comment, bool $requireHours = true, ?bool $withoutMaterial = null): void
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $actor);

        if (!in_array($mission->getStatus(), [
            MissionStatus::DECLARED,
            MissionStatus::ASSIGNED,
            MissionStatus::IN_PROGRESS,
            MissionStatus::ENCODING_IN_PROGRESS,
            MissionStatus::SUBMITTED,
        ], true)) {
            throw new ConflictHttpException('Mission cannot be submitted from its current status');
        }

        // D-143 — heures réelles (début ET fin cohérents) obligatoires : jamais les horaires
        // planifiés, jamais une durée seule. Seule la transition est refusée, rien n'est perdu.
        if ($requireHours) {
            $this->completeness->assertSubmittable(
                $mission,
                'Il reste une petite étape avant de terminer : pensez à renseigner vos heures prestées (début et fin).',
            );
        }

        // D-146 bis — chaque intervention doit être complète (firme, réponse délégué,
        // système choisi, matériel compatible) : jamais une mission soumise puis bloquée
        // au calcul financier. Les demandes catalogue en attente ne bloquent pas la
        // soumission (le manager les traite pendant la phase SUBMITTED).
        $this->contentCompleteness->assertComplete(
            $mission,
            false,
            "Certaines interventions sont incomplètes : complétez-les avant de terminer l'encodage.",
        );

        $trimmedComment = $comment !== null ? trim($comment) : null;
        $requiresJustification = $mission->getType() === MissionType::BLOCK
            && ($withoutMaterial ?? $this->countActiveMaterialLines($mission) === 0);

        if ($requiresJustification && ($trimmedComment === null || $trimmedComment === '')) {
            throw new UnprocessableEntityHttpException($withoutMaterial !== null
                ? "Aucun matériel n'a été encodé pour cette prestation : décrivez les interventions réalisées avant de soumettre."
                : "Aucun matériel n'a été encodé pour cette mission : décrivez les interventions réalisées avant de clôturer.",
            );
        }
    }

    /**
     * Les écritures de complete() (statut, horodatage, justification, audit), sans flush.
     *
     * @param array<string,mixed> $extraPayload
     * @return array<string,mixed> payload de l'AuditEvent / du message
     */
    private function applyComplete(Mission $mission, User $actor, ?string $comment, array $extraPayload = [], ?bool $withoutMaterial = null): array
    {
        $trimmedComment = $comment !== null ? trim($comment) : null;
        $hasNoActiveMaterial = $withoutMaterial ?? $this->countActiveMaterialLines($mission) === 0;

        $mission->setSubmittedWithoutMaterial($hasNoActiveMaterial);
        if ($trimmedComment !== null && $trimmedComment !== '') {
            $mission->setNoMaterialComment($trimmedComment);
        }

        $mission->setStatus(MissionStatus::SUBMITTED);
        $mission->setSubmittedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor) + $extraPayload;
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_COMPLETED, $payload);

        return $payload;
    }

    /**
     * Compte les lignes de matériel réellement significatives pour décider si la mission
     * a "du matériel encodé". Ignore les lignes à quantité nulle ou négative (aucune
     * contrainte Assert\Positive côté DTO aujourd'hui — cf. MaterialLineCreateRequest/
     * MaterialLineUpdateRequest — donc quantity=0 est persistable en base et ne doit pas
     * compter comme du matériel réellement utilisé).
     *
     * MaterialLine n'a ni suppression logique (DELETE fait un vrai
     * $this->em->remove(), voir MaterialLineController), ni flag `active` propre à la
     * ligne (seul MaterialItem, le catalogue, en a un — qui ne doit jamais invalider
     * rétroactivement une ligne déjà utilisée), ni notion de version/superseded (contrairement
     * à PricingRule/InstrumentistRate, D-072) : ces trois filtres ne s'appliquent donc pas à
     * cette entité, volontairement pas simulés ici.
     */
    private function countActiveMaterialLines(Mission $mission): int
    {
        $count = 0;
        foreach ($mission->getMaterialLines() as $line) {
            if ((float) $line->getQuantity() > 0) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * D-171 — « Terminer et valider » : le manager clôt lui-même un encodage encore ouvert
     * (instrumentiste affectée ou activité déclarée sans instrumentiste) puis le valide.
     * Les deux transitions existantes s'enchaînent (complete() puis validate(), deux audits,
     * deux messages) ; tout contrôle de validate() est fait AVANT la première écriture :
     * jamais une mission laissée SUBMITTED à mi-chemin par un refus de validation.
     *
     * Concurrence : $expectedRevision est l'empreinte de l'encodage que le manager a sous les
     * yeux (EncodingTakeoverService::revision(), servie par le détail de mission). Sous le verrou
     * de la mission, une empreinte différente → 409 ENCODING_CONCURRENT_MODIFICATION, rien n'est
     * écrit ; absente → 428.
     *
     * Reprise (instrumentiste affectée, encodage non soumis) : confirmation explicite exigée
     * ($takeoverConfirmed), audit MISSION_ENCODING_TAKEN_OVER, puis l'instrumentiste est prévenue
     * (ENCODING_TAKEN_OVER). Ses données sont conservées telles quelles.
     *
     * Heures : jamais exigées ici. Interventions et matériel se valident indépendamment ; des
     * heures manquantes ne sont ni inventées ni validées — le fait
     * encodingValidatedWithoutHoursAt les laisse déclarables ensuite
     * (MissionEncodingCorrectionPolicy::hoursPendingAfterValidation()).
     */
    public function completeAndValidate(Mission $mission, User $actor, ?string $comment, ?string $expectedRevision, bool $takeoverConfirmed): Mission
    {
        if ($this->takeovers === null || $this->encodingLock === null) {
            throw new \LogicException('completeAndValidate() requires EncodingTakeoverService and MissionEncodingLock.');
        }

        // Complément D-156 — « Terminer et valider » porte sur toute la prestation multi-salles.
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            $this->completeAndValidatePrestation($mission, $group, $actor, $comment, $expectedRevision, $takeoverConfirmed);

            return $mission;
        }

        return $this->encodingLock->run($mission, function () use ($mission, $actor, $comment, $expectedRevision, $takeoverConfirmed): Mission {
            $blocked = MissionEncodingCorrectionPolicy::completeAndValidateBlockedReason($mission, Clock::get()->now());
            if ($blocked !== null) {
                throw new ConflictHttpException($blocked);
            }

            $expectedRevision = $expectedRevision !== null ? trim($expectedRevision) : '';
            if ($expectedRevision === '') {
                throw EncodingConcurrencyException::versionRequired();
            }
            $revision = $this->takeovers->revision($mission);
            if (!hash_equals($revision, $expectedRevision)) {
                throw EncodingConcurrencyException::modified(EncodingConcurrencyException::SUBJECT_ENCODING);
            }

            $takeover = MissionEncodingCorrectionPolicy::isTakeover($mission);
            $instrumentist = $mission->getInstrumentist();
            if ($takeover && !$takeoverConfirmed) {
                throw new UnprocessableEntityHttpException(sprintf(
                    "Confirmez la reprise de l'encodage de %s : il n'a pas été soumis par l'instrumentiste.",
                    $this->displayName($instrumentist),
                ));
            }

            $this->contentCompleteness->assertComplete(
                $mission,
                true,
                "Encodage incomplet : complétez les interventions et traitez les demandes en attente avant de valider.",
            );

            $hoursMissing = MissionEncodingCorrectionPolicy::hoursMissing($mission);
            $takeoverPayload = null;
            if ($takeover) {
                $takeoverPayload = array_merge($this->actorPayload($actor), [
                    'instrumentistId'    => $instrumentist?->getId(),
                    'instrumentistName'  => $this->displayName($instrumentist),
                    'fromStatus'         => $mission->getStatus()->value,
                    'revision'           => $revision,
                    'hoursMissing'       => $hoursMissing,
                    'interventionsCount' => $mission->getInterventions()->count(),
                    'materialLinesCount' => $mission->getMaterialLines()->count(),
                ]);
                $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_TAKEN_OVER, $takeoverPayload);
            }

            $this->completeTransition($mission, $actor, $comment, false);
            $this->validateTransition($mission, $actor, false);

            if ($hoursMissing) {
                $mission->setEncodingValidatedWithoutHoursAt(Clock::get()->now());
                $this->em->flush();
            }

            // Après la validation (flush fait) : jamais de notification pour une reprise avortée.
            if ($takeoverPayload !== null) {
                $this->dispatch($mission, MissionChangeType::ENCODING_TAKEN_OVER, $actor, $takeoverPayload);
            }

            return $mission;
        });
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return "l'instrumentiste";
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }

    /** SUBMITTED → VALIDATED (verrouille l'encodage — encodingLockedAt, lu par MissionEncodingGuard). */
    /**
     * $expectedRevision (D-171, optionnel) : empreinte de l'encodage relu par le manager ; une
     * écriture concurrente entre sa lecture et sa validation → 409, rien n'est validé.
     */
    public function validate(Mission $mission, User $actor, ?string $expectedRevision = null): Mission
    {
        // Complément D-156 — une mission d'une prestation multi-salles se valide avec toute sa prestation.
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            $this->validatePrestation($mission, $group, $actor, $expectedRevision);

            return $mission;
        }

        return $this->locked($mission, function () use ($mission, $actor, $expectedRevision): Mission {
            $expectedRevision = $expectedRevision !== null ? trim($expectedRevision) : '';
            if ($expectedRevision !== '' && $this->takeovers !== null
                && !hash_equals($this->takeovers->revision($mission), $expectedRevision)) {
                throw EncodingConcurrencyException::modified(EncodingConcurrencyException::SUBJECT_ENCODING);
            }

            return $this->validateTransition($mission, $actor, true);
        });
    }

    /** $requireHours = false only for a manager takeover (D-171): missing hours stay missing. */
    private function validateTransition(Mission $mission, User $actor, bool $requireHours): Mission
    {
        $this->assertValidatable($mission, $requireHours);
        $payload = $this->applyValidate($mission, $actor);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_VALIDATED, $actor, $payload);

        return $mission;
    }

    /** Tous les contrôles de validate(), sans aucune écriture. */
    private function assertValidatable(Mission $mission, bool $requireHours): void
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to validate its encoding');
        }

        // D-143 — une mission soumise SANS aucune heure réelle (cas historiques, D-143 §audit)
        // ne peut pas être validée — donc valorisée — sur le planifié par défaut : renvoyer
        // l'encodage à l'instrumentiste (reject) ou saisir les heures (source MANAGER) d'abord.
        // Une durée seule déclarée avant D-143 reste validable.
        if ($requireHours) {
            $this->completeness->assertHasDeclaredHours(
                $mission,
                "Heures prestées manquantes : renseignez-les ou renvoyez l'encodage à l'instrumentiste avant de valider.",
            );
        }

        // D-146 bis — jamais une validation définitive d'un contenu incomplet (intervention
        // sans firme, réponse obligatoire absente, matériel incompatible) ni avec une
        // demande catalogue encore en attente : la mission serait verrouillée alors qu'une
        // intervention ou une ligne de matériel peut encore lui être ajoutée.
        $this->contentCompleteness->assertComplete(
            $mission,
            true,
            "Encodage incomplet : complétez les interventions et traitez les demandes en attente avant de valider.",
        );
    }

    /**
     * Les écritures de validate() (statut, verrou, audit), sans flush.
     *
     * @param array<string,mixed> $extraPayload
     * @return array<string,mixed>
     */
    private function applyValidate(Mission $mission, User $actor, array $extraPayload = []): array
    {
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor) + $extraPayload;
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_VALIDATED, $payload);

        return $payload;
    }

    /**
     * SUBMITTED → ENCODING_IN_PROGRESS, commentaire obligatoire (matériel manquant, quantité
     * incorrecte, mauvaise firme, intervention incomplète...) — historisé sur la mission via
     * MissionEncodingComment, jamais perdu.
     *
     * D-148 — « Renvoyer en correction » (Suivi des encodages) : seul le statut change.
     * Interventions, matériel, heures, affectation, planning et calculs financiers restent
     * intacts ; l'instrumentiste est prévenue par le handler (ENCODING_CORRECTION_REQUESTED).
     */
    public function reject(Mission $mission, User $actor, string $comment): Mission
    {
        // Complément D-156 — renvoi en correction de toute la prestation multi-salles.
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            $this->rejectPrestation($mission, $group, $actor, $comment);

            return $mission;
        }

        return $this->locked($mission, fn (): Mission => $this->rejectTransition($mission, $actor, $comment));
    }

    private function rejectTransition(Mission $mission, User $actor, string $comment): Mission
    {
        $this->assertRejectable($mission);
        $comment = $this->requireReason($comment);
        $payload = $this->applyReject($mission, $actor, $comment);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_REJECTED, $actor, $payload);

        return $mission;
    }

    private function assertRejectable(Mission $mission): void
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to reject its encoding');
        }
    }

    /**
     * Les écritures de reject() (statut, commentaire, audit), sans flush.
     *
     * @param array<string,mixed> $extraPayload
     * @return array<string,mixed>
     */
    private function applyReject(Mission $mission, User $actor, string $comment, array $extraPayload = []): array
    {
        $from = $mission->getStatus();
        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $this->addComment($mission, $actor, $comment);

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment], $this->transitionPayload($from, $mission), $extraPayload);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REJECTED, $payload);

        return $payload;
    }

    /**
     * VALIDATED → ENCODING_IN_PROGRESS, motif obligatoire depuis D-148 (optionnel en D-070) :
     * l'instrumentiste doit savoir ce qu'elle corrige. Déverrouille explicitement
     * (encodingLockedAt = null) — c'est le seul chemin qui le fait ; jamais depuis CLOSED
     * (terminal, message d'erreur dédié pour ne pas confondre avec un simple mauvais état).
     *
     * EPIC Exécution & Valorisation, Lot 3 (D-073) — politique de réouverture à seuil :
     * un FinancialCalculation LOCKED (intégré à un document financier interne) bloque
     * désormais aussi la réouverture, en plus du statut CLOSED. Pas encore de calcul
     * seulement CALCULATED/APPROVED (jamais LOCKED) : la réouverture reste libre comme
     * avant ce lot — rien ne change tant qu'aucun engagement financier n'existe.
     */
    public function reopen(Mission $mission, User $actor, string $comment): Mission
    {
        // Complément D-156 — réouverture de toute la prestation multi-salles.
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            $this->reopenPrestation($mission, $group, $actor, $comment);

            return $mission;
        }

        return $this->locked($mission, fn (): Mission => $this->reopenTransition($mission, $actor, $comment));
    }

    private function reopenTransition(Mission $mission, User $actor, string $comment): Mission
    {
        $this->assertReopenable($mission);
        $comment = $this->requireReason($comment);
        $payload = $this->applyReopen($mission, $actor, $comment);
        $this->em->flush();

        // D-148 — le handler prévient l'instrumentiste (ENCODING_CORRECTION_REQUESTED), après
        // le flush : jamais de notification pour une transition qui n'a pas abouti.
        $this->dispatch($mission, MissionChangeType::ENCODING_REOPENED, $actor, $payload);

        return $mission;
    }

    private function assertReopenable(Mission $mission): void
    {
        if ($mission->getStatus() === MissionStatus::CLOSED) {
            throw new ConflictHttpException(MissionEncodingCorrectionPolicy::CLOSED_MESSAGE);
        }

        if ($mission->getStatus() !== MissionStatus::VALIDATED) {
            throw new ConflictHttpException('Mission must be VALIDATED to reopen its encoding');
        }

        // Requête directe (jamais la collection en mémoire) : c'est l'autorité au moment de
        // la transition ; MissionEncodingCorrectionPolicy n'en est que le reflet affiché.
        if ($this->hasLockedFinancialCalculation($mission)) {
            throw new ConflictHttpException(MissionEncodingCorrectionPolicy::FINANCIAL_LOCK_MESSAGE);
        }
    }

    /**
     * Les écritures de reopen() (statut, déverrouillage, commentaire, calculs obsolètes,
     * audit), sans flush.
     *
     * @param array<string,mixed> $extraPayload
     * @return array<string,mixed>
     */
    private function applyReopen(Mission $mission, User $actor, string $comment, array $extraPayload = []): array
    {
        $from = $mission->getStatus();
        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $mission->setEncodingLockedAt(null);
        $this->addComment($mission, $actor, $comment);

        // D-153 — le calcul encore actif reflète l'encodage qui va être corrigé : marqué
        // obsolète (jamais approuvé, verrouillé ni facturé ensuite), statut et montants
        // inchangés, aucun recalcul automatique. Seul recalculate() le remplace.
        $obsoleteIds = [];
        $now = new \DateTimeImmutable();
        foreach ($this->em->getRepository(FinancialCalculation::class)->findBy([
            'mission' => $mission,
            'status' => [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED],
        ]) as $calculation) {
            if (!$calculation->isObsolete()) {
                $calculation->setEncodingReopenedAt($now);
            }
            $obsoleteIds[] = $calculation->getId();
        }

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment], $this->transitionPayload($from, $mission), $extraPayload);
        if ($obsoleteIds !== []) {
            $payload['obsoleteFinancialCalculationIds'] = $obsoleteIds;
        }
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REOPENED, $payload);

        return $payload;
    }

    // ── Complément D-156 — décisions du manager sur toute une prestation multi-salles ──────

    /**
     * Applique $decide à toute la prestation, tout ou rien : verrou de CHAQUE mission (ordre
     * d'id, même verrou que les écritures d'encodage), composition revérifiée sous verrou
     * (sinon 409 PRESTATION_CHANGED), tous les contrôles avant la première écriture (c'est le
     * contrat de $decide), un seul flush. Les messages de cycle de vie partent après le commit,
     * un par mission (traçabilité individuelle) ; une seule notification par prestation
     * (prestationNotifiedMissionId).
     *
     * @param list<Mission> $group
     * @param \Closure(list<Mission>): list<array{0: Mission, 1: MissionChangeType, 2: array<string,mixed>}> $decide
     */
    private function decideOnPrestation(Mission $anchor, array $group, User $actor, \Closure $decide): void
    {
        $expectedIds = PrestationGrouping::ids($group);
        $run = function () use ($anchor, $expectedIds, $decide): array {
            $current = $this->prestationGroups->groupOf($anchor);
            if (PrestationGrouping::ids($current) !== $expectedIds) {
                throw new PrestationConflictException(
                    PrestationConflictException::PRESTATION_CHANGED,
                    'La prestation a changé entre-temps (missions ajoutées, retirées ou déplacées). Rechargez-la avant de recommencer.',
                    ['currentMissionIds' => PrestationGrouping::ids($current)],
                );
            }
            PrestationGrouping::sort($current);
            $events = $decide($current);
            $this->em->flush();

            return $events;
        };

        $byId = $group;
        usort($byId, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());
        foreach (array_reverse($byId) as $member) {
            $inner = $run;
            $run = fn (): array => $this->locked($member, $inner);
        }
        if ($this->encodingLock === null) {
            $run = fn (): array => $this->em->wrapInTransaction($run);
        }

        foreach (self::notifyOncePerPrestation($run()) as [$mission, $changeType, $payload]) {
            $this->dispatch($mission, $changeType, $actor, $payload);
        }
    }

    /** @param list<Mission> $group */
    private function assertPrestationRevision(array $group, ?string $expectedRevision, bool $required): void
    {
        $expectedRevision = $expectedRevision !== null ? trim($expectedRevision) : '';
        if ($expectedRevision === '') {
            if ($required) {
                throw EncodingConcurrencyException::versionRequired();
            }

            return;
        }
        if ($this->takeovers !== null && !hash_equals($this->takeovers->prestationRevision($group), $expectedRevision)) {
            throw EncodingConcurrencyException::modified(EncodingConcurrencyException::SUBJECT_ENCODING);
        }
    }

    /**
     * @param list<Mission> $group
     * @param list<MissionStatus> $allowed
     */
    private static function assertPrestationStatuses(array $group, array $allowed, string $message): void
    {
        foreach ($group as $m) {
            if (!in_array($m->getStatus(), $allowed, true)) {
                throw self::statusMismatch($group, $message);
            }
        }
    }

    /** @param list<Mission> $group */
    private static function statusMismatch(array $group, string $message): PrestationConflictException
    {
        $statuses = [];
        foreach ($group as $m) {
            $statuses[(int) $m->getId()] = $m->getStatus()?->value;
        }

        return new PrestationConflictException(PrestationConflictException::PRESTATION_STATUS_MISMATCH, $message, [
            'prestationMissionIds' => PrestationGrouping::ids($group),
            'statuses' => $statuses,
        ]);
    }

    /**
     * SUBMITTED → VALIDATED pour toutes les missions de la prestation qui ne sont pas déjà
     * validées ou clôturées ; toutes doivent être soumises (soumission commune), sinon 409.
     *
     * @param list<Mission> $group
     */
    private function validatePrestation(Mission $anchor, array $group, User $actor, ?string $expectedRevision): void
    {
        $this->decideOnPrestation($anchor, $group, $actor, function (array $members) use ($actor, $expectedRevision): array {
            $pending = array_values(array_filter($members, static fn (Mission $m) => !in_array($m->getStatus(), [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)));
            if ($pending === []) {
                throw new ConflictHttpException('Mission must be SUBMITTED to validate its encoding');
            }
            self::assertPrestationStatuses($members, [MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED],
                "La prestation multi-salles n'est pas entièrement soumise : elle se valide en une fois, après la soumission de toutes ses missions.");
            $this->assertPrestationRevision($members, $expectedRevision, false);
            foreach ($pending as $m) {
                $this->assertValidatable($m, true);
            }

            $events = [];
            foreach ($pending as $m) {
                $events[] = [$m, MissionChangeType::ENCODING_VALIDATED, $this->applyValidate($m, $actor, self::prestationPayload($members))];
            }

            return $events;
        });
    }

    /**
     * Renvoi en correction de toute la prestation : chaque mission SUBMITTED repasse
     * ENCODING_IN_PROGRESS avec le même motif. Une mission déjà validée → 409 (rouvrir la
     * prestation à la place) : jamais une prestation à moitié validée, à moitié en correction.
     *
     * @param list<Mission> $group
     */
    private function rejectPrestation(Mission $anchor, array $group, User $actor, string $comment): void
    {
        $this->decideOnPrestation($anchor, $group, $actor, function (array $members) use ($actor, $comment): array {
            $submitted = array_values(array_filter($members, static fn (Mission $m) => $m->getStatus() === MissionStatus::SUBMITTED));
            if ($submitted === []) {
                throw new ConflictHttpException('Mission must be SUBMITTED to reject its encoding');
            }
            foreach ($members as $m) {
                if (in_array($m->getStatus(), [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)) {
                    throw self::statusMismatch($members, "Une partie de la prestation multi-salles est déjà validée : rouvrez la prestation pour la renvoyer en correction.");
                }
            }
            $comment = $this->requireReason($comment);

            $events = [];
            foreach ($submitted as $m) {
                $events[] = [$m, MissionChangeType::ENCODING_REJECTED, $this->applyReject($m, $actor, $comment, self::prestationPayload($members))];
            }

            return $events;
        });
    }

    /**
     * Réouverture de toute la prestation : chaque mission VALIDATED est rouverte (calculs
     * actifs marqués obsolètes, D-153) ; une mission encore SUBMITTED est renvoyée en correction
     * avec le même motif — toute la prestation repasse en correction. Clôturée ou calcul
     * verrouillé sur l'une des missions → 409, rien n'est rouvert.
     *
     * @param list<Mission> $group
     */
    private function reopenPrestation(Mission $anchor, array $group, User $actor, string $comment): void
    {
        $this->decideOnPrestation($anchor, $group, $actor, function (array $members) use ($actor, $comment): array {
            $validated = array_values(array_filter($members, static fn (Mission $m) => $m->getStatus() === MissionStatus::VALIDATED));
            foreach ($members as $m) {
                if ($m->getStatus() === MissionStatus::CLOSED) {
                    throw new ConflictHttpException(MissionEncodingCorrectionPolicy::CLOSED_MESSAGE);
                }
            }
            if ($validated === []) {
                throw new ConflictHttpException('Mission must be VALIDATED to reopen its encoding');
            }
            foreach ($validated as $m) {
                $this->assertReopenable($m);
            }
            $comment = $this->requireReason($comment);

            $extra = self::prestationPayload($members);
            $events = [];
            foreach ($members as $m) {
                if ($m->getStatus() === MissionStatus::VALIDATED) {
                    $events[] = [$m, MissionChangeType::ENCODING_REOPENED, $this->applyReopen($m, $actor, $comment, $extra)];
                } elseif ($m->getStatus() === MissionStatus::SUBMITTED) {
                    $events[] = [$m, MissionChangeType::ENCODING_REJECTED, $this->applyReject($m, $actor, $comment, $extra)];
                }
            }

            return $events;
        });
    }

    /**
     * « Terminer et valider » (D-171) de toute la prestation : chaque mission encore ouverte est
     * terminée (justification « sans matériel » appréciée sur la prestation, heures jamais
     * exigées), puis toutes sont validées. Révision attendue = celle de la prestation entière.
     *
     * @param list<Mission> $group
     */
    private function completeAndValidatePrestation(Mission $anchor, array $group, User $actor, ?string $comment, ?string $expectedRevision, bool $takeoverConfirmed): void
    {
        $now = Clock::get()->now();
        $this->decideOnPrestation($anchor, $group, $actor, function (array $members) use ($actor, $comment, $expectedRevision, $takeoverConfirmed, $now): array {
            $open = array_values(array_filter($members, static fn (Mission $m) => !in_array($m->getStatus(), [MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED], true)));
            if ($open === []) {
                throw new ConflictHttpException("L'encodage n'est pas en cours : il ne peut pas être terminé et validé depuis ce statut.");
            }
            foreach ($open as $m) {
                $blocked = MissionEncodingCorrectionPolicy::completeAndValidateBlockedReason($m, $now);
                if ($blocked !== null) {
                    throw new ConflictHttpException($blocked);
                }
            }
            $this->assertPrestationRevision($members, $expectedRevision, true);

            $takeovers = array_values(array_filter($open, static fn (Mission $m) => MissionEncodingCorrectionPolicy::isTakeover($m)));
            if ($takeovers !== [] && !$takeoverConfirmed) {
                throw new UnprocessableEntityHttpException(sprintf(
                    "Confirmez la reprise de l'encodage de %s : il n'a pas été soumis par l'instrumentiste.",
                    $this->displayName($takeovers[0]->getInstrumentist()),
                ));
            }

            $toValidate = array_values(array_filter($members, static fn (Mission $m) => !in_array($m->getStatus(), [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)));
            $withoutMaterial = $this->prestationHasNoActiveMaterial($members);
            $justification = $withoutMaterial ? $comment : null;
            foreach ($open as $m) {
                $this->assertCompletable($m, $actor, $justification, false, $withoutMaterial);
            }
            foreach ($toValidate as $m) {
                $this->contentCompleteness->assertComplete(
                    $m,
                    true,
                    "Encodage incomplet : complétez les interventions et traitez les demandes en attente avant de valider.",
                );
            }

            $extra = self::prestationPayload($members);
            $events = [];
            $takeoverEvents = [];
            $hoursMissing = [];
            foreach ($open as $m) {
                $hoursMissing[(int) $m->getId()] = MissionEncodingCorrectionPolicy::hoursMissing($m);
                if (MissionEncodingCorrectionPolicy::isTakeover($m)) {
                    $instrumentist = $m->getInstrumentist();
                    $takeoverPayload = array_merge($this->actorPayload($actor), [
                        'instrumentistId'    => $instrumentist?->getId(),
                        'instrumentistName'  => $this->displayName($instrumentist),
                        'fromStatus'         => $m->getStatus()->value,
                        'revision'           => $expectedRevision !== null ? trim($expectedRevision) : null,
                        'hoursMissing'       => $hoursMissing[(int) $m->getId()],
                        'interventionsCount' => $m->getInterventions()->count(),
                        'materialLinesCount' => $m->getMaterialLines()->count(),
                    ], $extra);
                    $this->audit->record($m, $actor, AuditEventType::MISSION_ENCODING_TAKEN_OVER, $takeoverPayload);
                    $takeoverEvents[] = [$m, MissionChangeType::ENCODING_TAKEN_OVER, $takeoverPayload];
                }
                $events[] = [$m, MissionChangeType::ENCODING_COMPLETED, $this->applyComplete($m, $actor, $justification, $extra, $withoutMaterial)];
            }
            foreach ($toValidate as $m) {
                $events[] = [$m, MissionChangeType::ENCODING_VALIDATED, $this->applyValidate($m, $actor, $extra)];
                if ($hoursMissing[(int) $m->getId()] ?? false) {
                    $m->setEncodingValidatedWithoutHoursAt($now);
                }
            }

            // Après la validation : jamais de notification de reprise pour une décision avortée.
            return [...$events, ...$takeoverEvents];
        });
    }

    /**
     * Point d'entrée unique pour le futur moteur de facturation (Lot 8+, non implémenté ici) :
     * seul VALIDATED est facturable, jamais une mission en cours. Ne rien ajouter d'autre
     * ici tant que le moteur réel n'existe pas — c'est délibérément le seul contrat exposé.
     */
    public function isBillable(Mission $mission): bool
    {
        return $mission->getStatus() === MissionStatus::VALIDATED;
    }

    /** @return Mission[] */
    public function findMissionsReadyForBilling(): array
    {
        return $this->em->getRepository(Mission::class)->findBy(['status' => MissionStatus::VALIDATED]);
    }

    /** D-073 (Lot 3) — requête directe, pas de dépendance au FinancialCalculationService complet pour ce seul contrôle. */
    private function hasLockedFinancialCalculation(Mission $mission): bool
    {
        return $this->em->getRepository(FinancialCalculation::class)->findOneBy([
            'mission' => $mission,
            'status' => FinancialCalculationStatus::LOCKED,
        ]) !== null;
    }

    private function addComment(Mission $mission, User $author, string $comment): void
    {
        $entity = new MissionEncodingComment();
        $entity->setMission($mission)->setAuthor($author)->setComment($comment);
        $this->em->persist($entity);

        // Keeps the in-memory collection consistent within the same request — Doctrine
        // does not sync the inverse side automatically from the owning side's FK alone.
        $mission->getEncodingComments()->add($entity);
    }

    /** D-148 — motif de correction obligatoire, jamais un texte vide ou fait d'espaces. */
    private function requireReason(string $comment): string
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new UnprocessableEntityHttpException('Le motif de la correction est obligatoire.');
        }

        return $comment;
    }

    /** @return array{fromStatus: string, toStatus: string} */
    private function transitionPayload(MissionStatus $from, Mission $mission): array
    {
        return ['fromStatus' => $from->value, 'toStatus' => $mission->getStatus()->value];
    }

    /** @return array{actorId: int, actorName: string} */
    private function actorPayload(User $actor): array
    {
        return [
            'actorId'   => $actor->getId(),
            'actorName' => trim(($actor->getFirstname() ?? '') . ' ' . ($actor->getLastname() ?? '')),
        ];
    }

    private function dispatch(Mission $mission, MissionChangeType $changeType, User $actor, array $payload): void
    {
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: $changeType,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
