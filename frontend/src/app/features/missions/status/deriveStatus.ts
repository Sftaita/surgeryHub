import type { MissionStatus, MissionStatusInput } from './types';
import { dayMonth, hhmm, initials } from './format';

/**
 * Calcule LE statut affiché d'une mission. Ordre de priorité figé — ne pas réordonner.
 * Fonction pure, sans horloge : la phase vient du backend (voir MissionPhase).
 */
export function deriveStatus(m: MissionStatusInput): MissionStatus {
  // 1. Annulée — l'emporte sur tout
  if (m.cancelled) {
    return { key: 'annulee', label: 'Annulée', caption: m.cancelledAt ? 'Annulée le ' + dayMonth(m.cancelledAt) : '' };
  }
  // 2. Brouillon — mission pas encore publiée
  if (!m.published) {
    return { key: 'brouillon', label: 'Brouillon', caption: 'Non publiée' };
  }
  // 3. Encodage : il prime sur le calendrier dès qu'il existe
  if (m.encoding === 'validated') {
    // D-172 — une finalisation par le chirurgien reste dite une fois la mission validée.
    const detail = m.submittedBySurgeon ? { detail: 'Encodage terminé par le chirurgien' } : {};
    if (!m.locked) {
      // Une mission validée ne se corrige qu'après réouverture : jamais « modifiable ».
      return { key: 'validee', label: 'Validée', caption: 'Encodage non verrouillé', ...detail };
    }
    // Verrou au-delà de l'encodage : son libellé backend, sans date ni auteur de substitution.
    if (m.lockLevel && m.lockLevel !== 'ENCODING') {
      return { key: 'validee', label: 'Validée', caption: m.lockLabel ?? 'Verrouillée', ...detail };
    }
    let cap = 'Encodage verrouillé';
    if (m.lockedAt) cap += ' le ' + dayMonth(m.lockedAt);
    if (m.lockedBy) cap += ' · ' + initials(m.lockedBy);
    return { key: 'validee', label: 'Validée', caption: cap, ...detail };
  }
  if (m.encoding === 'submitted') {
    // D-172 — une finalisation par le chirurgien est toujours dite.
    const by = m.submittedBySurgeon ? ' par le chirurgien' : '';
    return {
      key: 'soumise',
      label: 'À valider',
      caption: m.submittedAt ? 'Soumise' + by + ' le ' + dayMonth(m.submittedAt) + ' à ' + hhmm(m.submittedAt) : 'Soumise' + by,
    };
  }
  if (m.encoding === 'draft') {
    return {
      key: 'encodage',
      label: 'Encodage en cours',
      caption: m.encodingDraftAt ? 'Brouillon enregistré le ' + dayMonth(m.encodingDraftAt) : '',
    };
  }
  // 4. Pas d'encodage : dépend de la phase (servie par le backend) et de la couverture
  if (m.phase === 'upcoming') {
    return m.hasInstrumentiste
      ? { key: 'assignee', label: 'Assignée', caption: 'Instrumentiste confirmé' }
      : { key: 'publiee', label: 'Publiée', caption: 'En attente d’un instrumentiste' };
  }
  if (!m.hasInstrumentiste) {
    return { key: 'noncouverte', label: 'Non couverte', caption: 'Aucun instrumentiste assigné' };
  }
  if (m.phase === 'started') {
    return { key: 'encours', label: 'En cours', caption: '' };
  }
  return { key: 'aencoder', label: 'À encoder', caption: 'Encodage non commencé' };
}
