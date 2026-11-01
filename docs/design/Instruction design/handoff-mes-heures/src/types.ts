/** Statut d'une prestation dans « Mes heures ». Seules les heures soumises ou validées existent ici. */
export type PrestationStatus = 'pending' | 'validated';

/** Une prestation encodée par l'instrumentiste (jamais une mission seulement planifiée). */
export interface Prestation {
  missionId: string;
  /** Heure de début réellement encodée (ISO). */
  start: string;
  /** Heure de fin réellement encodée (ISO, peut être le lendemain). La durée se calcule avec `durationMinutes()`. */
  end: string;
  surgeonName: string;
  siteId: string;
  siteName: string;
  /** Libellé court pour les puces de filtre (« Brugmann »). Défaut : siteName. */
  siteShortName?: string;
  status: PrestationStatus;
  /** Tarif horaire personnel appliqué à CETTE prestation, en centimes. null = aucun tarif. */
  rateCents: number | null;
}

export interface SiteOption {
  id: string;
  name: string;
  shortName: string;
  count: number;
}

export interface RateLine {
  siteName: string;
  rateCents: number;
  minutes: number;
  amountCents: number;
}

export interface Estimate {
  encodedCents: number;
  validatedCents: number;
  /** Détail par (site, tarif), trié par montant décroissant. */
  lines: RateLine[];
  minRateCents: number;
  maxRateCents: number;
  /** Prestations sans tarif, exclues de l'estimation. */
  unratedCount: number;
}

/** Totaux d'un ensemble de prestations — le mois entier OU le site filtré. */
export interface Summary {
  count: number;
  encodedMinutes: number;
  validatedMinutes: number;
  pendingMinutes: number;
  /** Part des heures encodées déjà validées, arrondie (0–100). */
  validatedPct: number;
  /** null si l'instrumentiste n'a pas de rémunération horaire ou si aucune prestation n'a de tarif. */
  estimate: Estimate | null;
}

export type SiteFilter = 'all' | string;
