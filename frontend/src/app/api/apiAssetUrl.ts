/**
 * Resolves a path returned by the backend (profile picture, hospital photo, ...)
 * into a URL the browser can actually fetch.
 *
 * Backend upload endpoints (UserController, SiteController) store/return
 * root-relative paths ("/uploads/..."), never absolute URLs — they must be
 * prefixed with VITE_API_BASE_URL, since the frontend and the API are served
 * from different origins. An already-absolute URL (http/https) is returned
 * as-is (e.g. a future CDN-backed value).
 */
export function resolveApiAssetUrl(path: string | null | undefined): string | undefined {
  if (!path) return undefined;

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? "";
  return `${base.replace(/\/+$/, "")}/${path.replace(/^\/+/, "")}`;
}
