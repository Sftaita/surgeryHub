import { Navigate, useParams } from "react-router-dom";

/**
 * Le détail mission manager est le tiroir de la liste Missions (ManagerMissionDrawer) :
 * les liens /app/m/missions/:id (notifications, e-mails, facturation, journal…) y mènent.
 */
export function ManagerMissionRedirect() {
  const { id } = useParams();
  return <Navigate to={`/app/m/missions?mission=${encodeURIComponent(id ?? "")}`} replace />;
}
