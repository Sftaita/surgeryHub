import { POST_ENDING_SOON_DAYS } from "../theme/tokens";
import type { SurgeonSchedulePostV2 } from "./planningV2.types";

/**
 * Pure date math on data the frontend already has (post.endDate) — not a business rule
 * the backend owns, so computing it client-side doesn't violate the "no business
 * fallback" rule. There is no backend PlanningAlert for this (§6 of the handoff spec is
 * explicitly a frontend-only annotation); see PlanningAlertsTab for how it's merged
 * into the alert list as a synthetic, non-persisted card.
 */
export function isEndingSoon(endDate: string | null, days = POST_ENDING_SOON_DAYS): boolean {
  if (!endDate) return false;
  const end = new Date(endDate + "T00:00:00");
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diffDays = Math.round((end.getTime() - today.getTime()) / 86_400_000);
  return diffDays >= 0 && diffDays <= days;
}

export function formatEndingSoonLabel(endDate: string): string {
  const d = new Date(endDate + "T00:00:00");
  return `Se termine le ${d.toLocaleDateString("fr-FR", { day: "numeric", month: "short" })}`;
}

export function findEndingSoonPosts(posts: SurgeonSchedulePostV2[]): SurgeonSchedulePostV2[] {
  return posts.filter((p) => p.active && isEndingSoon(p.endDate));
}
