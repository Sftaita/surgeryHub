import React, { useState } from 'react';
import { EncodingSection } from './EncodingSection';
import { InterventionData } from './types';
import { mockInterventions, mockHours } from './mockData';

/**
 * Exemple d'intégration autonome — à supprimer une fois branché sur les vraies données.
 * Montre les seules mutations attendues : quantité ±1, retrait d'un matériel,
 * ajout d'un matériel, ajout/suppression d'une intervention.
 */
export function ExampleApp() {
  const [interventions, setInterventions] = useState<InterventionData[]>(mockInterventions);

  const mapIntervention = (id: string, fn: (it: InterventionData) => InterventionData) =>
    setInterventions((list) => list.map((it) => (it.id === id ? fn(it) : it)));

  return (
    <div style={{ background: 'var(--gray-50)', minHeight: '100vh', paddingTop: 20 }}>
      <EncodingSection
        hours={mockHours}
        interventions={interventions}
        onOpenHours={() => console.log('ouvrir la saisie des heures')}
        onQtyInc={(i, m) =>
          mapIntervention(i, (it) => ({
            ...it,
            materials: it.materials.map((x) => (x.id === m ? { ...x, qty: x.qty + 1 } : x)),
          }))
        }
        onQtyDec={(i, m) =>
          mapIntervention(i, (it) => ({
            ...it,
            materials: it.materials.map((x) => (x.id === m ? { ...x, qty: Math.max(1, x.qty - 1) } : x)),
          }))
        }
        onRemoveMaterial={(i, m) =>
          mapIntervention(i, (it) => ({ ...it, materials: it.materials.filter((x) => x.id !== m) }))
        }
        onAddMaterial={(i) => console.log('ouvrir la recherche matériel pour', i)}
        onEditIntervention={(i) => console.log('éditer', i)}
        onDeleteIntervention={(i) => setInterventions((l) => l.filter((it) => it.id !== i))}
        onAddIntervention={() => console.log('ouvrir la création d’intervention')}
        onValidate={() => console.log('terminer l’encodage')}
      />
    </div>
  );
}
