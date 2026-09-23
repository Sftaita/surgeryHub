import React, { useRef, useState } from 'react';
import { InterventionData } from './types';
import { InterventionCard } from './InterventionCard';
import { scrollElementToTop } from './useCardScroll';

interface InterventionsListProps {
  interventions: InterventionData[];
  /** Accordéon exclusif : ouvrir une intervention ferme les autres. Règle validée — laisser à true. */
  exclusive?: boolean;
  /** Dernière intervention en haut, n°1 en bas (défaut). Les numéros restent chronologiques. */
  newestFirst?: boolean;
  /** Amène la carte ouverte en haut de l'écran (et le haut de liste à la fermeture). */
  autoScroll?: boolean;
  defaultOpenId?: string;
  onQtyInc: (interventionId: string, materialId: string) => void;
  onQtyDec: (interventionId: string, materialId: string) => void;
  onRemoveMaterial?: (interventionId: string, materialId: string) => void;
  onAddMaterial: (interventionId: string) => void;
  onEditIntervention?: (interventionId: string) => void;
  onDeleteIntervention?: (interventionId: string) => void;
  onAddIntervention: () => void;
}

/** Liste des interventions : en-tête (résumé + "+ Intervention") puis cartes accordéon, la plus récente en haut. */
export function InterventionsList({
  interventions, exclusive = true, newestFirst = true, autoScroll = true, defaultOpenId,
  onQtyInc, onQtyDec, onRemoveMaterial, onAddMaterial,
  onEditIntervention, onDeleteIntervention, onAddIntervention,
}: InterventionsListProps) {
  const numbered = interventions.map((it, i) => ({ it, index: i + 1 }));
  const shown = newestFirst ? [...numbered].reverse() : numbered;

  const lastTodo = [...numbered].reverse().find(({ it }) => it.materials.length === 0) || shown[0];
  const [openIds, setOpenIds] = useState<string[]>(
    defaultOpenId ? [defaultOpenId] : lastTodo ? [lastTodo.it.id] : [],
  );

  const listRef = useRef<HTMLElement | null>(null);
  const cardRefs = useRef<Record<string, HTMLElement | null>>({});

  const toggle = (id: string) => {
    const willOpen = !openIds.includes(id);
    setOpenIds(willOpen ? (exclusive ? [id] : [...openIds, id]) : openIds.filter((x) => x !== id));
    if (!autoScroll) return;
    // ouverture (ou passage d'une carte à l'autre) : la carte visée monte en haut
    // fermeture : on remonte en tête de liste
    scrollElementToTop(willOpen ? cardRefs.current[id] : listRef.current);
  };

  const units = interventions.reduce((a, it) => a + it.materials.reduce((b, m) => b + m.qty, 0), 0);

  return (
    <section className="ivs" ref={listRef}>
      <header className="ivs__head">
        <h2 className="ivs__eyebrow">INTERVENTIONS</h2>
        <span className="ivs__summary tabular">
          {interventions.length} {interventions.length > 1 ? 'interventions' : 'intervention'} · {units} matériels
        </span>
        <button type="button" className="ivs__new" onClick={onAddIntervention} title="Ajouter une intervention">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.8} strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
          Intervention
        </button>
      </header>

      {shown.map(({ it, index }) => (
        <div key={it.id} ref={(el) => { cardRefs.current[it.id] = el; }}>
          <InterventionCard
            intervention={it}
            index={index}
            open={openIds.includes(it.id)}
            onToggle={() => toggle(it.id)}
            onQtyInc={(mid) => onQtyInc(it.id, mid)}
            onQtyDec={(mid) => onQtyDec(it.id, mid)}
            onRemoveMaterial={onRemoveMaterial ? (mid) => onRemoveMaterial(it.id, mid) : undefined}
            onAddMaterial={() => onAddMaterial(it.id)}
            onEdit={onEditIntervention ? () => onEditIntervention(it.id) : undefined}
            onDelete={onDeleteIntervention ? () => onDeleteIntervention(it.id) : undefined}
          />
        </div>
      ))}
    </section>
  );
}
