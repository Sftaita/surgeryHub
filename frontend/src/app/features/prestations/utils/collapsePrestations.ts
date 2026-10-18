import type { Mission } from "../../missions/api/missions.types";

export type PlanningRow = {
  /** Mission représentante (la première de la prestation) — sert de point d'entrée. */
  mission: Mission;
  /** Missions de la prestation présentes dans la liste (une seule si la mission est seule). */
  members: Mission[];
};

/**
 * D-156 — une prestation regroupée multi-salles = une seule ligne dans un planning
 * personnel (« Dr Deltour — 2 missions — 07h45–18h00 »), jamais deux demandes d'encodage.
 * Purement présentationnel : le regroupement est celui du serveur (`mission.prestation`),
 * jamais recalculé ici ; on ne fait que réunir les lignes portant la même clé.
 */
export function collapsePrestations(missions: Mission[]): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const byKey = new Map<string, PlanningRow>();
  for (const mission of missions) {
    const key = mission.prestation?.key;
    if (!key) {
      rows.push({ mission, members: [mission] });
      continue;
    }
    const existing = byKey.get(key);
    if (existing) {
      existing.members.push(mission);
      continue;
    }
    const row = { mission, members: [mission] };
    byKey.set(key, row);
    rows.push(row);
  }
  return rows;
}

/** Créneau affiché pour une ligne : celui de la prestation (serveur) ou de la mission seule. */
export function rowTimes(row: PlanningRow): { startAt?: string; endAt?: string } {
  const p = row.mission.prestation;
  return p ? { startAt: p.plannedStartAt, endAt: p.plannedEndAt } : { startAt: row.mission.startAt, endAt: row.mission.endAt };
}
