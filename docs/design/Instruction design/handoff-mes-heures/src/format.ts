const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const DOW = ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'];
const NNBSP = '\u202f';
const NBSP = '\u00a0';
const pad = (n: number) => String(n).padStart(2, '0');

/** 4950 → "82h30" ; 0 → "0h00" */
export function duration(minutes: number): string {
  const m = Math.max(0, Math.round(minutes));
  return Math.floor(m / 60) + 'h' + pad(m % 60);
}

/** true si la prestation passe minuit (fin datée d'un autre jour, ou fin avant le début) → marqueur « +1j ». */
export function endsNextDay(start: string, end: string): boolean {
  const s = new Date(start);
  const e = new Date(end);
  return e.toDateString() !== s.toDateString() || e.getTime() < s.getTime();
}

/** "2026-10-29T07:30:00" → "07h30" */
export function clock(iso: string): string {
  const d = new Date(iso);
  return pad(d.getHours()) + 'h' + pad(d.getMinutes());
}

/** start/end → "07h30 → 16h00" */
export function timeRange(start: string, end: string): string {
  return clock(start) + ' → ' + clock(end);
}

/** 348275 → "3 482,75 €" (espace fine insécable, virgule décimale) */
export function euro(cents: number): string {
  const [i, d] = (cents / 100).toFixed(2).split('.');
  return i.replace(/\B(?=(\d{3})+(?!\d))/g, NNBSP) + ',' + d + NBSP + '€';
}

/** 4200 → "42,00 €/h" */
export function hourlyRate(cents: number): string {
  return euro(cents) + '/h';
}

/** 4000, 4500 → "40 à 45 €/h" ; 4200, 4200 → "42 €/h" */
export function rateRange(minCents: number, maxCents: number): string {
  const short = (c: number) => (c % 100 === 0 ? String(c / 100) : (c / 100).toFixed(2).replace('.', ','));
  return minCents === maxCents ? short(minCents) + NBSP + '€/h' : short(minCents) + ' à ' + short(maxCents) + NBSP + '€/h';
}

/** (2026, 10) → "Octobre 2026" */
export function monthLabel(year: number, month: number): string {
  const m = MONTHS[month - 1];
  return m[0].toUpperCase() + m.slice(1) + ' ' + year;
}

/** (10) → "octobre" — pour « Aucune prestation encodée en octobre » */
export function monthName(month: number): string {
  return MONTHS[month - 1];
}

/** "2026-10-29T…" → "Jeu." */
export function dowShort(iso: string): string {
  return DOW[new Date(iso).getDay()];
}

/** "2026-10-29T…" → 29 */
export function dayOfMonth(iso: string): number {
  return new Date(iso).getDate();
}

/** "2026-10-29T…" → "Jeu. 29 oct." */
export function dateLong(iso: string): string {
  const d = new Date(iso);
  return DOW[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()];
}

/** n → "1 prestation" / "10 prestations" */
export function prestationCount(n: number): string {
  return n + (n > 1 ? ' prestations' : ' prestation');
}
