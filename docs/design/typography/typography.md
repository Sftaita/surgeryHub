# Typographie

**Famille : Inter** (Google Fonts ; self-host en production). Fallback : `system-ui, -apple-system, 'Segoe UI', sans-serif`. Antialiasing : `-webkit-font-smoothing: antialiased`.

## Échelle et rôles

| Rôle | Taille | Graisse | Tracking | Exemple |
|---|---|---|---|---|
| Titre écran | 26px | 800 | −0.02em | « 👋 Bonjour, Sophie ! » |
| Titre login desktop | 40px | 800 | −0.025em | « Bienvenue sur SurgeryHub » |
| Titre login mobile / encodage | 30 / 24px | 800 | −0.02em | |
| Titre modal | 20px | 800 | −0.02em | « Heures prestées » |
| Valeur forte (horaires hero) | 21px | 800 | −0.02em | `07h30 → 15h30` |
| Valeur stepper / total | 19–22px | 800 | — | `8h00` |
| Titre card | 15–17px | 700–800 | −0.01em | Nom du site |
| Corps | 14–15px | 400–600 | — | |
| Secondaire / méta | 12.5–13.5px | 400–600 | — | gray-500 |
| Label champ | 13px | 700 | — | gray-700 |
| Eyebrow | 10.5–12px | 800 | .06–.08em, UPPERCASE | « MISSION DU JOUR » |
| Micro (mois tuile) | 9.5–11px | 700 | .08em | « JUIL » |
| Nav mobile | 11px | 600/700 | — | |

## Règles
- **tabular-nums obligatoire** sur tout horaire, durée, date numérique, compteur (`font-variant-numeric: tabular-nums`).
- Format horaire belge : `07h30`, flèche `→` pour les plages, `·` comme séparateur de méta.
- Wordmark : « Surgery » + « Hub » colorés différemment (Hub en green-600 sur clair, green-300/400 sur sombre) — toujours en texte, jamais en image.
- `text-wrap: pretty` recommandé sur les paragraphes ; `line-height` 1.45–1.55 pour le corps, 1.1–1.25 pour les titres.
