export type Role = "INSTRUMENTIST" | "SURGEON" | "MANAGER" | "ADMIN";

export function isMobileRole(
  role: string
): role is "INSTRUMENTIST" | "SURGEON" {
  return role === "INSTRUMENTIST" || role === "SURGEON";
}

export function isDesktopRole(role: string): role is "MANAGER" | "ADMIN" {
  return role === "MANAGER" || role === "ADMIN";
}
