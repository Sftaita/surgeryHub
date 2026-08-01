import React, { useState } from 'react';
import { Modal } from './Modal';

interface NewInterventionSheetProps {
  onClose: () => void;
  onCreate: (name: string) => void;
}

export function NewInterventionSheet({ onClose, onCreate }: NewInterventionSheetProps) {
  const [name, setName] = useState('');
  return (
    <Modal title="Nouvelle intervention" onClose={onClose} width={400}>
      <div className="form-stack">
        <div className="field">
          <label className="field__label">Nom de l&apos;intervention *</label>
          <input className="field__input" autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex. Ménisque médial" />
        </div>
      </div>
      <button type="button" className="btn btn--primary btn--full" disabled={!name.trim()} onClick={() => onCreate(name.trim())}>
        Ajouter l&apos;intervention
      </button>
    </Modal>
  );
}
