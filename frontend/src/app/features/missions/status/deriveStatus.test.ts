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
  it('Validée, encodage verrouillé — date de validation + initiales, sans heure', () =>
    expect(s({ encoding: 'validated', locked: true, lockLevel: 'ENCODING', lockedAt: '2026-10-24T08:39:00+02:00', lockedBy: 'Samy Ftaita' }).caption).toBe('Encodage verrouillé le 24/10 · S.F.'));
  it('Validée sans verrou servi — jamais « modifiable » (une validée se rouvre d’abord)', () =>
    expect(s({ encoding: 'validated', locked: false }).caption).toBe('Encodage non verrouillé'));
  it('Annulée l’emporte sur tout', () =>
    expect(s({ cancelled: true, cancelledAt: '2026-10-28T10:00:00+01:00', encoding: 'validated', locked: true }).key).toBe('annulee'));
});

const overview = (o: Partial<MissionEncodingOverview>): MissionEncodingOverview => ({
  state: 'UPCOMING', stateLabel: '', finalizedBy: null, validatedBy: null, locks: [], ...o,
});
const instrumentist = { id: 7, firstname: 'Ana', lastname: 'B' } as never;
const ENCODING_LOCK = { level: 'ENCODING' as const, label: 'Encodage verrouillé', reason: '' };
const FINANCIAL_LOCK = { level: 'FINANCIAL_CALCULATION' as const, label: 'Calcul financier verrouillé', reason: '' };
const DOCUMENT_LOCK = { level: 'DOCUMENT' as const, label: 'Document émis', reason: '' };
const manager = { name: 'Samy Ftaita', role: 'MANAGER' as const, at: '2026-10-24T08:39:00+02:00' };
const surgeon = { name: 'Dr X', role: 'SURGEON' as const, at: '2026-10-23T21:13:00+02:00' };

describe('missionDisplayStatus — faits backend uniquement', () => {
  it('OPEN + NOT_DECLARED → Non couverte (grise)', () =>
    expect(missionDisplayStatus({ status: 'OPEN', instrumentist: null, encoding: overview({ state: 'NOT_DECLARED' }) }).key).toBe('noncouverte'));
  it('ASSIGNED + TO_ENCODE → À encoder', () =>
    expect(missionDisplayStatus({ status: 'ASSIGNED', instrumentist, encoding: overview({ state: 'TO_ENCODE' }) }).key).toBe('aencoder'));
  it('IN_PROGRESS (cron D-064) + UPCOMING → En cours', () =>
    expect(missionDisplayStatus({ status: 'IN_PROGRESS', instrumentist, encoding: overview({}) }).key).toBe('encours'));
  it('IN_PROGRESS + traces d’encodage → Encodage en cours', () =>
    expect(missionDisplayStatus({ status: 'IN_PROGRESS', instrumentist, encoding: overview({ state: 'IN_PROGRESS' }) }).key).toBe('encodage'));
  it('CLOSED → Validée, « Mission clôturée » (libellé backend, sans date de substitution)', () =>
    expect(missionDisplayStatus({
      status: 'CLOSED', instrumentist,
      encoding: overview({ state: 'LOCKED', validatedBy: { name: 'Samy Ftaita', role: 'MANAGER', at: '2026-10-24T08:39:00+02:00' }, locks: [ENCODING_LOCK, { level: 'CLOSED', label: 'Mission clôturée', reason: '' }] }),
    })).toEqual({ key: 'validee', label: 'Validée', caption: 'Mission clôturée' }));
  it('SUBMITTED par le chirurgien → À valider, dit dans la ligne grise', () =>
    expect(missionDisplayStatus({
      status: 'SUBMITTED', instrumentist: null, submittedAt: '2026-10-23T21:13:00+02:00',
      encoding: overview({ state: 'SUBMITTED', finalizedBy: { name: 'Dr X', role: 'SURGEON', at: '2026-10-23T21:13:00+02:00' } }),
    }).caption).toBe('Soumise par le chirurgien le 23/10 à 21h13'));
  it('A — encodage validé verrouillé : « Encodage verrouillé le … · S.F. »', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist, encoding: overview({ state: 'VALIDATED', validatedBy: manager, locks: [ENCODING_LOCK] }) }).caption)
      .toBe('Encodage verrouillé le 24/10 · S.F.'));
  it('A — calcul financier verrouillé : le verrou le plus restrictif, jamais confondu avec l’encodage', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist, encoding: overview({ state: 'VALIDATED', validatedBy: manager, locks: [ENCODING_LOCK, FINANCIAL_LOCK] }) }).caption)
      .toBe('Calcul financier verrouillé'));
  it('A — document émis : prime sur le calcul verrouillé', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist, encoding: overview({ state: 'LOCKED', validatedBy: manager, locks: [ENCODING_LOCK, FINANCIAL_LOCK, DOCUMENT_LOCK] }) }).caption)
      .toBe('Document émis'));
  it('A — validée sans verrou servi : « Encodage non verrouillé »', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist, encoding: overview({ state: 'VALIDATED', validatedBy: manager }) }).caption)
      .toBe('Encodage non verrouillé'));
  it('B — validée après finalisation par le chirurgien : verrou + seconde ligne', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist: null, encoding: overview({ state: 'VALIDATED', finalizedBy: surgeon, validatedBy: manager, locks: [ENCODING_LOCK] }) }))
      .toEqual({ key: 'validee', label: 'Validée', caption: 'Encodage verrouillé le 24/10 · S.F.', detail: 'Encodage terminé par le chirurgien' }));
  it('B — validée après finalisation par l’instrumentiste : aucune seconde ligne', () =>
    expect(missionDisplayStatus({ status: 'VALIDATED', instrumentist, encoding: overview({ state: 'VALIDATED', finalizedBy: { ...surgeon, role: 'INSTRUMENTIST' }, validatedBy: manager, locks: [ENCODING_LOCK] }) }).detail)
      .toBeUndefined());
  it('C — OPEN commencée (started servi), pas terminée → Non couverte, état d’encodage inchangé', () =>
    expect(missionDisplayStatus({ status: 'OPEN', instrumentist: null, encoding: overview({ state: 'NOT_APPLICABLE', started: true }) }).key).toBe('noncouverte'));
  it('C — OPEN à venir (started false) → Publiée', () =>
    expect(missionDisplayStatus({ status: 'OPEN', instrumentist: null, encoding: overview({ state: 'NOT_APPLICABLE', started: false }) }).key).toBe('publiee'));
  it('C — sans bloc encoding (non-manager), jamais d’horloge client : Publiée', () =>
    expect(missionDisplayStatus({ status: 'OPEN', instrumentist: null, encoding: null }).key).toBe('publiee'));
  it('C — ASSIGNED commencée avant le cron de démarrage → En cours', () =>
    expect(missionDisplayStatus({ status: 'ASSIGNED', instrumentist, encoding: overview({ state: 'UPCOMING', started: true }) }).key).toBe('encours'));
  it('D — annulée / brouillon d’encodage sans date servie : aucune date inventée', () => {
    expect(missionDisplayStatus({ status: 'CANCELLED', instrumentist, encoding: overview({ state: 'NOT_APPLICABLE' }) })).toEqual({ key: 'annulee', label: 'Annulée', caption: '' });
    expect(missionDisplayStatus({ status: 'ENCODING_IN_PROGRESS', instrumentist, encoding: overview({ state: 'IN_PROGRESS', started: true }) })).toEqual({ key: 'encodage', label: 'Encodage en cours', caption: '' });
  });
  it('DECLARED → libellé backend, pastille neutre', () =>
    expect(missionDisplayStatus({ status: 'DECLARED', instrumentist: null, encoding: null })).toEqual({ key: 'autre', label: 'Déclarée', caption: '' }));
});
