import { describe, it, expect } from 'vitest';
import { deriveStatus } from './deriveStatus';
import { missionDisplayStatus } from './toStatusInput';
import type { MissionStatusInput } from './types';
import type { MissionEncodingOverview } from '../api/missions.types';

const base: MissionStatusInput = {
  published: true,
  cancelled: false,
  hasInstrumentiste: true,
  phase: 'ended',
  encoding: 'none',
};
const s = (o: Partial<MissionStatusInput>) => deriveStatus({ ...base, ...o });

describe('deriveStatus — un seul statut par mission', () => {
  it('Brouillon', () => expect(s({ published: false })).toEqual({ key: 'brouillon', label: 'Brouillon', caption: 'Non publiée' }));
  it('Publiée (future, sans instrumentiste)', () =>
    expect(s({ hasInstrumentiste: false, phase: 'upcoming' }).key).toBe('publiee'));
  it('Assignée (future, avec instrumentiste)', () =>
    expect(s({ phase: 'upcoming' }).key).toBe('assignee'));
  it('En cours — sans ligne secondaire', () =>
    expect(s({ phase: 'started' })).toEqual({ key: 'encours', label: 'En cours', caption: '' }));
  it('En cours + encodage commencé → Encodage en cours (encodage possible dès le début)', () =>
    expect(s({ phase: 'started', encoding: 'draft' }).key).toBe('encodage'));
  it('Non couverte (passée, sans instrumentiste)', () =>
    expect(s({ hasInstrumentiste: false }).key).toBe('noncouverte'));
  it('À encoder (passée, rien encodé)', () =>
    expect(s({}).label).toBe('À encoder'));
  it('Encodage en cours', () =>
    expect(s({ encoding: 'draft', encodingDraftAt: '2026-10-23T19:00:00+02:00' }).caption).toBe('Brouillon enregistré le 23/10'));
  it('À valider (soumise)', () =>
    expect(s({ encoding: 'submitted', submittedAt: '2026-10-23T21:13:00+02:00' })).toEqual({ key: 'soumise', label: 'À valider', caption: 'Soumise le 23/10 à 21h13' }));
  it('Validée verrouillée — date + initiales, sans heure', () =>
    expect(s({ encoding: 'validated', locked: true, lockedAt: '2026-10-24T08:39:00+02:00', lockedBy: 'Samy Ftaita' }).caption).toBe('Verrouillée le 24/10 · S.F.'));
  it('Validée non verrouillée', () =>
    expect(s({ encoding: 'validated', locked: false }).caption).toBe('Non verrouillée · modifiable'));
  it('Annulée l’emporte sur tout', () =>
    expect(s({ cancelled: true, cancelledAt: '2026-10-28T10:00:00+01:00', encoding: 'validated', locked: true }).key).toBe('annulee'));
});

const overview = (o: Partial<MissionEncodingOverview>): MissionEncodingOverview => ({
  state: 'UPCOMING', stateLabel: '', finalizedBy: null, validatedBy: null, locks: [], ...o,
});
const instrumentist = { id: 7, firstname: 'Ana', lastname: 'B' } as never;

describe('missionDisplayStatus — faits backend uniquement', () => {
  it('OPEN + NOT_DECLARED → Non couverte (grise)', () =>
    expect(missionDisplayStatus({ status: 'OPEN', instrumentist: null, encoding: overview({ state: 'NOT_DECLARED' }) }).key).toBe('noncouverte'));
  it('ASSIGNED + TO_ENCODE → À encoder', () =>
    expect(missionDisplayStatus({ status: 'ASSIGNED', instrumentist, encoding: overview({ state: 'TO_ENCODE' }) }).key).toBe('aencoder'));
  it('IN_PROGRESS (cron D-064) + UPCOMING → En cours', () =>
    expect(missionDisplayStatus({ status: 'IN_PROGRESS', instrumentist, encoding: overview({}) }).key).toBe('encours'));
  it('IN_PROGRESS + traces d’encodage → Encodage en cours', () =>
    expect(missionDisplayStatus({ status: 'IN_PROGRESS', instrumentist, encoding: overview({ state: 'IN_PROGRESS' }) }).key).toBe('encodage'));
  it('CLOSED → Validée verrouillée, validateur en initiales', () =>
    expect(missionDisplayStatus({
      status: 'CLOSED', instrumentist,
      encoding: overview({ state: 'LOCKED', validatedBy: { name: 'Samy Ftaita', role: 'MANAGER', at: '2026-10-24T08:39:00+02:00' }, locks: [{ level: 'CLOSED', label: '', reason: '' }] }),
    })).toEqual({ key: 'validee', label: 'Validée', caption: 'Verrouillée le 24/10 · S.F.' }));
  it('SUBMITTED par le chirurgien → À valider, dit dans la ligne grise', () =>
    expect(missionDisplayStatus({
      status: 'SUBMITTED', instrumentist: null, submittedAt: '2026-10-23T21:13:00+02:00',
      encoding: overview({ state: 'SUBMITTED', finalizedBy: { name: 'Dr X', role: 'SURGEON', at: '2026-10-23T21:13:00+02:00' } }),
    }).caption).toBe('Soumise par le chirurgien le 23/10 à 21h13'));
  it('DECLARED → libellé backend, pastille neutre', () =>
    expect(missionDisplayStatus({ status: 'DECLARED', instrumentist: null, encoding: null })).toEqual({ key: 'autre', label: 'Déclarée', caption: '' }));
});
