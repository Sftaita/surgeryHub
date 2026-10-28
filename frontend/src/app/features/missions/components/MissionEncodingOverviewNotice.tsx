import type { MissionEncodingOverview } from "../api/missions.types";
import { encodingActorLabel, MissionLocksIndicator } from "./MissionEncodingBadge";

/**
 * D-172 — détail manager : état d'encodage (distinct du statut de planification), qui l'a
 * finalisé et validé, et l'explication de chaque verrou réel servi par le backend.
 */
export function MissionEncodingOverviewNotice({ encoding, locksOnly = false }: { encoding?: MissionEncodingOverview | null; locksOnly?: boolean }) {
  if (!encoding || encoding.state === "NOT_APPLICABLE" || encoding.state === "UPCOMING") return null;
  // Activité sans instrumentiste : état et auteurs sont dans le bloc « Activité opératoire ».
  if (locksOnly) {
    if (encoding.locks.length === 0) return null;
    return (
      <div className="mdw-notice mdw-notice--muted" data-testid="drawer-encoding-overview">
        {encoding.locks.map((lock) => (
          <span key={lock.level} className="mdw-notice__body mdw-notice__line" data-testid={`drawer-lock-${lock.level}`}>
            <strong>{lock.label}</strong> — {lock.reason}
          </span>
        ))}
      </div>
    );
  }
  const finalizedBy = encodingActorLabel(encoding.finalizedBy);
  const validatedBy = encodingActorLabel(encoding.validatedBy);

  return (
    <div className={`mdw-notice ${encoding.state === "NOT_DECLARED" ? "mdw-notice--warn" : "mdw-notice--muted"}`} data-testid="drawer-encoding-overview">
      <span className="mdw-notice__title">
        Encodage : {encoding.stateLabel.toLowerCase()} <MissionLocksIndicator locks={encoding.locks} />
      </span>
      {encoding.state === "NOT_DECLARED" && (
        <span className="mdw-notice__body mdw-notice__line">
          Mission passée sans instrumentiste : aucune activité n'a été déclarée réalisée ni encodée. Si l'intervention a eu
          lieu, déclarez-la réalisée sans instrumentiste puis encodez interventions et matériel.
        </span>
      )}
      {finalizedBy && <span className="mdw-notice__body mdw-notice__line">Encodage finalisé par {finalizedBy}.</span>}
      {validatedBy && <span className="mdw-notice__body mdw-notice__line">Validé par {validatedBy}.</span>}
      {encoding.locks.map((lock) => (
        <span key={lock.level} className="mdw-notice__body mdw-notice__line" data-testid={`drawer-lock-${lock.level}`}>
          <strong>{lock.label}</strong> — {lock.reason}
        </span>
      ))}
    </div>
  );
}
