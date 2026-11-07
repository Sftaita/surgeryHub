/**
 * EXEMPLE — branchement dans l'app instrumentiste. Routing, fetch et ouverture du détail sont à adapter.
 */
import { useEffect, useState } from 'react';
import { MesHeures } from './MesHeures';
import type { Prestation } from './types';
import { isHourly, toPrestation } from './example.toPrestation';
// import { useIsDesktop } from '../instrumentiste-nav/useIsDesktop';      // handoff-instrumentiste-nav
// import { useNavigate } from 'react-router-dom';

declare function useIsDesktop(bp?: number): boolean;
declare function useProfile(): { remunerationType?: string | null };
declare function fetchEncodedMissions(year: number, month: number): Promise<any[]>; // GET /me/missions?encoded=1&month=YYYY-MM
declare function openMissionDetail(missionId: string): void;               // écran « Détail mission » EXISTANT

export function MesHeuresPage() {
  const now = new Date();
  const current = { y: now.getFullYear(), m: now.getMonth() + 1 };
  const [ym, setYm] = useState(current);                // mois courant par défaut
  const [items, setItems] = useState<Prestation[] | null>(null);
  const isDesktop = useIsDesktop(900);
  const profile = useProfile();

  useEffect(() => {
    let alive = true;
    setItems(null);
    fetchEncodedMissions(ym.y, ym.m).then((rows) => {
      if (alive) setItems(rows.map(toPrestation).filter((p): p is Prestation => p !== null));
    });
    return () => { alive = false; };
  }, [ym.y, ym.m]);

  const shift = (d: number) => setYm(({ y, m }) => {
    const t = y * 12 + (m - 1) + d;
    return { y: Math.floor(t / 12), m: (t % 12) + 1 };
  });
  const isCurrentMonth = ym.y === current.y && ym.m === current.m;

  return (
    <MesHeures
      year={ym.y}
      month={ym.m}
      prestations={items ?? []}            // afficher un squelette pendant le chargement si besoin
      hourly={isHourly(profile)}
      isCurrentMonth={isCurrentMonth}
      isDesktop={isDesktop}
      onPrevMonth={() => shift(-1)}
      onNextMonth={() => !isCurrentMonth && shift(1)}
      onGoToCurrentMonth={() => setYm(current)}
      onOpenMission={openMissionDetail}
      // headerTop={<ShellTopRow />}       // rangée logo + avatar du bandeau mobile existant
    />
  );
}
