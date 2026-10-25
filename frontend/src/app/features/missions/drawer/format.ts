import type { FinanceGroup } from './types';

const BRUSSELS_TZ = 'Europe/Brussels';

const timeFormat = new Intl.DateTimeFormat('fr-BE', { timeZone: BRUSSELS_TZ, hour: '2-digit', minute: '2-digit' });
const dayFormat = new Intl.DateTimeFormat('fr-BE', { timeZone: BRUSSELS_TZ, weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
const shortFormat = new Intl.DateTimeFormat('fr-BE', { timeZone: BRUSSELS_TZ, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

/** '14h00' (Europe/Brussels). */
export const hh = (iso: string) => timeFormat.format(new Date(iso)).replace(':', 'h');
/** '23/10 21:13' (Europe/Brussels). */
export const shortDateTime = (iso: string | null | undefined) => (iso ? shortFormat.format(new Date(iso)).replace(',', '') : '—');
/** 'Jeu. 23 octobre 2026' (Europe/Brussels). */
export function dayLabel(iso: string) {
  const s = dayFormat.format(new Date(iso));
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export const minutesBetween = (startIso: string, endIso: string) => Math.round((new Date(endIso).getTime() - new Date(startIso).getTime()) / 60000);
export const fmtDur = (d: number) => `${Math.floor(d / 60)}h${String(d % 60).padStart(2, '0')}`;
export const eur = (n: number, currency = 'EUR') => n.toFixed(2).replace('.', ',') + (currency === 'EUR' ? ' €' : ` ${currency}`);
export const initials = (full: string) => full.replace(/^Dr\s+/, '').trim().split(/[\s-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

export type Tone = { bg: string; fg: string };
export const OK: Tone = { bg: 'var(--green-50)', fg: 'var(--green-800)' };
export const WARN: Tone = { bg: 'var(--amber-50)', fg: 'var(--amber-700)' };
export const PLAN: Tone = { bg: 'var(--gray-150)', fg: 'var(--gray-700)' };

/**
 * Compare planifié / réel — COULEUR D'AFFICHAGE uniquement, à partir des valeurs servies
 * (horaire planifié de la mission, heures prestées de GET .../execution).
 * Chaque valeur réelle porte sa propre couleur : début plus tôt, fin plus tard,
 * total plus long → ambre ; sinon vert.
 */
export function compareHours(planStart: string, planEnd: string, real: { start: string | null; end: string | null; minutes: number } | null) {
  const pD = minutesBetween(planStart, planEnd);
  const base = { pStart: hh(planStart), pEnd: hh(planEnd), pTotal: fmtDur(pD) };
  if (!real) return { ...base, started: false as const, label: 'Pas encore réalisée', labelFg: 'var(--gray-400)' };
  const rD = real.minutes;
  return {
    ...base, started: true as const,
    rStart: real.start ? { v: hh(real.start), ...(new Date(real.start) < new Date(planStart) ? WARN : OK) } : { v: '—', ...PLAN },
    rEnd: real.end ? { v: hh(real.end), ...(new Date(real.end) > new Date(planEnd) ? WARN : OK) } : { v: '—', ...PLAN },
    rTotal: { v: fmtDur(rD), ...(rD > pD ? WARN : OK) },
    label: rD > pD ? `Dépasse le planifié de ${fmtDur(rD - pD)}` : 'Conforme au planifié',
    labelFg: rD > pD ? 'var(--amber-700)' : 'var(--green-700)',
  };
}

/* ------------------------------------------------------------------
 * SIGNE FINANCIER — AFFICHAGE UNIQUEMENT.
 * L'API renvoie des montants positifs. On n'écrit JAMAIS ces signes
 * dans les données : firme = entrée (+, vert), instrumentiste = sortie (−, rouge).
 * ------------------------------------------------------------------ */
export const signOf = (g: FinanceGroup) => (g.beneficiary === 'instrumentiste' ? -1 : 1);
export const signed = (amount: number, sign: 1 | -1, currency = 'EUR') => `${sign < 0 ? '−' : '+'} ${eur(Math.abs(amount), currency)}`;
export const signFg = (sign: number) => (sign < 0 ? 'var(--red-600)' : 'var(--green-700)');
export const groupTotal = (g: FinanceGroup) => g.lines.reduce((t, l) => t + l.total, 0);
export const balanceOf = (groups: FinanceGroup[]) => groups.reduce((t, g) => t + signOf(g) * groupTotal(g), 0);
