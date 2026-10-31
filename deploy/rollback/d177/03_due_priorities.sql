-- D-177 — EN MODE REPLI UNIQUEMENT (toutes les 5 minutes, due_priorities.sh) : la version de
-- repli ne fait plus expirer les priorités. Une priorité en attente dont l'échéance est passée
-- devient une demande refusée à son échéance, jamais avant : la version de repli l'affiche
-- « Demande refusée — à rediffuser » et le manager peut publier au pool ou attribuer.
-- Journalisée (DUE_DURING_ROLLBACK), annulée par 05_roll_forward.sql. Idempotent.
INSERT IGNORE INTO d177_rollback_change_log (publication_id, change_kind, declined_at_set, changed_at)
SELECT p.id, 'DUE_DURING_ROLLBACK', p.expires_at, NOW()
  FROM mission_publication p
 WHERE p.scope = 'TARGETED' AND p.expires_at IS NOT NULL AND p.expires_at <= NOW()
   AND p.declined_at IS NULL AND p.closed_at IS NULL;

UPDATE mission_publication p
  JOIN d177_rollback_change_log l ON l.publication_id = p.id AND l.change_kind = 'DUE_DURING_ROLLBACK'
   SET p.declined_at = l.declined_at_set
 WHERE p.declined_at IS NULL AND p.closed_at IS NULL;

-- Missions à rediffuser par un manager (échéance passée, toujours OPEN sans instrumentiste).
SELECT m.id AS mission_id, DATE_FORMAT(m.start_at, '%d/%m/%Y %H:%i') AS debut,
       h.name AS site, p.expires_at AS echeance_passee, p.target_instrumentist_id
  FROM d177_rollback_change_log l
  JOIN mission_publication p ON p.id = l.publication_id
  JOIN mission m ON m.id = p.mission_id
  JOIN hospital h ON h.id = m.site_id
 WHERE l.change_kind = 'DUE_DURING_ROLLBACK' AND m.status = 'OPEN' AND m.instrumentist_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM mission_publication q WHERE q.mission_id = m.id AND q.scope = 'POOL' AND q.declined_at IS NULL)
 ORDER BY m.start_at;
