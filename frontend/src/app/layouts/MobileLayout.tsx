import { Outlet } from "react-router-dom";

export function MobileLayout() {
  return (
    <div style={{ padding: 12 }}>
      <div style={{ marginBottom: 12 }}>MobileLayout</div>
      <Outlet />
    </div>
  );
}
