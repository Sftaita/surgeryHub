// D-180 — libellés du suivi manager des heures. Présentation seule : pourcentages, périodes et
// raisons d'indisponibilité viennent du serveur ; aucun calcul n'est refait ici.
import { euro, monthName, monthShort } from "../hours-shared/format";
import type { HoursComparison, InstrumentistHoursRow, InstrumentistRef, PersonalAverageComparison } from "./api/instrumentistHours.types";

const NBSP = " ";
const MINUS = "−";

/** 8 → "+8 %", -9 → "−9 %", 0 → "0 %", null → "—" (jamais un pourcentage artificiel). */
export function pctLabel(pct: number | null): string {
  if (pct === null) return "—";
  if (pct === 0) return "0" + NBSP + "%";
  return (pct > 0 ? "+" : MINUS) + Math.abs(pct) + NBSP + "%";
}

/** "3304.00" → "≈ 3 304,00 €" : toute estimation porte le signe ≈. */
export function approx(amount: string): string {
  return "≈" + NBSP + euro(amount);
}

export function fullName(i: InstrumentistRef): string {
  return [i.firstname, i.lastname].filter(Boolean).join(" ") || "Instrumentiste";
}

export function initials(i: InstrumentistRef): string {
  return ((i.firstname?.[0] ?? "") + (i.lastname?.[0] ?? "")).toUpperCase() || "?";
}

function monthYear(month: string): string {
  return monthName(month) + " " + month.slice(0, 4);
}

/** En-tête de colonne « vs sept. » ou, mois en cours, « vs 1–3 oct. ». */
export function previousMonthHeading(previousMonth: string, asOfDay: number | null): string {
  return asOfDay === null ? `vs ${monthShort(previousMonth)}` : `vs 1–${asOfDay} ${monthShort(previousMonth)}`;
}

/** Explication d'une comparaison au mois précédent indisponible. */
export function previousMonthReason(c: HoursComparison, previousMonth: string): string | null {
  if (c.available) return null;
  return c.reason === "NO_REFERENCE" ? `Pas d'historique en ${monthName(previousMonth)}` : `Aucune heure validée en ${monthName(previousMonth)}`;
}

/** Explication d'une moyenne personnelle indisponible — jamais une date d'entrée en activité. */
export function averageReason(c: PersonalAverageComparison): string | null {
  if (c.available) return null;
  if (c.reason === "NO_REFERENCE") {
    return c.observableSince ? `Pas d'historique avant ${monthYear(c.observableSince)}` : "Aucune prestation antérieure connue";
  }
  return "Aucune heure validée sur la période de référence";
}

/** « sur 4 mois » quand l'historique observable raccourcit la période de six mois. */
export function averagePeriodNote(c: PersonalAverageComparison): string | null {
  return c.monthsCounted > 0 && c.monthsCounted < 6 ? `sur ${c.monthsCounted} mois` : null;
}

/** « avr.–sept. » : période réellement comptée. */
export function averagePeriodRange(c: PersonalAverageComparison): string | null {
  if (!c.from || !c.to) return null;
  return c.from === c.to ? monthShort(c.from) : `${monthShort(c.from)}–${monthShort(c.to)}`;
}

/** Libellé accessible complet d'une évolution. */
export function deltaAria(pct: number | null, reference: string, reason: string | null): string {
  if (pct === null) return `Comparaison ${reference} indisponible${reason ? " : " + reason : ""}`;
  if (pct === 0) return `Stable par rapport à ${reference}`;
  return `${pct > 0 ? "En hausse" : "En baisse"} de ${Math.abs(pct)} % par rapport à ${reference}`;
}

/** Coût estimé validé d'une ligne : montant, « Tarif manquant » ou « — ». */
export function validatedCost(row: InstrumentistHoursRow): { text: string; missing: boolean } {
  if (row.estimateState === "UNRATED") return { text: "Tarif manquant", missing: true };
  if (row.estimate?.validatedAmount) return { text: approx(row.estimate.validatedAmount), missing: false };
  return { text: "—", missing: false };
}

export function encodedCost(row: InstrumentistHoursRow): { text: string; missing: boolean } {
  if (row.estimateState === "UNRATED") return { text: "Tarif manquant", missing: true };
  if (row.estimate) return { text: approx(row.estimate.encodedAmount), missing: false };
  return { text: "—", missing: false };
}

export function unratedNote(row: InstrumentistHoursRow): string | null {
  const n = row.estimate?.unratedCount ?? 0;
  return n > 0 ? `+ ${n} sans tarif` : null;
}
