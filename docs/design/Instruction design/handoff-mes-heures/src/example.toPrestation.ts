/**
 * EXEMPLE — adaptateur API → Prestation. Les noms de champs API sont SUPPOSÉS : réécris-le avec les vrais.
 * Ne pas copier tel quel.
 */
import type { Prestation } from './types';

interface ApiEncodedMission {
  id: number | string;
  encoding: {
    status: 'draft' | 'submitted' | 'validated' | string;
    actualStart: string;      // heure réelle encodée (ISO)
    actualEnd: string;        // heure réelle encodée (ISO) — la durée en découle (durationMinutes)
  };
  surgeon: { firstName: string; lastName: string } | null;
  site: { id: number | string; name: string; shortName?: string };
  /** Tarif horaire personnel de l'instrumentiste pour cette mission (en euros). Absent si non paramétré. */
  instrumentistHourlyRate?: number | null;
}

/** Retourne null pour tout ce qui ne doit PAS apparaître (brouillon, non encodé). */
export function toPrestation(m: ApiEncodedMission): Prestation | null {
  const st = m.encoding?.status;
  if (st !== 'submitted' && st !== 'validated') return null;
  return {
    missionId: String(m.id),
    start: m.encoding.actualStart,
    end: m.encoding.actualEnd,
    surgeonName: m.surgeon ? `Dr ${m.surgeon.firstName} ${m.surgeon.lastName}` : 'Chirurgien non renseigné',
    siteId: String(m.site.id),
    siteName: m.site.name,
    siteShortName: m.site.shortName,
    status: st === 'validated' ? 'validated' : 'pending',
    rateCents: m.instrumentistHourlyRate == null ? null : Math.round(m.instrumentistHourlyRate * 100)
  };
}

/**
 * `hourly` vient du PROFIL de l'instrumentiste (ex. `profile.remunerationType === 'hourly'`),
 * pas de la présence d'un tarif sur une prestation.
 */
export function isHourly(profile: { remunerationType?: string | null }): boolean {
  return profile.remunerationType === 'hourly';
}
