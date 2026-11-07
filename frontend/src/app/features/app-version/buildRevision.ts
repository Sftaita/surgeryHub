/**
 * D-162 — commit à partir duquel CE bundle a été construit. `git archive` (procédure de
 * déploiement obligatoire) remplace la valeur ci-dessous par le SHA exact (attribut
 * export-subst, voir .gitattributes) ; dans un clone elle reste littérale = développement.
 * Sert uniquement à détecter un bundle obsolète (PWA en cache) : la version affichée vient
 * toujours du serveur (`GET /api/version`).
 */
export const BUILD_REVISION = "$Format:%H$";
