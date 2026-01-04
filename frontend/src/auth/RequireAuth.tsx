import { Navigate, Outlet } from "react-router-dom";
import { authStore } from "./authStore";

export default function RequireAuth() {
  const token = authStore.getAccessToken();
  if (!token) return <Navigate to="/login" replace />;
  return <Outlet />;
}
