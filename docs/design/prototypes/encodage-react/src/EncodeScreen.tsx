import React, { useState } from 'react';
import { CatalogMaterial, MissionData, WorkedHours } from './types';
import { CATALOG, MOCK_MISSION } from './mockData';
import { EncodeHeader } from './components/EncodeHeader';
import { MissionReadOnlyCard } from './components/MissionReadOnlyCard';
import { WorkedHoursRow } from './components/WorkedHoursRow';
import { WorkedHoursSheet } from './components/WorkedHoursSheet';
import { InterventionsSection } from './components/InterventionsSection';
import { MaterialSearchSheet } from './components/MaterialSearchSheet';
import { NewInterventionSheet } from './components/NewInterventionSheet';
import { StickyValidateFooter } from './components/StickyValidateFooter';
import { Toast } from './components/Toast';
import { useToast } from './hooks/useToast';

interface EncodeScreenProps {
  missionId: string;
  onBack: () => void;
  onValidated: () => void;
}

function nowHHMM() {
  const d = new Date();
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

/**
 * Container: owns all state for this screen. In production, fetch the
 * mission by `missionId` instead of MOCK_MISSION, and persist every mutation
 * via your API inside each handler (there is no manual "Save" button —
 * every change below is meant to write through immediately).
 */
export function EncodeScreen({ missionId, onBack, onValidated }: EncodeScreenProps) {
  const [mission, setMission] = useState<MissionData>(MOCK_MISSION);
  const [savedAt, setSavedAt] = useState(nowHHMM());
  const [hoursSheetOpen, setHoursSheetOpen] = useState(false);
  const [materialSheetFor, setMaterialSheetFor] = useState<string | null>(null); // intervention id
  const [newInterventionOpen, setNewInterventionOpen] = useState(false);
  const { toastMessage, showToast } = useToast();

  function touchSaved() { setSavedAt(nowHHMM()); }

  function toggleIntervention(id: string) {
    setMission((m) => ({ ...m, interventions: m.interventions.map((it) => (it.id === id ? { ...it, open: !it.open } : it)) }));
  }

  function changeQty(interventionId: string, materialId: string, delta: number) {
    setMission((m) => ({
      ...m,
      interventions: m.interventions.map((it) =>
        it.id !== interventionId ? it : {
          ...it,
          materials: it.materials.map((mat) => (mat.id === materialId ? { ...mat, qty: Math.max(1, mat.qty + delta) } : mat))
        }
      )
    }));
    touchSaved();
  }

  function addMaterial(interventionId: string, mat: CatalogMaterial) {
    setMission((m) => ({
      ...m,
      interventions: m.interventions.map((it) =>
        it.id !== interventionId ? it : { ...it, materials: [...it.materials, { id: 'mm' + Date.now(), name: mat.name, reference: mat.reference, qty: 1, isNew: true }] }
      )
    }));
    setMaterialSheetFor(null);
    touchSaved();
    showToast('Matériel ajouté à l\u2019intervention.');
  }

  function addNotFoundMaterial(interventionId: string, name: string, reference: string) {
    setMission((m) => ({
      ...m,
      interventions: m.interventions.map((it) =>
        it.id !== interventionId ? it : { ...it, materials: [...it.materials, { id: 'mm' + Date.now(), name, reference: reference || '—', qty: 1, isNew: true, notFound: true }] }
      )
    }));
    setMaterialSheetFor(null);
    touchSaved();
    // In production: also POST to the "Demandes matériel" queue reviewed by management.
    showToast('Ligne ajoutée — signalée à l\u2019équipe pour précision.');
  }

  function addIntervention(name: string) {
    setMission((m) => ({ ...m, interventions: [...m.interventions, { id: 'ii' + Date.now(), name, open: true, materials: [] }] }));
    setNewInterventionOpen(false);
    touchSaved();
  }

  function saveHours(h: WorkedHours) {
    setMission((m) => ({ ...m, hours: h }));
    setHoursSheetOpen(false);
    touchSaved();
    showToast('Heures prestées enregistrées.');
  }

  const activeIntervention = mission.interventions.find((it) => it.id === materialSheetFor);

  return (
    <div className="encode-screen">
      <EncodeHeader mission={mission} savedAt={savedAt} onBack={onBack} />
      <div className="encode-content">
        <MissionReadOnlyCard mission={mission} />
        <WorkedHoursRow hours={mission.hours} onClick={() => setHoursSheetOpen(true)} />
        <InterventionsSection
          interventions={mission.interventions}
          onToggle={toggleIntervention}
          onQtyInc={(iid, mid) => changeQty(iid, mid, 1)}
          onQtyDec={(iid, mid) => changeQty(iid, mid, -1)}
          onAddMaterial={(iid) => setMaterialSheetFor(iid)}
          onAddIntervention={() => setNewInterventionOpen(true)}
        />
      </div>

      <StickyValidateFooter onValidate={onValidated} />

      {hoursSheetOpen && (
        <WorkedHoursSheet
          initial={mission.hours ?? { start: 450, end: 930, pause: 0, nextDay: false }}
          scheduled={mission.scheduled}
          onClose={() => setHoursSheetOpen(false)}
          onSave={saveHours}
        />
      )}

      {activeIntervention && (
        <MaterialSearchSheet
          interventionName={activeIntervention.name}
          catalog={CATALOG}
          onClose={() => setMaterialSheetFor(null)}
          onAdd={(mat) => addMaterial(activeIntervention.id, mat)}
          onAddNotFound={(name, ref) => addNotFoundMaterial(activeIntervention.id, name, ref)}
        />
      )}

      {newInterventionOpen && (
        <NewInterventionSheet onClose={() => setNewInterventionOpen(false)} onCreate={addIntervention} />
      )}

      <Toast message={toastMessage} />
    </div>
  );
}
