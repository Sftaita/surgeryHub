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
  OPEN_MISSION_AVAILABLE: "Nouvelle offre disponible",
  SURGEON_POST_COVERED: "Poste couvert",
  SURGEON_POST_UNCOVERED: "Poste non couvert",
  PLANNING_MISSION_REASSIGNED: "Mission réassignée",
  PLANNING_MISSION_CANCELLED: "Mission annulée",
  PLANNING_MISSION_ADDED: "Nouvelle mission",
  PLANNING_MISSION_UPDATED: "Mission modifiée",
  ABSENCE_INSTRUMENTIST_RELEASED: "Mission retirée (absence)",
  ABSENCE_SURGEON_MISSION_OPENED: "Mission désormais ouverte (absence)",
  ABSENCE_MISSION_CANCELLED: "Mission annulée (absence)",
  ABSENCE_INSTRUMENTIST_REASSIGNED: "Mission réaffectée (absence)",
  PLANNING_RESENT_MANUAL: "Planning renvoyé",
};

/**
 * `NotificationEvent` (backend) n'a pas de titre/corps préformaté — seulement
 * `eventType` + `payload` bruts. On dérive un texte lisible ici plutôt que
 * d'exposer l'eventType brut à l'utilisateur ; type inconnu → repli générique
 * (jamais un écran cassé pour un eventType ajouté côté backend sans être
 * répercuté ici).
 */
export function formatNotificationTitle(n: Pick<NotificationItem, "eventType">): string {
  return TITLES[n.eventType] ?? "Notification";
}

export function formatNotificationBody(n: Pick<NotificationItem, "payload" | "eventType">): string {
  const payload = n.payload ?? {};
  const siteName = typeof payload.siteName === "string" ? payload.siteName : null;
  const missionDate = typeof payload.missionDate === "string" ? payload.missionDate : null;

  if (n.eventType === "PLANNING_RESENT_MANUAL") {
    const from = typeof payload.periodFrom === "string" ? payload.periodFrom : null;
    const to = typeof payload.periodTo === "string" ? payload.periodTo : null;
    return from && to ? `Période du ${from} au ${to}` : "";
  }

  // CAS B (D-117) audit — the generic missionDate+siteName fallback below left this type
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

  // CAS B (D-117) — payload is the CANCELLED mission's own summary (date/siteName above
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

  if (missionDate && siteName) return `${missionDate} — ${siteName}`;
  if (siteName) return siteName;
  if (missionDate) return missionDate;
  return "";
}
