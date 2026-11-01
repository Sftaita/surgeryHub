// D-179 — formats d'affichage de « Mes heures » (handoff révision 3). Présentation seule :
// aucune durée ni aucun montant n'est calculé ici, tout vient de GET /api/me/hours.

const MONTHS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"];
const MONTHS_SHORT = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
const DOW = ["Dim.", "Lun.", "Mar.", "Mer.", "Jeu.", "Ven.", "Sam."];
const NNBSP = " ";
const NBSP = " ";
const pad = (n: number) => String(n).padStart(2, "0");

/** 4950 → "82h30" ; 0 → "0h00" */
export function duration(minutes: number): string {
  const m = Math.max(0, Math.round(minutes));
  return Math.floor(m / 60) + "h" + pad(m % 60);
}

/**
 * "2026-10-29T07:30:00+01:00" → "07h30". Lit l'heure murale portée par la chaîne (le serveur
 * l'émet en heure de Bruxelles), indépendamment du fuseau du navigateur.
 */
export function clock(iso: string): string {
  const hhmm = iso.slice(11, 16);
  return hhmm.replace(":", "h");
}

/** "3482.75" → "3 482,75 €" (espace fine insécable, virgule décimale). */
export function euro(amount: string): string {
  const [i, d = "00"] = Number(amount).toFixed(2).split(".");
  return i.replace(/\B(?=(\d{3})+(?!\d))/g, NNBSP) + "," + d + NBSP + "€";
}

/** "42.00" → "42,00 €/h" */
export function hourlyRate(amount: string): string {
  return euro(amount) + "/h";
}

/** "40.00", "45.00" → "40 à 45 €/h" ; "42.00", "42.00" → "42 €/h" */
export function rateRange(min: string, max: string): string {
  const short = (a: string) => {
    const n = Number(a);
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(".", ",");
  };
  return Number(min) === Number(max) ? short(min) + NBSP + "€/h" : short(min) + " à " + short(max) + NBSP + "€/h";
}

function parseDay(day: string): Date {
  const [y, m, d] = day.split("-").map(Number);
  return new Date(y, m - 1, d);
}

/** "2026-10" → "Octobre 2026" */
export function monthLabel(month: string): string {
  const [y, m] = month.split("-").map(Number);
  const name = MONTHS[m - 1];
  return name[0].toUpperCase() + name.slice(1) + " " + y;
}

/** "2026-10" → "octobre" */
export function monthName(month: string): string {
  return MONTHS[Number(month.split("-")[1]) - 1];
}

/** "2026-10-29" → "Jeu." */
export function dowShort(day: string): string {
  return DOW[parseDay(day).getDay()];
}

/** "2026-10-29" → 29 */
export function dayOfMonth(day: string): number {
  return parseDay(day).getDate();
}

/** "2026-10-29" → "Jeu. 29 oct." */
export function dateLong(day: string): string {
  const d = parseDay(day);
  return DOW[d.getDay()] + " " + d.getDate() + " " + MONTHS_SHORT[d.getMonth()];
}

/** n → "1 prestation" / "10 prestations" */
export function prestationCount(n: number): string {
  return n + (n > 1 ? " prestations" : " prestation");
}

/** Mois courant du navigateur, "YYYY-MM". */
export function currentMonth(now: Date = new Date()): string {
  return now.getFullYear() + "-" + pad(now.getMonth() + 1);
}

/** ("2026-01", -1) → "2025-12" */
export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split("-").map(Number);
  const t = y * 12 + (m - 1) + delta;
  return Math.floor(t / 12) + "-" + pad((t % 12) + 1);
}
