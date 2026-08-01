import React, { useMemo, useState } from 'react';
import { CatalogMaterial } from '../types';
import { Modal } from './Modal';

interface MaterialSearchSheetProps {
  interventionName: string;
  catalog: CatalogMaterial[];
  onClose: () => void;
  onAdd: (m: CatalogMaterial) => void;
  onAddNotFound: (name: string, reference: string) => void;
}

/**
 * Single search field, dynamic filter as you type, horizontal scrollable
 * brand chips (only brands with a matching result stay visible), one click
 * on a result adds it immediately — no confirmation step. "Matériel
 * introuvable" switches the same sheet to a small 2-field form (exceptional
 * path, never the default).
 */
export function MaterialSearchSheet({ interventionName, catalog, onClose, onAdd, onAddNotFound }: MaterialSearchSheetProps) {
  const [query, setQuery] = useState('');
  const [brand, setBrand] = useState<string | null>(null);
  const [notFoundMode, setNotFoundMode] = useState(false);
  const [nfName, setNfName] = useState('');
  const [nfRef, setNfRef] = useState('');

  const q = query.trim().toLowerCase();
  const matchesQuery = (m: CatalogMaterial) =>
    !q || m.name.toLowerCase().includes(q) || m.reference.toLowerCase().includes(q) || m.brand.toLowerCase().includes(q);

  const availableBrands = useMemo(
    () => Array.from(new Set(catalog.filter(matchesQuery).map((m) => m.brand))),
    [catalog, q]
  );
  const results = catalog.filter((m) => matchesQuery(m) && (!brand || m.brand === brand));

  if (notFoundMode) {
    return (
      <Modal title="Ajouter du matériel" subtitle={interventionName} onClose={onClose}>
        <div className="form-stack">
          <div className="field">
            <label className="field__label">Nom du matériel *</label>
            <input className="field__input" autoFocus value={nfName} onChange={(e) => setNfName(e.target.value)} placeholder="Ex. Ancre FiberTak 4.5 mm" />
          </div>
          <div className="field">
            <label className="field__label">Référence <span className="field__hint">(optionnelle)</span></label>
            <input className="field__input" value={nfRef} onChange={(e) => setNfRef(e.target.value)} placeholder="Ex. AR-4520" />
          </div>
        </div>
        <button type="button" className="btn btn--amber btn--full" disabled={!nfName.trim()} onClick={() => onAddNotFound(nfName.trim(), nfRef.trim())}>
          Ajouter ce matériel
        </button>
        <button type="button" className="btn btn--ghost btn--full" onClick={() => setNotFoundMode(false)}>Retour à la recherche</button>
      </Modal>
    );
  }

  return (
    <Modal title="Ajouter du matériel" subtitle={interventionName} onClose={onClose}>
      <div className="search-input" style={{ marginTop: 16 }}>
        <svg className="search-input__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        <input autoFocus placeholder="Rechercher un matériel, une référence, une marque…" value={query} onChange={(e) => { setQuery(e.target.value); setBrand(null); }} />
      </div>

      <div className="brand-chips">
        {availableBrands.map((b) => (
          <button key={b} type="button" className={`brand-chip ${brand === b ? 'brand-chip--active' : ''}`} onClick={() => setBrand(brand === b ? null : b)}>
            {b}
          </button>
        ))}
      </div>

      <div className="search-results">
        {results.map((m) => (
          <button key={m.id} type="button" className="search-result" onClick={() => onAdd(m)}>
            <span className="search-result__body">
              <span className="search-result__name">{m.name}</span>
              <span className="search-result__meta">{m.brand} · {m.reference}</span>
            </span>
            <span className="search-result__add">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
            </span>
          </button>
        ))}
        {results.length === 0 && <div className="empty-state">Aucun résultat pour cette recherche.</div>}
      </div>

      <button type="button" className="btn btn--outline-warn btn--full" onClick={() => setNotFoundMode(true)}>
        Matériel introuvable
      </button>
    </Modal>
  );
}
