import "./hours-shared.css";
import { clock, duration } from "./format";
import { ClockIcon } from "./Icons";

// D-180 — piste, statut et étiquettes partagés par « Mes heures » (D-179) et le suivi manager.
// Extraits tels quels de MyHoursView.tsx : présentation seule, toutes les valeurs viennent
// du serveur (heures réellement encodées, jamais le planifié, aucune pause).

/** Champs d'une prestation lus par la piste (MyHoursItem et ligne du tiroir manager). */
export interface TrackPrestation {
  start: string | null;
  end: string | null;
  endsNextDay: boolean;
  minutes: number | null;
  durationSource: "ACTUAL_TIMES" | "ACTUAL_EXPLICIT" | "PRESENCE_SHARE" | null;
  status: "pending" | "validated";
  estimate?: { rateType: "HOURLY_RATE" | "CONSULTATION_FEE" } | null;
}

export function StatusLabel({ status, pill }: { status: TrackPrestation["status"]; pill?: boolean }) {
  const ok = status === "validated";
  return (
    <span className={`mh-status ${ok ? "mh-status--ok" : "mh-status--warn"} ${pill ? "mh-status--pill" : ""}`}>
      <span className="mh-status__dot" />
      {ok ? "Validé" : "En attente de validation"}
    </span>
  );
}

/** En-têtes de la piste : « DÉBUT · DURÉE TOTALE · FIN » (desktop) / « DÉBUT · TOTAL · FIN » (mobile). */
export function TrackHeads({ short }: { short?: boolean }) {
  return (
    <span className="mh-tl-heads" aria-hidden>
      <span>DÉBUT</span><span className="mh-tl-heads__total">{short ? "TOTAL" : "DURÉE TOTALE"}</span><span>FIN</span>
    </span>
  );
}

/** Piste des heures ENCODÉES : début | durée totale | fin. Toujours neutre, jamais le planifié. */
export function Track({ p }: { p: TrackPrestation }) {
  if (p.minutes === null) {
    return <span className="mh-tl mh-tl--missing">Heures non renseignées</span>;
  }
  return (
    <span className="mh-tl">
      {p.start ? <span className="mh-tl__val">{clock(p.start)}</span> : <span className="mh-tl__val mh-tl__val--none">—</span>}
      <span className="mh-tl__total"><ClockIcon size={13} strokeWidth={2.4} />{duration(p.minutes)}</span>
      {p.end ? (
        <span className="mh-tl__val">{clock(p.end)}{p.endsNextDay && <sup className="mh-tl__next">+1j</sup>}</span>
      ) : (
        <span className="mh-tl__val mh-tl__val--none">—</span>
      )}
    </span>
  );
}

/** Consultation valorisée au forfait : jamais une estimation horaire, jamais d'heures fictives. */
export function isFlatFee(p: Pick<TrackPrestation, "estimate">): boolean {
  return p.estimate?.rateType === "CONSULTATION_FEE";
}

/** D-156 — heures communes d'une prestation multi-salles : cette ligne porte la part imputée à la salle. */
export function isPresenceShare(p: Pick<TrackPrestation, "durationSource">): boolean {
  return p.durationSource === "PRESENCE_SHARE";
}
