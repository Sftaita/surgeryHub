import React, { useLayoutEffect, useRef, useState } from 'react';
import { InterventionData } from './types';
import { MaterialRow } from './MaterialRow';

interface InterventionCardProps {
  intervention: InterventionData;
  /** Rang 1-based dans la mission — pastille du bandeau (numérotation chronologique). */
  index: number;
  open: boolean;
  onToggle: () => void;
  onQtyInc: (materialId: string) => void;
  onQtyDec: (materialId: string) => void;
  onRemoveMaterial?: (materialId: string) => void;
  onAddMaterial: () => void;
  onEdit?: () => void;
  onDelete?: () => void;
}

const plural = (n: number, s: string, p: string) => `${n} ${n > 1 ? p : s}`;

/**
 * Carte intervention — bandeau vert foncé, accordéon animé.
 *
 * Le corps reste monté et s'anime en `max-height` mesurée (+ opacité) : compatible iOS Safari
 * et WebView Android, contrairement à `grid-template-rows: 0fr→1fr` et à l'animation de `height:auto`.
 */
export function InterventionCard({
  intervention, index, open, onToggle,
  onQtyInc, onQtyDec, onRemoveMaterial, onAddMaterial, onEdit, onDelete,
}: InterventionCardProps) {
  const mats = intervention.materials;
  const units = mats.reduce((a, m) => a + m.qty, 0);
  const done = mats.length > 0;
  const countLabel = done
    ? `${plural(mats.length, 'matériel', 'matériels')} · ${plural(units, 'unité', 'unités')}`
    : 'Aucun matériel encodé';

  const contentRef = useRef<HTMLDivElement | null>(null);
  const [height, setHeight] = useState(0);

  // Mesure du corps : à l'ouverture, quand le matériel change, et sur redimensionnement
  // (le titre peut passer sur deux lignes en rotation d'écran).
  useLayoutEffect(() => {
    const el = contentRef.current;
    if (!el) return;
    const measure = () => setHeight(el.scrollHeight);
    measure();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', measure);
      return () => window.removeEventListener('resize', measure);
    }
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [mats.length, open]);

  return (
    <section className="ivc" aria-label={`Intervention ${index} — ${intervention.name}`}>
      <button type="button" className="ivc__head" onClick={onToggle} aria-expanded={open}>
        {done ? (
          <span className="ivc__num ivc__num--round" aria-hidden="true">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round"><path d="m5 13 4 4L19 7" /></svg>
          </span>
        ) : (
          <span className="ivc__num ivc__num--todo tabular">{index}</span>
        )}
        <span className="ivc__titles">
          <span className="ivc__name">{intervention.name}</span>
          <span className="ivc__count tabular">{countLabel}</span>
        </span>
        <span className={`ivc__status${done ? '' : ' ivc__status--todo'}`}>{done ? 'Complété' : 'À compléter'}</span>
        <span className="ivc__chevwrap">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round" className={`ivc__chevron${open ? ' ivc__chevron--open' : ''}`}><path d="m6 9 6 6 6-6" /></svg>
        </span>
      </button>

      <div className="ivc__reveal" style={{ maxHeight: open ? height : 0 }} aria-hidden={!open}>
        <div className={`ivc__body${open ? ' ivc__body--open' : ''}`} ref={contentRef}>
          <div className="ivc__materials">
            {mats.map((m) => (
              <MaterialRow
                key={m.id}
                material={m}
                onInc={() => onQtyInc(m.id)}
                onDec={() => onQtyDec(m.id)}
                onRemove={onRemoveMaterial ? () => onRemoveMaterial(m.id) : undefined}
              />
            ))}
            {!done && (
              <p className="ivc__nudge">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--amber-700)" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.4 18a1.8 1.8 0 0 0 1.6 2.7h16a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" /></svg>
                Ajoutez le matériel utilisé pour valider cette intervention.
              </p>
            )}
          </div>
          <div className="ivc__actions">
            <button type="button" className="ivc__add" onClick={onAddMaterial} tabIndex={open ? 0 : -1}>
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
              Ajouter du matériel
            </button>
            {onEdit && <button type="button" className="ivc__edit" onClick={onEdit} tabIndex={open ? 0 : -1}>Modifier</button>}
            {onDelete && (
              <button type="button" className="ivc__trash" onClick={onDelete} tabIndex={open ? 0 : -1} aria-label="Supprimer l’intervention" title="Supprimer l’intervention">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
              </button>
            )}
          </div>
        </div>
      </div>
    </section>
  );
}
