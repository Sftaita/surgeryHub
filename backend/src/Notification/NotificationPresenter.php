<?php

namespace App\Notification;

use App\Entity\EncodingAnomalyReport;
use App\Entity\Hospital;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PublicationScope;
use App\Enum\ReleasedRoomSlotStatus;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centre de notifications — ce qu'une notification in-app DIT, calculé côté serveur.
 *
 * `NotificationEvent` ne stocke que `eventType` + un payload brut : le titre et la phrase
 * étaient autrefois rédigés par le frontend (notificationFormat.ts), qui retombait sur
 * « Notification » pour tout type absent de sa table (17 types de l'enum, plus le type
 * historique PLANNING_ALERT_RAISED). Ce presenter est désormais la seule source du texte :
 *
 *   - title / body   rédigés uniquement à partir du payload instantané et, à défaut, de la
 *                    Mission liée (date, horaires, site, chirurgien — jamais un patient ni un
 *                    montant) ; une ancienne notification incomplète le dit explicitement
 *                    (`legacy: true`), sans contexte inventé ;
 *   - category       famille d'icône (mission, offer, absence, planning, alert, encoding,
 *                    catalogue, request, room) ;
 *   - businessState  INFO | ACTION_REQUIRED | DONE, lu sur la ressource métier ACTUELLE
 *                    (statut de la mission, de la demande, de l'alerte, de l'offre…) —
 *                    indépendant de seenAt : lire une notification ne traite rien ;
 *   - stateLabel     libellé court de l'état (« À traiter », « Traitée », « Plus disponible »…) ;
 *   - targetUrl      NotificationTargetResolver, plus les cibles propres au centre
 *                    (absence → tiroir de la mission ou page Absences).
 *
 * Les ressources sont chargées par lot (une requête par famille), jamais une par notification.
 */
final class NotificationPresenter
{
    public const STATE_INFO = 'INFO';
    public const STATE_ACTION_REQUIRED = 'ACTION_REQUIRED';
    public const STATE_DONE = 'DONE';

    /** Types dont l'état métier peut être « À traiter » (filtre `action`). */
    public const ACTIONABLE_TYPES = [
        'MISSION_DECLARED', 'MISSION_OFFERED', 'ROOM_TAKEOVER_MISSION_OFFER', 'MISSION_OFFER_DECLINED',
        'CATALOGUE_REQUEST_CREATED', 'SURGEON_MISSION_REQUEST_CREATED', 'PLANNING_ALERT', 'PLANNING_ALERT_RAISED',
        'ENCODING_REMINDER', 'ENCODING_CORRECTION_REQUESTED', 'ENCODING_ESCALATION', 'ENCODING_ANOMALY_REPORTED',
        'ABSENCE_IMPACT_SUMMARY', 'ASSIGNMENT_REVIEW_REQUESTED',
    ];

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const ALERT_LABELS = [
        'SURGEON_ABSENCE'         => 'absence du chirurgien',
        'INSTRUMENTIST_ABSENCE'   => "absence de l'instrumentiste",
        'SURGEON_CONFLICT'        => "conflit d'horaire du chirurgien",
        'INSTRUMENTIST_CONFLICT'  => "conflit d'horaire de l'instrumentiste",
        'REASSIGNMENT_REQUIRED'   => 'réaffectation nécessaire',
        'OCCURRENCE_CANCELLED'    => 'créneau annulé',
        'INSTRUMENTIST_INACTIVE'  => 'instrumentiste désactivée',
        'SURGEON_INCOMPATIBILITY' => 'incompatibilité avec le chirurgien',
    ];

    private const ANOMALY_LABELS = [
        'INTERVENTION_MISSING'   => 'intervention manquante',
        'INTERVENTION_INCORRECT' => 'intervention incorrecte',
        'MATERIAL_INCORRECT'     => 'matériel',
        'HOURS_INCORRECT'        => 'horaires',
        'OTHER'                  => 'autre',
    ];

    /** Rubriques « action requise » de ABSENCE_IMPACT_SUMMARY (AbsenceImpactSummaryMessage). */
    public const ABSENCE_ACTION_BUCKETS = [
        'missionsReleased'     => 'Instrumentiste retirée — à recouvrir',
        'missionsRestoredOpen' => 'Rétablie sans instrumentiste — à couvrir',
        'draftsToCover'        => 'Planning en préparation — à recouvrir',
        'managerDecisions'     => 'Décision manager attendue',
    ];

    /** @var array<int, Mission> */
    private array $missions = [];
    /** @var array<string, object> "KIND:id" → request */
    private array $catalogueRequests = [];
    /** @var array<int, SurgeonMissionRequest> */
    private array $surgeonRequests = [];
    /** @var array<int, PlanningAlert> */
    private array $alerts = [];
    /** @var array<int, EncodingAnomalyReport> */
    private array $anomalyReports = [];
    /** @var array<int, ReleasedOperatingRoomSlot> */
    private array $roomSlots = [];
    /** @var array<int, ?int> missionId → instrumentist targeted by its pending nominative offer */
    private array $pendingOfferTargets = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationTargetResolver $targetResolver,
    ) {
    }

    /**
     * @param list<NotificationEvent> $events all addressed to $recipient
     * @return list<array<string, mixed>> the API items, in the given order
     */
    public function presentMany(array $events, User $recipient, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->preload($events);

        return array_map(fn (NotificationEvent $e) => $this->present($e, $recipient, $now), $events);
    }

    /** @return array<string, mixed> */
    private function present(NotificationEvent $n, User $recipient, \DateTimeImmutable $now): array
    {
        $payload = $n->getPayload() ?? [];
        $eventType = (string) $n->getEventType();
        // Only what the preload really found: a linked mission deleted since is presented
        // without it (no target, no context), never an EntityNotFoundException.
        $linked = $n->getMission() !== null ? ($this->missions[(int) $n->getMission()->getId()] ?? null) : null;
        $mission = $linked ?? ($n->getMission() === null ? $this->missionFromPayload($payload) : null);
        $facts = $this->facts($payload, $mission);

        $view = $this->describe($eventType, $payload, $facts, $mission, $recipient, $now);
        $view += ['category' => 'info', 'actionLabel' => null, 'state' => [self::STATE_INFO, null], 'missions' => null, 'legacy' => false];

        $type = NotificationType::tryFrom($eventType);
        $targetUrl = $view['targetUrl'] ?? ($type !== null
            ? $this->targetResolver->resolve(
                $type,
                $linked,
                $recipient,
                isset($payload['requestId']) ? (int) $payload['requestId'] : null,
                isset($payload['kind']) ? CatalogueRequestKind::tryFrom((string) $payload['kind']) : null,
                isset($payload['roomSlotId']) ? (int) $payload['roomSlotId'] : null,
            )
            : null);
        // Ancienne alerte planning : la mission n'est que dans le payload.
        if ($targetUrl === null && $mission !== null && $eventType === 'PLANNING_ALERT_RAISED' && self::isManager($recipient)) {
            $targetUrl = '/app/m/missions/' . $mission->getId();
        }

        if ($targetUrl !== null) {
            $targetUrl = str_replace('__ID__', (string) $n->getId(), $targetUrl);
        }
        [$state, $stateLabel] = $view['state'];

        // An instrumentist's encoding notification about a mission she no longer holds
        // (reassigned since): the encoding screen would refuse her (MissionVoter) — no dead link,
        // nothing left for her to do. Neutral wording (D-151): never who nor why.
        if ($targetUrl !== null && str_ends_with($targetUrl, '/encoding') && str_starts_with($targetUrl, '/app/i/')
            && $mission !== null && $mission->getInstrumentist()?->getId() !== $recipient->getId()) {
            $targetUrl = null;
            [$state, $stateLabel] = [self::STATE_INFO, 'Ne vous concerne plus'];
        }

        return [
            'id'            => $n->getId(),
            'eventType'     => $eventType,
            'missionId'     => $n->getMission()?->getId(),
            'title'         => $view['title'],
            'body'          => $view['body'],
            'category'      => $view['category'],
            'actionLabel'   => $targetUrl !== null ? ($view['actionLabel'] ?? self::defaultActionLabel($view['category'])) : null,
            'businessState' => $state,
            'stateLabel'    => $stateLabel,
            'legacy'        => $view['legacy'],
            'missions'      => $view['missions'],
            'targetUrl'     => $targetUrl,
            'payload'       => $n->getPayload(),
            'sentAt'        => $n->getSentAt()?->format(DATE_ATOM),
            'seenAt'        => $n->getSeenAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, ?string> $f
     * @return array<string, mixed>
     */
    private function describe(string $type, array $p, array $f, ?Mission $mission, User $recipient, \DateTimeImmutable $now): array
    {
        $at = self::missionPhrase($f);          // « du 13 novembre 2026 de 08h00 à 13h00 à Delta »
        $on = self::missionPhrase($f, 'le');    // « le 13 novembre 2026 … »
        $surgeon = $f['surgeon'] !== null ? 'le Dr ' . $f['surgeon'] : null;
        $mState = fn () => $this->missionCoverageState($mission, $recipient, $now);

        return match ($type) {
            // ── Missions déclarées ─────────────────────────────────────────────
            'MISSION_DECLARED' => [
                'title' => 'Mission déclarée à valider',
                'body' => self::sentence(['Une mission a été déclarée', self::str($p, 'declaredByName') ? 'par ' . self::str($p, 'declaredByName') : null,
                    $surgeon ? 'pour ' . $surgeon : null, $on]),
                'category' => 'mission', 'actionLabel' => 'Consulter la mission',
                'state' => $this->declaredState($mission),
                'legacy' => $at === null,
            ],
            'MISSION_DECLARED_APPROVED' => ['title' => 'Mission déclarée validée', 'body' => self::sentence(['Votre mission déclarée', $at, 'a été validée']), 'category' => 'mission'],
            'MISSION_DECLARED_REJECTED' => ['title' => 'Mission déclarée refusée', 'body' => self::sentence(['Votre mission déclarée', $at, "n'a pas été validée"]), 'category' => 'mission'],

            // ── Offres ────────────────────────────────────────────────────────
            'OPEN_MISSION_AVAILABLE' => $this->openMissionView($p, $f, $on, $surgeon, $mission, $recipient, $now),
            'MISSION_OFFERED' => [
                'title' => 'Mission proposée — réponse attendue',
                'body' => self::sentence(['Une mission vous est proposée', $on, $surgeon ? 'avec ' . $surgeon : null]) . ' Acceptez-la ou refusez-la depuis vos offres.',
                'category' => 'offer', 'actionLabel' => "Répondre à l'offre",
                'state' => $this->offerState($mission, $recipient),
            ],
            'ROOM_TAKEOVER_MISSION_OFFER' => [
                'title' => 'Salle reprise — mission réservée',
                'body' => self::str($p, 'takenByName') && self::str($p, 'originalSurgeonName')
                    ? self::sentence([self::str($p, 'takenByName') . ' reprend la salle de ' . self::str($p, 'originalSurgeonName'), $on]) . ' Une mission vous est réservée sur ce créneau.'
                    : self::sentence(['Une mission vous est réservée sur une salle reprise', $on]),
                'category' => 'offer', 'actionLabel' => "Répondre à l'offre",
                'state' => $this->offerState($mission, $recipient),
            ],
            'MISSION_ASSIGNED_DIRECTLY' => [
                'title' => 'Mission attribuée',
                'body' => self::sentence(['La mission', $at, 'vous a été attribuée']) . " Aucune action n'est requise.",
                'category' => 'mission',
            ],
            'MISSION_OFFER_DECLINED' => [
                'title' => 'Demande de mission refusée',
                'body' => self::sentence([(self::str($p, 'instrumentistName') ?? "L'instrumentiste") . ' a refusé la mission', $at]) . ' Elle reste à couvrir.',
                'category' => 'mission', 'actionLabel' => 'Voir la mission',
                'state' => $mState(),
            ],

            // ── Cycle de vie des missions ─────────────────────────────────────
            'PLANNING_MISSION_RELEASED' => $this->neutralRemoval($p, $f),
            'PLANNING_MISSION_REASSIGNED' => ($p['assignmentEnded'] ?? false) === true
                ? $this->neutralRemoval($p, $f)
                : ['title' => 'Mission ajoutée à votre planning', 'body' => self::sentence(['La mission', $at, 'vous a été attribuée']), 'category' => 'mission', 'actionLabel' => 'Voir la mission'],
            'PLANNING_MISSION_CANCELLED' => ['title' => 'Mission annulée', 'body' => self::sentence(['La mission', $at, 'est annulée']), 'category' => 'mission'],
            'PLANNING_MISSION_ADDED' => ['title' => 'Nouvelle mission dans votre planning', 'body' => self::sentence(['La mission', $at, 'a été ajoutée à votre planning']), 'category' => 'mission'],
            'PLANNING_MISSION_UPDATED' => ['title' => 'Mission modifiée', 'body' => self::sentence(['La mission', $at, 'a été modifiée']), 'category' => 'mission'],
            'SURGEON_POST_COVERED' => $this->postCoveredView($p, $f, $at),
            'SURGEON_POST_UNCOVERED' => [
                'title' => 'Mission sans instrumentiste',
                'body' => self::sentence([self::str($p, 'fromInstrumentistName') ? self::str($p, 'fromInstrumentistName') . " n'est plus affecté·e à votre mission" : "Votre mission n'a plus d'instrumentiste", $at])
                    . ' Elle est remise à disposition des instrumentistes pour trouver un remplacement.',
                'category' => 'mission',
            ],
            'ASSIGNMENT_REFUSED_BY_SURGEON' => [
                'title' => "Refus d'affectation par le chirurgien",
                'body' => self::sentence([($surgeon ? ucfirst($surgeon) : 'Le chirurgien') . " a refusé l'affectation de " . (self::str($p, 'fromInstrumentistName') ?? "l'instrumentiste"), $at ? '(mission ' . $at . ')' : null])
                    . ' La mission est remise à disposition des autres instrumentistes.',
                'category' => 'mission',
            ],
            'ASSIGNMENT_REVIEW_REQUESTED' => [
                'title' => 'Intervention demandée sur une affectation',
                'body' => self::sentence([($surgeon ? ucfirst($surgeon) : 'Le chirurgien') . " demande votre intervention sur l'affectation de " . (self::str($p, 'instrumentistName') ?? "l'instrumentiste"), $at ? '(mission ' . $at . ')' : null]),
                'category' => 'mission', 'actionLabel' => 'Voir la mission',
                'state' => $this->upcomingActionState($mission, $now),
            ],
            'MISSION_REASSIGNMENT_NOTICE' => [
                'title' => "Changement d'instrumentiste",
                'body' => self::str($p, 'sentence') ?? self::sentence(["L'instrumentiste de la mission", $at, 'a changé']),
                'category' => 'mission',
            ],
            'MISSION_UNCOVERED_ESCALATION' => [
                'title' => 'Instrumentiste toujours recherchée',
                'body' => self::sentence(["Aucune instrumentiste n'a encore été trouvée pour votre intervention", $at]) . ' La coordination poursuit les recherches.',
                'category' => 'mission',
            ],
            'MISSION_RESTORED', 'MISSION_RESTORED_MGR' => ['title' => 'Mission de nouveau prévue', 'body' => self::sentence(['La mission', $at, "est de nouveau prévue après la modification d'une absence"]), 'category' => 'mission'],

            // ── Absences ──────────────────────────────────────────────────────
            'ABSENCE_IMPACT_SUMMARY' => $this->absenceImpactView($p, $recipient, $now),
            'ABSENCE_SELF_DECLARED' => [
                'title' => 'Absence déclarée' . (self::str($p, 'absentUserName') ? ' — ' . self::str($p, 'absentUserName') : ''),
                'body' => self::str($p, 'message') ?? self::sentence([self::str($p, 'absentUserName') ?? 'Une personne', 'a déclaré une absence', self::period($p['dateStart'] ?? null, $p['dateEnd'] ?? null)]),
                'category' => 'absence', 'actionLabel' => "Voir l'absence",
                'targetUrl' => self::isManager($recipient) && isset($p['absenceId']) ? '/app/m/planning/absences?absenceId=' . (int) $p['absenceId'] : null,
            ],
            'ABSENCE_INSTRUMENTIST_RELEASED' => ['title' => 'Mission retirée de votre planning', 'body' => self::sentence(['En raison de votre absence, la mission', $at, 'ne fait plus partie de votre planning']), 'category' => 'absence'],
            'ABSENCE_SURGEON_MISSION_OPENED' => [
                'title' => "Absence de l'instrumentiste",
                'body' => self::sentence([self::str($p, 'instrumentistName') ? self::str($p, 'instrumentistName') . ' est absent·e : votre mission' : 'Votre mission', $at, 'est remise à disposition des instrumentistes']),
                'category' => 'absence',
            ],
            'ABSENCE_MISSION_CANCELLED' => ['title' => 'Mission annulée — absence du chirurgien', 'body' => self::sentence(['La mission', $at, $surgeon ? 'avec ' . $surgeon : null, "est annulée en raison de l'absence du chirurgien"]), 'category' => 'absence'],
            'ABSENCE_INSTRUMENTIST_REASSIGNED' => $this->absenceReassignedView($p, $at),
            'ABSENCE_OCCURRENCE_CANCELLED', 'ABSENCE_OCCURRENCE_CANCELLED_MGR' => [
                'title' => self::str($p, 'surgeonName') ? 'Absence du Dr ' . self::str($p, 'surgeonName') : 'Absence du chirurgien — créneau impacté',
                'body' => self::str($p, 'sentence') ?? self::sentence(['Le créneau', $at, "est impacté par l'absence du chirurgien"]),
                'category' => 'absence',
            ],
            'ABSENCE_OCCURRENCE_UNCOVERED' => [
                'title' => self::str($p, 'absentName') ? 'Absence de ' . self::str($p, 'absentName') : 'Absence de votre instrumentiste',
                'body' => self::str($p, 'sentence') ?? self::sentence(['Votre créneau', $at, "est concerné par l'absence de votre instrumentiste"]),
                'category' => 'absence',
            ],
            'ABSENCE_OCCURRENCE_RESTORED', 'ABSENCE_OCCURRENCE_RESTORED_MGR' => [
                'title' => 'Poste habituel de nouveau prévu',
                'body' => self::str($p, 'sentence') ?? self::sentence(['Le créneau', $at, 'est de nouveau prévu']),
                'category' => 'absence',
            ],

            // ── Planning ──────────────────────────────────────────────────────
            'PLANNING_ALERT', 'PLANNING_ALERT_RAISED' => [
                'title' => 'Alerte planning' . (isset(self::ALERT_LABELS[(string) ($p['alertType'] ?? '')]) ? ' — ' . self::ALERT_LABELS[(string) $p['alertType']] : ''),
                'body' => $at !== null ? self::sentence(['Une alerte concerne la mission', $at]) : 'Une alerte concerne le planning.',
                'category' => 'alert', 'actionLabel' => 'Voir la mission',
                'state' => $this->alertState(isset($p['alertId']) ? (int) $p['alertId'] : null),
            ],
            'PLANNING_DEPLOYED_INSTRUMENTIST', 'PLANNING_DEPLOYED_SURGEON' => [
                'title' => 'Planning publié',
                'body' => self::sentence(['Votre planning', self::str($p, 'periodLabel'), 'est disponible']),
                'category' => 'planning', 'actionLabel' => 'Voir mon planning',
            ],
            'PLANNING_DEPLOYED_MANAGER' => [
                'title' => 'Déploiement du planning confirmé',
                'body' => self::deployedManagerBody($p),
                'category' => 'planning', 'actionLabel' => 'Voir les missions',
            ],
            'PLANNING_CHANGE_SUMMARY' => ['title' => 'Votre planning a été modifié', 'body' => self::changeSummaryBody($p), 'category' => 'planning', 'actionLabel' => 'Voir mon planning'],
            'PLANNING_RESENT_MANUAL' => [
                'title' => 'Planning renvoyé',
                'body' => self::str($p, 'periodFrom') && self::str($p, 'periodTo')
                    ? sprintf('Votre planning du %s au %s vous a été renvoyé.', self::str($p, 'periodFrom'), self::str($p, 'periodTo'))
                    : 'Votre planning vous a été renvoyé.',
                'category' => 'planning', 'actionLabel' => 'Voir mon planning',
            ],
            'ROOM_SLOT_AVAILABLE' => $this->roomSlotView($p, $f),

            // ── Demandes chirurgien ───────────────────────────────────────────
            'SURGEON_MISSION_OPEN_PUBLISHED' => [
                'title' => 'Nouvelle mission ouverte',
                'body' => self::sentence(['Votre mission', $at, 'a été publiée']) . ' Elle est actuellement sans instrumentiste.',
                'category' => 'mission',
            ],
            'SURGEON_MISSION_REQUEST_CREATED' => [
                'title' => 'Nouvelle demande de mission',
                'body' => self::sentence([self::str($p, 'surgeonName') ? 'Le Dr ' . self::str($p, 'surgeonName') . ' demande une mission' : 'Une mission est demandée', $on]),
                'category' => 'request', 'actionLabel' => 'Traiter la demande',
                'state' => $this->surgeonRequestState(isset($p['requestId']) ? (int) $p['requestId'] : null),
            ],
            'SURGEON_MISSION_REQUEST_ACCEPTED' => [
                'title' => 'Demande de mission acceptée',
                'body' => self::sentence(['Votre demande de mission', $at, 'a été acceptée'])
                    . (($p['withoutInstrumentist'] ?? false) === true ? ' Elle est encore sans instrumentiste.' : ''),
                'category' => 'request', 'actionLabel' => 'Voir la mission',
            ],
            'SURGEON_MISSION_REQUEST_REJECTED' => [
                'title' => 'Demande de mission refusée',
                'body' => self::sentence(['Votre demande de mission', $at, "n'a pas été retenue"]) . (self::str($p, 'reviewComment') ? ' Motif : ' . self::str($p, 'reviewComment') : ''),
                'category' => 'request', 'actionLabel' => 'Voir mes demandes',
            ],

            // ── Catalogue ─────────────────────────────────────────────────────
            'CATALOGUE_REQUEST_CREATED' => [
                'title' => self::isMaterial($p) ? 'Nouvelle demande de matériel' : "Nouvelle demande d'intervention",
                'body' => self::sentence([self::quoted($p, 'label') ?? 'Un élément', 'est proposé au catalogue', $at ? 'pour la mission ' . $at : null]),
                'category' => 'catalogue', 'actionLabel' => 'Traiter la demande',
                'state' => $this->catalogueState($p),
            ],
            'CATALOGUE_REQUEST_RESOLVED' => [
                'title' => self::isMaterial($p) ? 'Demande de matériel acceptée' : "Demande d'intervention acceptée",
                'body' => self::sentence([self::quoted($p, 'label') ?? 'Votre proposition', 'a été acceptée',
                    self::str($p, 'finalName') && self::str($p, 'finalName') !== self::str($p, 'label') ? 'et enregistrée sous « ' . self::str($p, 'finalName') . ' »' : null,
                    $at ? '(mission ' . $at . ')' : null]),
                'category' => 'catalogue', 'actionLabel' => 'Voir la mission',
            ],
            'CATALOGUE_REQUEST_IGNORED' => [
                'title' => 'Proposition non retenue',
                'body' => self::sentence([self::quoted($p, 'label') ?? 'Votre proposition', "n'a pas été retenue au catalogue", $at ? '(mission ' . $at . ')' : null]),
                'category' => 'catalogue', 'actionLabel' => 'Voir la mission',
            ],

            // ── Encodage ──────────────────────────────────────────────────────
            'ENCODING_REMINDER' => [
                'title' => self::str($p, 'title') ?? 'Encodage à finaliser',
                'body' => self::str($p, 'body') ?? self::sentence(['Votre mission', $at, 'est encore à finaliser']),
                'category' => 'encoding', 'actionLabel' => "Compléter l'encodage",
                'state' => $this->encodingState($mission),
            ],
            'ENCODING_ESCALATION' => [
                'title' => 'Encodage en attente depuis 4 jours',
                'body' => self::sentence([(self::str($p, 'instrumentistName') ?? "L'instrumentiste") . " n'a pas finalisé l'encodage de la mission", $at])
                    . (is_array($p['missing'] ?? null) && $p['missing'] !== [] ? ' Manquant : ' . implode(', ', array_map('strval', $p['missing'])) . '.' : ''),
                'category' => 'encoding', 'actionLabel' => 'Voir le suivi',
                'state' => $this->encodingState($mission),
            ],
            'ENCODING_SUBMITTED' => [
                'title' => ($p['resubmitted'] ?? false) === true ? 'Encodage corrigé' : 'Encodage disponible',
                'body' => self::sentence(["L'encodage de votre mission", $at, ($p['resubmitted'] ?? false) === true ? 'a été corrigé' : 'a été soumis',
                    self::str($p, 'instrumentistName') ? 'par ' . self::str($p, 'instrumentistName') : null]),
                'category' => 'encoding', 'actionLabel' => "Consulter l'encodage",
            ],
            'ENCODING_ANOMALY_REPORTED' => [
                'title' => "Anomalie d'encodage signalée",
                'body' => self::sentence([self::str($p, 'surgeonName') ? 'Le Dr ' . self::str($p, 'surgeonName') . ' a signalé' : 'Le chirurgien a signalé',
                    'un point à vérifier' . (isset(self::ANOMALY_LABELS[(string) ($p['type'] ?? '')]) ? ' (' . self::ANOMALY_LABELS[(string) $p['type']] . ')' : ''), $at ? 'sur la mission ' . $at : null]),
                'category' => 'encoding', 'actionLabel' => 'Voir la mission',
                'state' => $this->anomalyState(isset($p['reportId']) ? (int) $p['reportId'] : null),
            ],
            'ENCODING_ANOMALY_INSTRUMENTIST' => [
                'title' => 'Point à vérifier sur votre encodage',
                'body' => self::sentence(['Le chirurgien a signalé un point à vérifier' . (isset(self::ANOMALY_LABELS[(string) ($p['type'] ?? '')]) ? ' (' . self::ANOMALY_LABELS[(string) $p['type']] . ')' : ''), $at ? 'sur votre mission ' . $at : null])
                    . ' La coordination reviendra vers vous si nécessaire.',
                'category' => 'encoding', 'actionLabel' => "Voir l'encodage",
            ],
            'ENCODING_ANOMALY_RESOLVED' => [
                'title' => 'Signalement traité',
                'body' => self::sentence(['Votre signalement', $at ? 'sur la mission ' . $at : null, 'a été traité']) . (self::str($p, 'resolutionComment') ? ' Commentaire : ' . self::str($p, 'resolutionComment') : ''),
                'category' => 'encoding', 'actionLabel' => "Voir l'encodage",
            ],
            'ENCODING_CORRECTION_REQUESTED' => [
                'title' => 'Correction de votre encodage demandée',
                'body' => self::sentence(["L'encodage de votre mission", $at, ($p['action'] ?? null) === 'REOPENED' ? 'a été rouvert pour correction' : 'vous a été renvoyé pour correction'])
                    . (self::str($p, 'reason') ? ' Motif : ' . self::str($p, 'reason') : ''),
                'category' => 'encoding', 'actionLabel' => "Corriger l'encodage",
                'state' => $this->encodingState($mission),
            ],
            'ENCODING_UPDATED_BY_SURGEON' => [
                'title' => 'Encodage complété par le chirurgien',
                'body' => self::sentence([(self::str($p, 'surgeonName') ? 'Le Dr ' . self::str($p, 'surgeonName') : 'Le chirurgien') . ' a complété ou corrigé les interventions ou le matériel de votre mission', $at]),
                'category' => 'encoding', 'actionLabel' => "Voir l'encodage",
            ],
            'ENCODING_TAKEN_OVER' => [
                'title' => 'Encodage repris et validé',
                'body' => self::sentence([(self::str($p, 'managerName') ?? 'La coordination') . " a repris, terminé et validé l'encodage de votre mission", $at])
                    . ' Ce que vous aviez encodé est conservé.' . (($p['hoursMissing'] ?? false) === true ? ' Vos heures prestées restent à déclarer.' : ''),
                'category' => 'encoding', 'actionLabel' => "Voir l'encodage",
            ],

            default => [
                'title' => 'Ancienne notification',
                'body' => $at !== null
                    ? self::sentence(['Elle concerne la mission', $at]) . ' Le détail de cette ancienne notification n\'est pas disponible.'
                    : "Le détail de cette ancienne notification n'est pas disponible.",
                'category' => $mission !== null ? 'mission' : 'info',
                'legacy' => true,
            ],
        };
    }

    // ── Vues spécifiques ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function openMissionView(array $p, array $f, ?string $at, ?string $surgeon, ?Mission $mission, User $recipient, \DateTimeImmutable $now): array
    {
        $count = (int) ($p['missionCount'] ?? 1);
        if ($count > 1 || ($at === null && self::str($p, 'periodLabel') !== null)) {
            // Ancienne offre groupée (avant H3) : un compte et une période, pas de mission précise.
            return [
                'title' => $count > 1 ? sprintf('%d missions disponibles', $count) : 'Nouvelle mission disponible',
                'body' => self::sentence([$count > 1 ? sprintf('%d missions sont disponibles', $count) : 'Une mission est disponible', self::str($p, 'periodLabel'), $f['site'] ? 'à ' . $f['site'] : null]),
                'category' => 'offer', 'actionLabel' => 'Consulter les offres',
                'state' => [self::STATE_INFO, null],
            ];
        }

        $state = [self::STATE_INFO, 'Disponible'];
        if ($mission !== null) {
            $taken = $mission->getInstrumentist();
            if ($taken !== null && $taken->getId() === $recipient->getId()) {
                $state = [self::STATE_DONE, 'Vous avez pris cette mission'];
            } elseif ($mission->getStatus() !== MissionStatus::OPEN || $taken !== null || ($mission->getStartAt() !== null && $mission->getStartAt() < $now)) {
                $state = [self::STATE_DONE, 'Plus disponible'];
            }
        }

        return [
            'title' => 'Nouvelle mission disponible',
            'body' => self::sentence(['Une mission est disponible', $at, $surgeon ? 'avec ' . $surgeon : null]),
            'category' => 'offer', 'actionLabel' => "Consulter l'offre",
            'state' => $state,
        ];
    }

    /** @return array<string, mixed> */
    private function neutralRemoval(array $p, array $f): array
    {
        $when = self::dateLabel($f['date']) . ($f['start'] !== null ? ' à ' . self::time($f['start']) : '');

        return [
            'title' => 'Affectation annulée',
            // D-151 — phrase neutre imposée : jamais qui, jamais pourquoi.
            'body' => $f['date'] !== null && $f['site'] !== null
                ? sprintf("Votre affectation à la mission du %s, prévue à %s, n'a pas pu être maintenue.", $when, $f['site'])
                : "Votre affectation à cette mission n'a pas pu être maintenue.",
            'category' => 'mission',
        ];
    }

    /** @return array<string, mixed> */
    private function postCoveredView(array $p, array $f, ?string $at): array
    {
        $name = self::str($p, 'instrumentistName');
        if (($p['acceptedVoluntarily'] ?? false) === true) {
            $deadline = self::str($p, 'refusalDeadlineAt');
            $deadlineLabel = $deadline !== null ? (new \DateTimeImmutable($deadline))->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('d/m/Y à H:i') : null;

            return [
                'title' => 'Instrumentiste affecté·e à votre mission',
                'body' => self::sentence([($name ?? 'Un instrumentiste') . ' a accepté votre mission', $at])
                    . ($deadlineLabel !== null ? " Vous pouvez signaler un refus d'affectation jusqu'au {$deadlineLabel}." : ''),
                'category' => 'mission', 'actionLabel' => 'Voir la mission',
            ];
        }

        return [
            'title' => 'Votre mission est couverte',
            'body' => self::sentence([($name ?? 'Un instrumentiste') . ' est affecté·e à votre mission', $at]),
            'category' => 'mission', 'actionLabel' => 'Voir la mission',
        ];
    }

    /** @return array<string, mixed> */
    private function absenceReassignedView(array $p, ?string $at): array
    {
        $targets = [];
        foreach (is_array($p['reassignedTo'] ?? null) ? $p['reassignedTo'] : [] as $t) {
            if (is_array($t)) {
                $targets[] = self::missionPhrase(['date' => self::str($t, 'date'), 'start' => null, 'end' => null, 'horaire' => self::str($t, 'horaire'), 'site' => self::str($t, 'siteName'), 'surgeon' => null]);
            }
        }
        $targets = array_values(array_filter($targets));

        return [
            'title' => 'Mission réaffectée — absence du chirurgien',
            'body' => $targets !== []
                ? self::sentence(['La mission', $at, "est annulée en raison de l'absence du chirurgien"]) . ' Vous êtes réaffecté·e à la mission ' . $targets[0]
                    . (count($targets) > 1 ? sprintf(' (+%d autre%s)', count($targets) - 1, count($targets) > 2 ? 's' : '') : '') . '.'
                : self::sentence(['La mission', $at, "est annulée en raison de l'absence du chirurgien ; une autre mission vous a été attribuée"]),
            'category' => 'absence', 'actionLabel' => 'Voir mon planning',
        ];
    }

    /** @return array<string, mixed> */
    private function roomSlotView(array $p, array $f): array
    {
        $when = array_filter([self::str($p, 'dayLabel'), self::str($p, 'startTime') && self::str($p, 'endTime')
            ? sprintf('de %s à %s', self::time(self::str($p, 'startTime')), self::time(self::str($p, 'endTime')))
            : self::str($p, 'periodLabel')]);
        $state = [self::STATE_INFO, 'Disponible'];
        $slot = isset($p['roomSlotId']) ? ($this->roomSlots[(int) $p['roomSlotId']] ?? null) : null;
        if ($slot !== null && $slot->getStatus() !== ReleasedRoomSlotStatus::AVAILABLE) {
            $state = [self::STATE_DONE, 'Plus disponible'];
        }

        return [
            'title' => 'Salle opératoire disponible',
            'body' => self::sentence(['Une salle vient de se libérer', $f['site'] ? 'à ' . $f['site'] : null, $when !== [] ? implode(' ', $when) : null]) . ' Elle peut être reprise.',
            'category' => 'room', 'actionLabel' => 'Voir la salle',
            'state' => $state,
        ];
    }

    /**
     * Sophie Colette est absente le 12 novembre 2026. Deux missions à Delta sont impactées.
     *
     * @return array<string, mixed>
     */
    private function absenceImpactView(array $p, User $recipient, \DateTimeImmutable $now): array
    {
        $name = self::str($p, 'absentUserName') ?? 'Une personne';
        $action = (string) ($p['action'] ?? 'CREATED');
        $required = (int) ($p['actionRequiredCount'] ?? 0);
        $automatic = (int) ($p['automaticCount'] ?? 0);
        $period = self::period($p['dateStart'] ?? null, $p['dateEnd'] ?? null);

        $title = match ($action) {
            'UPDATED' => "Absence modifiée — {$name}",
            'DELETED' => "Absence supprimée — {$name}",
            default   => "Absence de {$name} — planning impacté",
        };

        $list = is_array($p['missions'] ?? null) ? $p['missions'] : null;
        if ($list === null) {
            // Notification antérieure à l'enrichissement du payload : ni dates ni missions.
            $parts = [];
            if ($required > 0) {
                $parts[] = sprintf('%d élément%s étai%s à couvrir ou à décider', $required, $required > 1 ? 's' : '', $required > 1 ? 'ent' : 't');
            }
            if ($automatic > 0) {
                $parts[] = sprintf('%d traité%s automatiquement', $automatic, $automatic > 1 ? 's' : '');
            }

            return [
                'title' => $title,
                'body' => ($parts !== [] ? ucfirst(implode(', ', $parts)) . '. ' : '')
                    . "Les dates et les missions concernées ne sont pas disponibles pour cette ancienne notification.",
                'category' => 'absence', 'actionLabel' => "Voir l'absence",
                'targetUrl' => self::isManager($recipient) && isset($p['absenceId']) ? '/app/m/planning/absences?absenceId=' . (int) $p['absenceId'] : null,
                'legacy' => true,
            ];
        }

        $missions = [];
        $open = 0;
        $uncoveredPast = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['missionId'])) {
                continue;
            }
            $mission = $this->missions[(int) $item['missionId']] ?? null;
            [$covered, $statusLabel] = self::coverage($mission);
            $upcoming = $mission?->getStartAt() === null || $mission->getStartAt() >= $now;
            if (!$covered) {
                $upcoming ? $open++ : $uncoveredPast++;
            }
            $missions[] = [
                'missionId'    => (int) $item['missionId'],
                'dateLabel'    => self::dateLabel(self::str($item, 'date')),
                'timeLabel'    => self::horaire(self::str($item, 'horaire')),
                'siteName'     => self::str($item, 'siteName'),
                'surgeonName'  => self::str($item, 'surgeonName'),
                'impactLabel'  => self::ABSENCE_ACTION_BUCKETS[(string) ($item['bucket'] ?? '')] ?? null,
                'statusLabel'  => $statusLabel,
                'covered'      => $covered,
                'targetUrl'    => '/app/m/missions/' . (int) $item['missionId'],
            ];
        }

        $sites = array_values(array_unique(array_filter(array_map(static fn (array $m) => $m['siteName'], $missions))));
        $count = count($missions);
        $subject = $action === 'DELETED' ? "L'absence de {$name}" . ($period ? ' ' . $period : '') . ' a été supprimée.'
            : ($action === 'UPDATED' ? "L'absence de {$name} a été modifiée" . ($period ? ' (' . trim(preg_replace('/^(le|du) /', '', $period) ?? '') . ')' : '') . '.'
            : self::sentence(["{$name} est absent·e", $period]));
        $impact = $count > 0
            ? sprintf(' %s mission%s%s %s à couvrir ou à décider.', self::countWord($count), $count > 1 ? 's' : '', count($sites) === 1 ? ' à ' . $sites[0] : '', $count > 1 ? 'sont' : 'est')
            : ($required === 0 ? ' Impact traité automatiquement, aucune action requise.' : '');

        $state = match (true) {
            $count === 0 => [self::STATE_INFO, null],
            $open > 0 => [self::STATE_ACTION_REQUIRED, sprintf('%d à couvrir', $open)],
            $uncoveredPast > 0 => [self::STATE_INFO, null],
            default => [self::STATE_DONE, 'Missions couvertes'],
        };

        $targetUrl = null;
        if (self::isManager($recipient)) {
            $targetUrl = $count === 1
                ? $missions[0]['targetUrl']
                : (isset($p['absenceId']) ? '/app/m/planning/absences?absenceId=' . (int) $p['absenceId'] . '&notification=__ID__' : null);
        }

        return [
            'title' => $title,
            'body' => $subject . $impact,
            'category' => 'absence',
            'actionLabel' => $count === 1 ? 'Voir la mission concernée' : ($count > 1 ? 'Voir les missions concernées' : "Voir l'absence"),
            'state' => $state,
            'missions' => $missions,
            'targetUrl' => $targetUrl,
        ];
    }

    // ── États métier ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: ?string} */
    private function declaredState(?Mission $mission): array
    {
        return match ($mission?->getStatus()) {
            null => [self::STATE_INFO, null],
            MissionStatus::DECLARED => [self::STATE_ACTION_REQUIRED, 'À valider'],
            MissionStatus::REJECTED => [self::STATE_DONE, 'Refusée'],
            MissionStatus::CANCELLED => [self::STATE_DONE, 'Annulée'],
            default => [self::STATE_DONE, 'Validée'],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function offerState(?Mission $mission, User $recipient): array
    {
        if ($mission === null) {
            return [self::STATE_INFO, null];
        }
        if ($mission->getInstrumentist()?->getId() === $recipient->getId()) {
            return [self::STATE_DONE, 'Acceptée'];
        }
        // Same rule as MissionDispatchService::pendingOffer(), on the preloaded publications.
        $pendingFor = $mission->getStatus() === MissionStatus::OPEN && $mission->getInstrumentist() === null
            ? ($this->pendingOfferTargets[(int) $mission->getId()] ?? null)
            : null;
        if ($pendingFor !== null && $pendingFor === $recipient->getId()) {
            return [self::STATE_ACTION_REQUIRED, 'Réponse attendue'];
        }

        return [self::STATE_DONE, 'Plus disponible'];
    }

    /** Manager side: a mission still to cover. @return array{0: string, 1: ?string} */
    private function missionCoverageState(?Mission $mission, User $recipient, \DateTimeImmutable $now): array
    {
        if ($mission === null) {
            return [self::STATE_INFO, null];
        }
        [$covered, $label] = self::coverage($mission);
        if ($covered) {
            return [self::STATE_DONE, $label];
        }

        return $mission->getStartAt() !== null && $mission->getStartAt() < $now
            ? [self::STATE_INFO, null]
            : [self::STATE_ACTION_REQUIRED, 'À couvrir'];
    }

    /** @return array{0: string, 1: ?string} */
    private function upcomingActionState(?Mission $mission, \DateTimeImmutable $now): array
    {
        if ($mission === null || $mission->getStatus() === MissionStatus::CANCELLED
            || ($mission->getStartAt() !== null && $mission->getStartAt() < $now)) {
            return [self::STATE_INFO, null];
        }

        return [self::STATE_ACTION_REQUIRED, 'À examiner'];
    }

    /** @return array{0: string, 1: ?string} */
    private function encodingState(?Mission $mission): array
    {
        return match ($mission?->getStatus()) {
            null => [self::STATE_INFO, null],
            MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED => [self::STATE_DONE, 'Encodage soumis'],
            MissionStatus::CANCELLED => [self::STATE_DONE, 'Annulée'],
            default => [self::STATE_ACTION_REQUIRED, 'À encoder'],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function alertState(?int $alertId): array
    {
        $alert = $alertId !== null ? ($this->alerts[$alertId] ?? null) : null;

        return match ($alert?->getStatus()) {
            null => [self::STATE_INFO, null],
            PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED => [self::STATE_ACTION_REQUIRED, 'À traiter'],
            PlanningAlertStatus::RESOLVED => [self::STATE_DONE, 'Résolue'],
            PlanningAlertStatus::IGNORED => [self::STATE_DONE, 'Ignorée'],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function catalogueState(array $p): array
    {
        $request = isset($p['requestId'], $p['kind']) ? ($this->catalogueRequests[$p['kind'] . ':' . (int) $p['requestId']] ?? null) : null;

        return match ($request?->getStatus()) {
            null => [self::STATE_INFO, null],
            MaterialItemRequest::STATUS_PENDING => [self::STATE_ACTION_REQUIRED, 'À traiter'],
            MaterialItemRequest::STATUS_RESOLVED => [self::STATE_DONE, 'Acceptée'],
            default => [self::STATE_DONE, 'Non retenue'],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function surgeonRequestState(?int $requestId): array
    {
        $request = $requestId !== null ? ($this->surgeonRequests[$requestId] ?? null) : null;

        return match ($request?->getStatus()) {
            null => [self::STATE_INFO, null],
            SurgeonMissionRequest::STATUS_PENDING => [self::STATE_ACTION_REQUIRED, 'À traiter'],
            SurgeonMissionRequest::STATUS_ACCEPTED => [self::STATE_DONE, 'Acceptée'],
            default => [self::STATE_DONE, 'Refusée'],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function anomalyState(?int $reportId): array
    {
        $report = $reportId !== null ? ($this->anomalyReports[$reportId] ?? null) : null;

        return match ($report?->getStatus()) {
            null => [self::STATE_INFO, null],
            EncodingAnomalyReport::STATUS_OPEN => [self::STATE_ACTION_REQUIRED, 'À traiter'],
            default => [self::STATE_DONE, 'Traitée'],
        };
    }

    /** @return array{0: bool, 1: string} covered (or no longer to cover), current status label */
    private static function coverage(?Mission $mission): array
    {
        if ($mission === null) {
            return [true, 'Mission supprimée'];
        }
        if ($mission->getStatus() === MissionStatus::CANCELLED) {
            return [true, 'Annulée'];
        }
        $instrumentist = $mission->getInstrumentist();
        if ($instrumentist !== null) {
            return [true, 'Couverte — ' . self::name($instrumentist)];
        }

        return [false, $mission->getStatus() === MissionStatus::DRAFT ? 'En préparation, sans instrumentiste' : 'Sans instrumentiste'];
    }

    // ── Chargement par lot ───────────────────────────────────────────────────

    /** @param list<NotificationEvent> $events */
    private function preload(array $events): void
    {
        $missionIds = $alertIds = $surgeonRequestIds = $reportIds = $slotIds = $offerMissionIds = [];
        $catalogue = [CatalogueRequestKind::MATERIAL_ITEM->value => [], CatalogueRequestKind::INTERVENTION_TYPE->value => []];

        foreach ($events as $n) {
            $p = $n->getPayload() ?? [];
            $type = (string) $n->getEventType();
            if ($n->getMission() !== null) {
                $missionIds[] = (int) $n->getMission()->getId(); // proxy id: no query
            } elseif (isset($p['missionId']) && is_numeric($p['missionId'])) {
                $missionIds[] = (int) $p['missionId'];
            }
            if (in_array($type, ['MISSION_OFFERED', 'ROOM_TAKEOVER_MISSION_OFFER'], true) && $missionIds !== []) {
                $offerMissionIds[] = end($missionIds);
            }
            foreach (is_array($p['missions'] ?? null) ? $p['missions'] : [] as $item) {
                if (is_array($item) && isset($item['missionId'])) {
                    $missionIds[] = (int) $item['missionId'];
                }
            }
            if (isset($p['alertId'])) {
                $alertIds[] = (int) $p['alertId'];
            }
            if ($type === 'SURGEON_MISSION_REQUEST_CREATED' && isset($p['requestId'])) {
                $surgeonRequestIds[] = (int) $p['requestId'];
            }
            if ($type === 'CATALOGUE_REQUEST_CREATED' && isset($p['requestId'], $catalogue[$p['kind'] ?? ''])) {
                $catalogue[$p['kind']][] = (int) $p['requestId'];
            }
            if ($type === 'ENCODING_ANOMALY_REPORTED' && isset($p['reportId'])) {
                $reportIds[] = (int) $p['reportId'];
            }
            if ($type === 'ROOM_SLOT_AVAILABLE' && isset($p['roomSlotId'])) {
                $slotIds[] = (int) $p['roomSlotId'];
            }
        }

        // One query per family, never one per notification: the missions (an already-referenced
        // proxy is initialized by the same query), then the people and sites they show, then the
        // publications of nominative offers only. A mission that no longer exists simply stays
        // absent from $this->missions (presented without it, never an error).
        // Reset on every call: this service outlives a request (worker, tests) and must never
        // present a business state read during an earlier one.
        $this->missions = [];
        $missionIds = array_values(array_unique($missionIds));
        $userIds = $siteIds = [];
        foreach ($this->findByIds(Mission::class, $missionIds) as $m) {
            $this->missions[(int) $m->getId()] = $m;
            $userIds[] = $m->getSurgeon()?->getId();
            $userIds[] = $m->getInstrumentist()?->getId();
            $siteIds[] = $m->getSite()?->getId();
        }
        $this->findByIds(User::class, array_filter($userIds));
        $this->findByIds(Hospital::class, array_filter($siteIds));
        $this->pendingOfferTargets = [];
        foreach ($this->findByIds(MissionPublication::class, array_values(array_intersect(array_unique($offerMissionIds), array_keys($this->missions))), 'mission') as $publication) {
            if ($publication->getScope() === PublicationScope::TARGETED && $publication->isActive()) {
                // latest wins, as MissionDispatchService::pendingOffer()
                $this->pendingOfferTargets[(int) $publication->getMission()?->getId()] = $publication->getTargetInstrumentist()?->getId();
            }
        }
        $this->alerts = self::indexById($this->findByIds(PlanningAlert::class, $alertIds));
        $this->surgeonRequests = self::indexById($this->findByIds(SurgeonMissionRequest::class, $surgeonRequestIds));
        $this->anomalyReports = self::indexById($this->findByIds(EncodingAnomalyReport::class, $reportIds));
        $this->roomSlots = self::indexById($this->findByIds(ReleasedOperatingRoomSlot::class, $slotIds));
        $this->catalogueRequests = [];
        foreach ([CatalogueRequestKind::MATERIAL_ITEM->value => MaterialItemRequest::class, CatalogueRequestKind::INTERVENTION_TYPE->value => InterventionTypeRequest::class] as $kind => $class) {
            foreach ($this->findByIds($class, $catalogue[$kind]) as $request) {
                $this->catalogueRequests[$kind . ':' . $request->getId()] = $request;
            }
        }
    }

    /** @return list<object> */
    private function findByIds(string $class, array $ids, string $field = 'id'): array
    {
        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : $this->em->getRepository($class)->findBy([$field => $ids], $field === 'id' ? null : ['id' => 'ASC']);
    }

    /** @param list<object> $entities @return array<int, object> */
    private static function indexById(array $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            $out[(int) $e->getId()] = $e;
        }

        return $out;
    }

    private function missionFromPayload(array $p): ?Mission
    {
        return isset($p['missionId']) && is_numeric($p['missionId']) ? ($this->missions[(int) $p['missionId']] ?? null) : null;
    }

    // ── Faits & formatage ────────────────────────────────────────────────────

    /**
     * Date / horaires / site / chirurgien : l'instantané du payload d'abord (ce qui était vrai
     * à l'envoi), la Mission liée seulement pour ce que le payload ne porte pas.
     *
     * @return array{date: ?string, start: ?string, end: ?string, horaire: ?string, site: ?string, surgeon: ?string}
     */
    private function facts(array $p, ?Mission $mission): array
    {
        $startAt = self::str($p, 'startAt');
        $endAt = self::str($p, 'endAt');

        return [
            'date'    => self::str($p, 'missionDate') ?? self::str($p, 'date') ?? ($startAt !== null ? substr($startAt, 0, 10) : null) ?? $mission?->getStartAt()?->format('Y-m-d'),
            'start'   => self::str($p, 'startTime') ?? ($startAt !== null && strlen($startAt) > 10 ? (new \DateTimeImmutable($startAt))->format('H:i') : null) ?? $mission?->getStartAt()?->format('H:i'),
            'end'     => self::str($p, 'endTime') ?? ($endAt !== null ? (new \DateTimeImmutable($endAt))->format('H:i') : null) ?? $mission?->getEndAt()?->format('H:i'),
            'horaire' => self::str($p, 'horaire'),
            'site'    => self::str($p, 'siteName') ?? $mission?->getSite()?->getName(),
            'surgeon' => self::str($p, 'surgeonName') ?? ($mission?->getSurgeon() !== null ? self::name($mission->getSurgeon()) : null),
        ];
    }

    /** « le 13 novembre 2026 de 08h00 à 13h00 à Delta » — only the parts known. */
    private static function missionPhrase(array $f, string $article = 'du'): ?string
    {
        if ($f['date'] === null && $f['site'] === null) {
            return null;
        }
        $time = $f['start'] !== null && $f['end'] !== null
            ? sprintf('de %s à %s', self::time($f['start']), self::time($f['end']))
            : ($f['horaire'] !== null ? self::horaire($f['horaire']) : ($f['start'] !== null ? 'à ' . self::time($f['start']) : null));

        return implode(' ', array_filter([
            $f['date'] !== null ? $article . ' ' . self::dateLabel($f['date']) : null,
            $time,
            $f['site'] !== null ? 'à ' . $f['site'] : null,
        ]));
    }

    /** Accepts 'Y-m-d', 'd/m/Y' or ISO 8601 — never re-zoned (the payload is a wall-clock snapshot). */
    public static function dateLabel(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return $raw;
        }

        return sprintf('%d %s %d', $d, self::MONTHS[$mo] ?? '', $y);
    }

    private static function period(mixed $start, mixed $end): ?string
    {
        $s = is_string($start) ? self::dateLabel($start) : null;
        $e = is_string($end) ? self::dateLabel($end) : null;
        if ($s === null) {
            return null;
        }

        return $e === null || $e === $s ? 'le ' . $s : sprintf('du %s au %s', $s, $e);
    }

    private static function time(?string $hhmm): ?string
    {
        return $hhmm !== null ? str_replace(':', 'h', substr($hhmm, 0, 5)) : null;
    }

    /** '08:00–13:00' → 'de 08h00 à 13h00'. */
    private static function horaire(?string $horaire): ?string
    {
        if ($horaire === null) {
            return null;
        }
        $parts = preg_split('/\s*[–-]\s*/u', $horaire);

        return is_array($parts) && count($parts) === 2 ? sprintf('de %s à %s', self::time($parts[0]), self::time($parts[1])) : $horaire;
    }

    /** @param list<?string> $parts */
    private static function sentence(array $parts): string
    {
        $text = trim(implode(' ', array_filter($parts, static fn (?string $s) => $s !== null && $s !== '')));

        return $text === '' ? '' : (str_ends_with($text, '.') ? $text : $text . '.');
    }

    private static function deployedManagerBody(array $p): string
    {
        $count = (int) ($p['missionCount'] ?? 0);
        $detail = array_filter([
            isset($p['assignedCount']) ? sprintf('%d attribuée%s', (int) $p['assignedCount'], (int) $p['assignedCount'] > 1 ? 's' : '') : null,
            isset($p['openPoolCount']) ? sprintf('%d au pool', (int) $p['openPoolCount']) : null,
        ]);

        return self::sentence(['Planning', self::str($p, 'periodLabel'), 'publié :', sprintf('%d mission%s', $count, $count > 1 ? 's' : ''), $detail !== [] ? '(' . implode(', ', $detail) . ')' : null]);
    }

    private static function changeSummaryBody(array $p): string
    {
        $changes = [];
        foreach (['addedCount' => ['ajout', 'ajouts'], 'removedCount' => ['retrait', 'retraits'], 'modifiedCount' => ['modification', 'modifications']] as $key => [$one, $many]) {
            $k = (int) ($p[$key] ?? 0);
            if ($k > 0) {
                $changes[] = $k . ' ' . ($k > 1 ? $many : $one);
            }
        }
        $head = self::str($p, 'periodLabel') !== null ? 'Planning de ' . self::str($p, 'periodLabel') : 'Votre planning';

        return $changes !== [] ? $head . ' : ' . implode(', ', $changes) . '.' : $head . ' a été modifié.';
    }

    private static function countWord(int $n): string
    {
        return [1 => 'Une', 2 => 'Deux', 3 => 'Trois', 4 => 'Quatre', 5 => 'Cinq'][$n] ?? (string) $n;
    }

    private static function defaultActionLabel(string $category): string
    {
        return match ($category) {
            'offer' => "Consulter l'offre",
            'planning' => 'Voir le planning',
            'absence' => "Voir l'absence",
            'catalogue', 'request' => 'Voir la demande',
            'encoding' => "Voir l'encodage",
            'room' => 'Voir la salle',
            default => 'Voir la mission',
        };
    }

    private static function str(array $p, string $key): ?string
    {
        $v = $p[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private static function quoted(array $p, string $key): ?string
    {
        return self::str($p, $key) !== null ? '« ' . self::str($p, $key) . ' »' : null;
    }

    private static function isMaterial(array $p): bool
    {
        return ($p['kind'] ?? null) !== CatalogueRequestKind::INTERVENTION_TYPE->value;
    }

    private static function isManager(User $user): bool
    {
        return (bool) array_intersect(['ROLE_MANAGER', 'ROLE_ADMIN'], $user->getRoles());
    }

    private static function name(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : 'Utilisateur #' . $user->getId();
    }
}
