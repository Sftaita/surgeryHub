export type EncodingState = 'À encoder' | 'En cours' | 'Soumis' | 'Validé';
/** Tonalité de l'écart horaire : pilote la couleur de la jauge et du libellé d'écart. */
export type GapTone = 'ok' | 'warn' | 'bad' | 'muted';

export interface MaterialLine {
  id: string;
  name: string;
  /** "Fabricant · Réf. XXX" */
  ref: string;
  qty: number;
}

export interface Intervention {
  id: string;
  name: string;
  firm: string;
  /** Délégué de la firme présent au bloc. */
  rep?: boolean;
  materials: MaterialLine[];
}

export interface EncodingStep {
  label: 'Brouillon' | 'Soumis' | 'Validé';
  /** "12/09 · 19:22", "en attente", "—" */
  when: string;
  done: boolean;
}

export interface MissionRow {
  id: number;
  /** Clé de regroupement, déjà formatée : "lun. 7 sept." */
  date: string;
  /** "08:00" */
  time: string;
  nurse: string;
  surgeon: string;
  /** "CHIREC — Hôpital Delta · Bloc opératoire" */
  site: string;
  state: EncodingState;
  late: boolean;

  planRange: string;   // "08:00 → 18:00"
  planTotal: string;   // "10h00"
  realRange: string;   // "08:15 → 17:00" | "non démarré"
  realTotal: string;   // "8h45" | "—"
  /** Part du planifié réellement prestée, en %. */
  realPct: string;     // "87%"
  gap: string;         // "−1h15 vs planifié"
  gapTone: GapTone;
  hoursNote: string;

  steps: EncodingStep[];
  interventions: Intervention[];
  /** Montant facturable formaté, "—" tant qu'il n'est pas calculable. */
  finance: string;
  footTitle: string;
  footSub: string;
  /** État du bouton de validation du tiroir. */
  cta: 'on' | 'off' | 'done';
}

export interface FilterOption { label: string; count: number; on: boolean; }
export interface FilterGroup { label: string; options: FilterOption[]; }
