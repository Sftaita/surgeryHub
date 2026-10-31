-- D-177 — retour en avant : après redéploiement de D-177, worker ARRÊTÉ, AVANT la crontab.
-- Annule exactement les marquages du repli (d177_rollback_change_log). Idempotent.

-- 1. Priorités échues pendant le repli dont la mission a déjà été rediffusée au pool par un
--    manager : la priorité est close EXPIRED à son échéance, sans nouvelle sollicitation.
UPDATE mission_publication p
  JOIN d177_rollback_change_log l ON l.publication_id = p.id AND l.change_kind = 'DUE_DURING_ROLLBACK'
   SET p.declined_at = NULL, p.closed_at = p.expires_at, p.close_reason = 'EXPIRED'
 WHERE p.declined_at = l.declined_at_set AND p.closed_at IS NULL
   AND EXISTS (SELECT 1 FROM (SELECT mission_id FROM mission_publication
                               WHERE scope = 'POOL' AND declined_at IS NULL AND closed_at IS NULL) q
               WHERE q.mission_id = p.mission_id);

-- 2. Toutes les autres lignes marquées par le repli retrouvent leur état D-177 :
--    - CLOSED_IN_POOL : déjà closes (closed_at) → simple retrait du refus technique ;
--    - DUE_DURING_ROLLBACK restantes : redeviennent actives, échéance passée → la commande
--      app:room-takeover:expire-priorities les fait expirer (pool ouvert à ce moment-là), ou
--      les clôt sans pool si la mission a été attribuée / annulée entre-temps.
UPDATE mission_publication p
  JOIN d177_rollback_change_log l ON l.publication_id = p.id
   SET p.declined_at = NULL
 WHERE p.declined_at = l.declined_at_set;

SELECT l.change_kind, COUNT(*) AS rows_logged,
       SUM(p.declined_at IS NULL) AS restored
  FROM d177_rollback_change_log l JOIN mission_publication p ON p.id = l.publication_id
 GROUP BY l.change_kind;
-- Ensuite : expire-priorities --dry-run, puis exécution, puis crontab. Tables d177_rollback_*
-- conservées jusqu'à validation, puis supprimées sur décision.
