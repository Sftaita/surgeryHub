import React from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useNavigate, useOutletContext } from "react-router-dom";
import useMediaQuery from "@mui/material/useMediaQuery";
import { fetchMyHours } from "./api/myHours.api";
import { currentMonth, shiftMonth } from "./format";
import { MyHoursView, type SiteFilter } from "./MyHoursView";
import type { MobileLayoutOutletContext } from "../../layouts/MobileLayout";

/**
 * D-179 — « Mes heures » (/app/i/hours). Le mois courant est sélectionné par défaut, jamais
 * de mois futur ; le filtre par site est réinitialisé à chaque changement de mois. Le
 * serveur calcule tout pour le périmètre demandé (mois + site).
 */
export default function MyHoursPage() {
  const navigate = useNavigate();
  const outlet = useOutletContext<MobileLayoutOutletContext | undefined>();
  const mediaDesktop = useMediaQuery("(min-width:900px)");
  const isDesktop = outlet?.isDesktop ?? mediaDesktop;
  // Le tableau desktop demande ≈ 925 px ; avec le rail (≈ 320 px), en dessous de 1280 px la
  // colonne Chirurgien · site tomberait à 0 : on garde alors la liste.
  const wideTable = useMediaQuery("(min-width:1280px)");

  const thisMonth = React.useMemo(() => currentMonth(), []);
  const [month, setMonth] = React.useState(thisMonth);
  const [site, setSite] = React.useState<SiteFilter>("all");
  const isCurrentMonth = month === thisMonth;

  const changeMonth = (next: string) => {
    setMonth(next);
    setSite("all");
  };

  const siteId = site === "all" ? null : site;
  const query = useQuery({
    queryKey: ["my-hours", month, siteId],
    queryFn: () => fetchMyHours(month, siteId),
    // Garde l'affichage précédent pendant un changement de site : pas de saut de mise en page.
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === month ? keepPreviousData(previous) : undefined),
  });

  return (
    <MyHoursView
      month={month}
      statement={query.data ?? null}
      loading={query.isFetching}
      error={query.isError}
      site={site}
      onSite={setSite}
      isCurrentMonth={isCurrentMonth}
      isDesktop={isDesktop}
      tableLayout={isDesktop && wideTable}
      onPrevMonth={() => changeMonth(shiftMonth(month, -1))}
      onNextMonth={() => !isCurrentMonth && changeMonth(shiftMonth(month, 1))}
      onGoToCurrentMonth={() => changeMonth(thisMonth)}
      onRetry={() => void query.refetch()}
      onOpenMission={(id) => navigate(`/app/i/missions/${id}`)}
      headerTop={outlet?.brandTopRow}
    />
  );
}
