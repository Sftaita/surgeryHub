/**
 * Correctif auth/router (2026-09-04) — RequireAuth capture `pathname + search` comme
 * destination de retour post-login (`state.from`). Cette valeur provient toujours de
 * `useLocation()` (donc interne par construction), mais elle transite par
 * `history.state`/`location.state`, qui n'est pas strictement contrôlé par React Router
 * seul (state persistant au fil du temps, navigation manuelle, etc.) — on ne fait jamais
 * confiance à une valeur de redirection sans la valider explicitement (open-redirect).
 *
 * N'accepte qu'un chemin interne à l'application : commence par un seul `/`, jamais par
 * `//` ou `/\` (interprétés par certains navigateurs comme une URL protocole-relative
 * vers un hôte externe), et ne contient jamais de schéma (`javascript:`, `http:`, ...).
 */
export function isSafeInternalPath(path: unknown): path is string {
  if (typeof path !== "string" || path.length === 0) return false;
  if (!path.startsWith("/")) return false;
  if (path.startsWith("//") || path.startsWith("/\\")) return false;
  // Un chemin interne valide ne contient jamais de schéma d'URL — filtre défensif
  // supplémentaire contre une valeur du type "/\t/evil.com" ou "/ javascript:...".
  if (/^\/[a-zA-Z][a-zA-Z0-9+.-]*:/.test(path)) return false;
  return true;
}
