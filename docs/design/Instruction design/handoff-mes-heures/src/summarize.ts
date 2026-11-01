import type { Estimate, Prestation, RateLine, SiteFilter, SiteOption, Summary } from './types';

const DAY_MINUTES = 24 * 60;

/**
 * Durée d'une prestation en minutes = fin − début, à partir des heures réellement encodées.
 * Passage après minuit : si la fin tombe avant le début (fin encodée sur la date du début), on ajoute 24 h.
 * Une fin déjà datée du lendemain donne directement le bon résultat.
 */
export function durationMinutes(p: Pick<Prestation, 'start' | 'end'>): number {
  const diff = Math.round((new Date(p.end).getTime() - new Date(p.start).getTime()) / 60000);
  return diff < 0 ? diff + DAY_MINUTES : diff;
}

/** Montant d'une prestation en centimes : SON tarif × SA durée, arrondi au centime. */
export function amountCents(p: Prestation): number | null {
  return p.rateCents == null ? null : Math.round((durationMinutes(p) * p.rateCents) / 60);
}

/** Plus récente en premier (ordre de la liste). */
export function sortRecentFirst(items: Prestation[]): Prestation[] {
  return [...items].sort((a, b) => b.start.localeCompare(a.start));
}

const sumMinutes = (items: Prestation[]) => items.reduce((t, p) => t + durationMinutes(p), 0);

/** Sites présents dans le MOIS ENTIER (puces de filtre), triés par nombre de prestations. */
export function listSites(items: Prestation[]): SiteOption[] {
  const siteMap = new Map<string, SiteOption>();
  for (const p of items) {
    const s = siteMap.get(p.siteId);
    if (s) s.count++;
    else siteMap.set(p.siteId, { id: p.siteId, name: p.siteName, shortName: p.siteShortName ?? p.siteName, count: 1 });
  }
  return [...siteMap.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
}

/** Le filtre par site n'apparaît qu'à partir de 2 sites dans le mois. */
export function showSiteFilter(sites: SiteOption[]): boolean {
  return sites.length >= 2;
}

/** Prestations du périmètre affiché (mois entier ou un site), plus récente en premier. */
export function filterBySite(items: Prestation[], filter: SiteFilter): Prestation[] {
  return sortRecentFirst(filter === 'all' ? items : items.filter((p) => p.siteId === filter));
}

/**
 * Totaux du périmètre affiché. Pur, sans date courante.
 * Appelé sur `filterBySite(...)` : compteurs, estimation et liste reflètent TOUS le même filtre.
 * Heures encodées = en attente + validées.
 */
export function summarize(items: Prestation[], hourly: boolean): Summary {
  const encodedMinutes = sumMinutes(items);
  const validatedMinutes = sumMinutes(items.filter((p) => p.status === 'validated'));
  return {
    count: items.length,
    encodedMinutes,
    validatedMinutes,
    pendingMinutes: encodedMinutes - validatedMinutes,
    validatedPct: encodedMinutes ? Math.round((validatedMinutes / encodedMinutes) * 100) : 0,
    estimate: hourly ? buildEstimate(items) : null
  };
}

function buildEstimate(items: Prestation[]): Estimate | null {
  const rated = items.filter((p) => p.rateCents != null);
  if (!rated.length) return null;
  const lineMap = new Map<string, RateLine>();
  let encodedCents = 0;
  let validatedCents = 0;
  for (const p of rated) {
    const a = amountCents(p)!;
    const m = durationMinutes(p);
    encodedCents += a;
    if (p.status === 'validated') validatedCents += a;
    const key = p.siteId + '|' + p.rateCents;
    const l = lineMap.get(key);
    if (l) { l.minutes += m; l.amountCents += a; }
    else lineMap.set(key, { siteName: p.siteName, rateCents: p.rateCents!, minutes: m, amountCents: a });
  }
  const rates = rated.map((p) => p.rateCents!);
  return {
    encodedCents,
    validatedCents,
    lines: [...lineMap.values()].sort((a, b) => b.amountCents - a.amountCents),
    minRateCents: Math.min(...rates),
    maxRateCents: Math.max(...rates),
    unratedCount: items.length - rated.length
  };
}
