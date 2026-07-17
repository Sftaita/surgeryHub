import type {
  InstrumentistDetailDTO,
  SiteMembershipDTO,
} from "../api/instrumentists.types";

export function buildDisplayName(
  instrumentist?: InstrumentistDetailDTO,
): string {
  if (!instrumentist) {
    return "—";
  }

  if (instrumentist.displayName && instrumentist.displayName.trim() !== "") {
    return instrumentist.displayName;
  }

  const fullname = [instrumentist.firstname, instrumentist.lastname]
    .filter((value): value is string => Boolean(value && value.trim() !== ""))
    .join(" ")
    .trim();

  return fullname !== "" ? fullname : "—";
}

export function getEmploymentTypeLabel(
  employmentType: InstrumentistDetailDTO["employmentType"],
): string {
  switch (employmentType) {
    case "EMPLOYEE":
      return "Employé";
    case "FREELANCER":
      return "Freelancer";
    default:
      return "—";
  }
}

export function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.error?.message ??
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    "Une erreur est survenue."
  );
}

export function isConflictError(err: any): boolean {
  return (
    err?.response?.status === 409 ||
    err?.response?.data?.error?.code === "CONFLICT"
  );
}

export function mergeMembershipsBySiteId(
  memberships: SiteMembershipDTO[],
): SiteMembershipDTO[] {
  const map = new Map<number, SiteMembershipDTO>();

  for (const membership of memberships) {
    const existing = map.get(membership.site.id);

    if (!existing) {
      map.set(membership.site.id, membership);
      continue;
    }

    const existingIsOptimistic = existing.id < 0;
    const currentIsConfirmed = membership.id > 0;

    if (existingIsOptimistic && currentIsConfirmed) {
      map.set(membership.site.id, membership);
    }
  }

  return Array.from(map.values()).sort((a, b) =>
    a.site.name.localeCompare(b.site.name, "fr", { sensitivity: "base" }),
  );
}

export function normalizeRateValue(value: string | null | undefined): string {
  return value ?? "";
}

export function parseRateInput(value: string): number | null {
  const trimmed = value.trim();

  if (trimmed === "") {
    return null;
  }

  const parsed = Number(trimmed);

  if (!Number.isFinite(parsed)) {
    return null;
  }

  return parsed;
}
