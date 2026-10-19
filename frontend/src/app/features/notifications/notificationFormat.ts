import dayjs from "dayjs";
import type { NotificationItem } from "./api/notifications.api";

/**
 * Point 4 (audit UX) — cette table avait divergé de `NotificationType` (backend/src/
 * Enum/NotificationType.php) : la quasi-totalité des notifications réellement émises
 * retombait sur le repli générique "Notification", sans titre lisible. Clés alignées
 * sur les valeurs exactes de l'enum ci-dessus.
 */
const TITLES: Record<string, string> = {
  PLANNING_ALERT: "Alerte planning",
  PLANNING_DEPLOYED_INSTRUMENTIST: "Planning publié",
  PLANNING_DEPLOYED_SURGEON: "Planning publié",
  PLANNING_DEPLOYED_MANAGER: "Déploiement confirmé",
  OPEN_MISSION_AVAILABLE: "Nouvelle mission disponible",
  SURGEON_POST_COVERED: "Poste couvert",
  SURGEON_POST_UNCOVERED: "Poste non couvert",
  PLANNING_MISSION_REASSIGNED: "Mission réassignée",
  PLANNING_MISSION_CANCELLED: "Mission annulée",
  PLANNING_MISSION_RELEASED: "Mission retirée de votre planning",
  PLANNING_CHANGE_SUMMARY: "Votre planning a été modifié",
  PLANNING_MISSION_ADDED: "Nouvelle mission",
  PLANNING_MISSION_UPDATED: "Mission modifiée",
  MISSION_OFFERED: "Mission proposée — réponse attendue",
  MISSION_ASSIGNED_DIRECTLY: "Mission attribuée",
  MISSION_OFFER_DECLINED: "Demande refusée",
  ABSENCE_INSTRUMENTIST_RELEASED: "Mission retirée (absence)",
  ABSENCE_SURGEON_MISSION_OPENED: "Mission désormais ouverte (absence)",
  ABSENCE_MISSION_CANCELLED: "Mission annulée (absence)",
  ABSENCE_INSTRUMENTIST_REASSIGNED: "Mission réaffectée (absence)",
  PLANNING_RESENT_MANUAL: "Planning renvoyé",
  ROOM_TAKEOVER_MISSION_OFFER: "Salle reprise — mission disponible",
  // H4 (D-130) — absences, niveaux N1 (créneau habituel) / N2 (planning en préparation).
  ABSENCE_OCCURRENCE_CANCELLED: "Absence du chirurgien — créneau impacté",
  ABSENCE_OCCURRENCE_UNCOVERED: "Absence de votre instrumentiste",
  ABSENCE_OCCURRENCE_RESTORED: "Poste habituel de nouveau prévu",
  MISSION_RESTORED: "Mission réactivée",
  ABSENCE_IMPACT_SUMMARY: "Impact d'une absence sur le planning",
  ABSENCE_SELF_DECLARED: "Absence déclarée",
  // H5b (D-132) — notifications désormais émises par le dispatcher (in-app + push tracés).
  MISSION_DECLARED: "Mission déclarée à valider",
  MISSION_DECLARED_APPROVED: "Mission déclarée validée",
  MISSION_DECLARED_REJECTED: "Mission déclarée refusée",
  ENCODING_ANOMALY_REPORTED: "Anomalie d'encodage signalée",
  SURGEON_MISSION_REQUEST_CREATED: "Nouvelle demande de mission",
  // D-143 — encodage progressif, relances, retour chirurgien.
  ENCODING_REMINDER: "Encodage à finaliser",
  ENCODING_ESCALATION: "Encodage en attente depuis 4 jours",
  ENCODING_SUBMITTED: "Encodage disponible",
  ENCODING_ANOMALY_INSTRUMENTIST: "Point à vérifier sur votre encodage",
  ENCODING_CORRECTION_REQUESTED: "Correction de votre encodage demandée",
  ENCODING_UPDATED_BY_SURGEON: "Encodage complété par le chirurgien",
  // D-151
  ASSIGNMENT_REFUSED_BY_SURGEON: "Refus d'affectation par le chirurgien",
  ASSIGNMENT_REVIEW_REQUESTED: "Intervention demandée sur une affectation",
};

/**
 * D-151 — une instrumentiste retirée d'une mission publiée (refus du chirurgien, libération ou
 * réaffectation par le manager) ne lit qu'une formulation neutre : jamais qui, jamais pourquoi.
 */
function isAssignmentEnded(n: Pick<NotificationItem, "eventType"> & Partial<Pick<NotificationItem, "payload">>): boolean {
  return n.eventType === "PLANNING_MISSION_RELEASED" || (n.eventType === "PLANNING_MISSION_REASSIGNED" && n.payload?.assignmentEnded === true);
}

const ANOMALY_TYPE_LABELS: Record<string, string> = {
  INTERVENTION_MISSING: "intervention manquante",
  INTERVENTION_INCORRECT: "intervention incorrecte",
  MATERIAL_INCORRECT: "matériel",
  HOURS_INCORRECT: "horaires",
  OTHER: "autre",
};

/**
 * `NotificationEvent` (backend) n'a pas de titre/corps préformaté — seulement
 * `eventType` + `payload` bruts. On dérive un texte lisible ici plutôt que
 * d'exposer l'eventType brut à l'utilisateur ; type inconnu → repli générique
 * (jamais un écran cassé pour un eventType ajouté côté backend sans être
 * répercuté ici).
 */
export function formatNotificationTitle(n: Pick<NotificationItem, "eventType"> & Partial<Pick<NotificationItem, "payload">>): string {
  if (isAssignmentEnded(n)) return "Affectation annulée";
  if (n.eventType === "SURGEON_POST_COVERED" && n.payload?.acceptedVoluntarily === true) return "Instrumentiste affecté à votre mission";
  return TITLES[n.eventType] ?? "Notification";
}

export function formatNotificationBody(n: Pick<NotificationItem, "payload" | "eventType">): string {
  const payload = n.payload ?? {};
  const siteName = typeof payload.siteName === "string" ? payload.siteName : null;
  const missionDate = typeof payload.missionDate === "string" ? payload.missionDate : null;

  // D-125 — built only from the server payload (names snapshotted), never recomputed.
  // MISSION_ASSIGNED_DIRECTLY is a confirmation: never phrased as a request.
  if (n.eventType === "MISSION_OFFERED" || n.eventType === "MISSION_ASSIGNED_DIRECTLY" || n.eventType === "MISSION_OFFER_DECLINED") {
    const startTime = typeof payload.startTime === "string" ? payload.startTime : null;
    const endTime = typeof payload.endTime === "string" ? payload.endTime : null;
    const when = [missionDate, startTime && endTime ? `${startTime}–${endTime}` : null].filter(Boolean).join(" ");
    const where = [when || null, siteName].filter(Boolean).join(" — ");
    if (n.eventType === "MISSION_OFFERED") {
      return where ? `${where}. Acceptez-la ou refusez-la depuis vos offres.` : "Acceptez-la ou refusez-la depuis vos offres.";
    }
    if (n.eventType === "MISSION_ASSIGNED_DIRECTLY") {
      return where ? `${where}. Déjà confirmée, aucune action n'est requise.` : "Déjà confirmée, aucune action n'est requise.";
    }
    const name = typeof payload.instrumentistName === "string" ? payload.instrumentistName : "L'instrumentiste";
    return where ? `${name} a refusé la mission du ${where}. Elle reste à couvrir.` : `${name} a refusé la mission. Elle reste à couvrir.`;
  }

  // H2 (D-127) / D-151 — removed from a published mission: the imposed neutral sentence,
  // never who decided nor why, never an offer to take the mission back.
  if (isAssignmentEnded(n)) {
    const startTime = typeof payload.startTime === "string" ? payload.startTime : null;
    const when = [missionDate, startTime].filter(Boolean).join(" à ");
    if (when && siteName) return `Votre affectation à la mission du ${when}, prévue à ${siteName}, n'a pas pu être maintenue.`;
    return "Votre affectation à cette mission n'a pas pu être maintenue.";
  }

  // D-151 — voluntary acceptance: who, when, and until when the surgeon may refuse.
  if (n.eventType === "SURGEON_POST_COVERED" && payload.acceptedVoluntarily === true) {
    const name = typeof payload.instrumentistName === "string" ? payload.instrumentistName : "Un instrumentiste";
    const startTime = typeof payload.startTime === "string" ? payload.startTime : null;
    const when = [missionDate, startTime].filter(Boolean).join(" à ");
    const deadline = typeof payload.refusalDeadlineAt === "string" ? dayjs(payload.refusalDeadlineAt).format("DD/MM/YYYY [à] HH:mm") : null;
    const head = `${name} a accepté votre mission${when ? ` du ${when}` : ""}${siteName ? `, à ${siteName}` : ""}.`;
    return deadline ? `${head} Vous pouvez signaler un refus d'affectation jusqu'au ${deadline}.` : head;
  }

  // D-151 — managers: the real origin of the unassignment / the surgeon's request.
  if (n.eventType === "ASSIGNMENT_REFUSED_BY_SURGEON" || n.eventType === "ASSIGNMENT_REVIEW_REQUESTED") {
    const surgeon = typeof payload.surgeonName === "string" ? `Dr ${payload.surgeonName}` : "Le chirurgien";
    const where = [missionDate ? `mission du ${missionDate}` : null, siteName].filter(Boolean).join(" — ");
    if (n.eventType === "ASSIGNMENT_REFUSED_BY_SURGEON") {
      const who = typeof payload.fromInstrumentistName === "string" ? payload.fromInstrumentistName : "l'instrumentiste";
      return `${surgeon} a refusé l'affectation de ${who}${where ? ` (${where})` : ""}. La mission est remise à disposition des autres instrumentistes.`;
    }
    const who = typeof payload.instrumentistName === "string" ? payload.instrumentistName : "l'instrumentiste";
    return `${surgeon} demande votre intervention sur l'affectation de ${who}${where ? ` (${where})` : ""}.`;
  }

  // H3 (D-129) — pool offer for ONE mission (payload from OpenMissionPoolNotifier):
  // date, hours, site, surgeon — never a patient or an amount.
  if (n.eventType === "OPEN_MISSION_AVAILABLE") {
    const startTime = typeof payload.startTime === "string" ? payload.startTime : null;
    const endTime = typeof payload.endTime === "string" ? payload.endTime : null;
    const surgeonName = typeof payload.surgeonName === "string" ? payload.surgeonName : null;
    const when = [missionDate, startTime && endTime ? `${startTime}–${endTime}` : null].filter(Boolean).join(" ");
    const parts = [when || null, siteName, surgeonName ? `Dr ${surgeonName}` : null].filter((p): p is string => Boolean(p));
    if (parts.length > 0) return `${parts.join(" — ")}. Consultez vos offres pour la prendre.`;
  }

  // H3 (D-129) — recap of a Planning Modification batch (counts only, details in the email).
  if (n.eventType === "PLANNING_CHANGE_SUMMARY") {
    const count = (key: string) => (typeof payload[key] === "number" ? (payload[key] as number) : 0);
    const plural = (k: number, one: string, many: string) => `${k} ${k > 1 ? many : one}`;
    const changes = [
      count("addedCount") > 0 ? plural(count("addedCount"), "ajout", "ajouts") : null,
      count("removedCount") > 0 ? plural(count("removedCount"), "retrait", "retraits") : null,
      count("modifiedCount") > 0 ? plural(count("modifiedCount"), "modification", "modifications") : null,
    ].filter(Boolean);
    const period = typeof payload.periodLabel === "string" ? payload.periodLabel : null;
    const head = period ? `Planning de ${period}` : "Votre planning";
    return changes.length > 0 ? `${head} : ${changes.join(", ")}.` : `${head} a été modifié.`;
  }

  // H4 (D-130) — N1 / N2 absence information: the server already wrote the exact D-127
  // sentence for the level (never "mission annulée" before publication) — shown as is.
  if (n.eventType === "ABSENCE_OCCURRENCE_CANCELLED" || n.eventType === "ABSENCE_OCCURRENCE_UNCOVERED") {
    if (typeof payload.sentence === "string" && payload.sentence !== "") return payload.sentence;
  }

  if (n.eventType === "PLANNING_RESENT_MANUAL") {
    const from = typeof payload.periodFrom === "string" ? payload.periodFrom : null;
    const to = typeof payload.periodTo === "string" ? payload.periodTo : null;
    return from && to ? `Période du ${from} au ${to}` : "";
  }

  // CAS B (D-118) audit — the generic missionDate+siteName fallback below left this type
  // showing only the site (no instrumentist, no période), too thin for "who was assigned to
  // my mission and when" — surfaced by both MissionPostDeployService::claim() and the
  // OPEN→ASSIGNED branch of assign()/reassign() (CAS B's automatic post-absence
  // reassignment included, same free pipeline, no new NotificationType needed for it).
  if (n.eventType === "SURGEON_POST_COVERED") {
    const instrumentistName = typeof payload.instrumentistName === "string" ? payload.instrumentistName : null;
    const periodLabel = typeof payload.periodLabel === "string" ? payload.periodLabel : null;
    const parts = [instrumentistName, [missionDate, periodLabel].filter(Boolean).join(" · ") || null, siteName].filter(
      (part): part is string => Boolean(part),
    );
    if (parts.length > 0) return parts.join(" — ");
  }

  // CAS B (D-118) — payload is the CANCELLED mission's own summary (date/siteName above
  // already describe it), plus 'reassignedTo': list<{date, moment, horaire, siteName,
  // surgeonName}> for the mission(s) the instrumentist was automatically moved onto instead
  // (see AbsenceMissionReactionService::buildTargetSummary()). The body leads with the new
  // assignment — that's the actionable information — not a repeat of what was cancelled.
  if (n.eventType === "ABSENCE_INSTRUMENTIST_REASSIGNED") {
    const reassignedTo = Array.isArray(payload.reassignedTo) ? payload.reassignedTo : [];
    const targets = reassignedTo
      .map((t) => {
        if (typeof t !== "object" || t === null) return null;
        const rec = t as Record<string, unknown>;
        const targetDate = typeof rec.date === "string" ? rec.date : null;
        const targetHoraire = typeof rec.horaire === "string" ? rec.horaire : null;
        const targetSite = typeof rec.siteName === "string" ? rec.siteName : null;
        return [[targetDate, targetHoraire].filter(Boolean).join(" · ") || null, targetSite].filter(Boolean).join(" — ") || null;
      })
      .filter((s): s is string => Boolean(s));

    if (targets.length > 0) {
      const [first, ...rest] = targets;
      return rest.length > 0 ? `Nouvelle mission : ${first} (+${rest.length} autre${rest.length > 1 ? "s" : ""})` : `Nouvelle mission : ${first}`;
    }
  }

  // D-124 — offre contextuelle à l'instrumentiste qui était prévue sur la salle libérée.
  // Texte construit uniquement depuis le payload serveur (noms snapshotés), jamais recalculé.
  if (n.eventType === "ROOM_TAKEOVER_MISSION_OFFER") {
    const takenBy = typeof payload.takenByName === "string" ? payload.takenByName : null;
    const original = typeof payload.originalSurgeonName === "string" ? payload.originalSurgeonName : null;
    if (takenBy && original && missionDate && siteName) {
      return `${takenBy} reprend la salle de ${original} le ${missionDate} à ${siteName}. Une mission est disponible pour ce créneau.`;
    }
  }

  // D-143 — textes construits uniquement depuis le payload serveur.
  if (n.eventType === "ENCODING_REMINDER") {
    if (typeof payload.body === "string" && payload.body) return payload.body;
    return missionDate ? `Votre mission du ${missionDate} est encore à finaliser.` : "Une mission est encore à finaliser.";
  }
  if (n.eventType === "ENCODING_ESCALATION") {
    const who = typeof payload.instrumentistName === "string" ? payload.instrumentistName : "L'instrumentiste";
    const surgeon = typeof payload.surgeonName === "string" ? ` — ${payload.surgeonName}` : "";
    const missing = Array.isArray(payload.missing) && payload.missing.length > 0 ? ` Manquant : ${payload.missing.join(", ")}.` : "";
    return `${who} — mission du ${missionDate ?? "—"}${surgeon}.${missing}`;
  }
  if (n.eventType === "ENCODING_SUBMITTED") {
    const who = typeof payload.instrumentistName === "string" ? ` par ${payload.instrumentistName}` : "";
    return payload.resubmitted === true
      ? `L'encodage de votre mission du ${missionDate ?? "—"} a été corrigé${who}. Consultez la nouvelle version.`
      : `L'encodage de votre mission du ${missionDate ?? "—"} a été soumis${who}.`;
  }
  // D-148 — renvoi en correction / réouverture par un manager : mission + motif saisi.
  if (n.eventType === "ENCODING_CORRECTION_REQUESTED") {
    const mission = typeof payload.missionId === "number" ? `#${payload.missionId} ` : "";
    const what = payload.action === "REOPENED" ? "a été rouvert" : "vous a été renvoyé";
    const reason = typeof payload.reason === "string" && payload.reason ? ` Motif : ${payload.reason}` : "";
    return `L'encodage de votre mission ${mission}du ${missionDate ?? "—"} ${what} pour correction.${reason}`;
  }
  // D-154 — une notification par série de modifications du chirurgien (regroupées).
  if (n.eventType === "ENCODING_UPDATED_BY_SURGEON") {
    const who = typeof payload.surgeonName === "string" && payload.surgeonName ? payload.surgeonName : "Le chirurgien";
    return `${who} a complété ou corrigé les interventions / le matériel de votre mission du ${missionDate ?? "—"}. Consultez votre encodage.`;
  }
  if (n.eventType === "ENCODING_ANOMALY_INSTRUMENTIST") {
    const category = typeof payload.type === "string" && ANOMALY_TYPE_LABELS[payload.type] ? ` (${ANOMALY_TYPE_LABELS[payload.type]})` : "";
    return `Le chirurgien a signalé un point à vérifier${category} sur votre mission du ${missionDate ?? "—"}. La coordination reviendra vers vous si nécessaire.`;
  }

  if (missionDate && siteName) return `${missionDate} — ${siteName}`;
  if (siteName) return siteName;
  if (missionDate) return missionDate;
  return "";
}
