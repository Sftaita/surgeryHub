-- D-177 — repli, étape 2 (worker ARRÊTÉ, AVANT d'activer la version de repli).
-- Rien n'est supprimé ; toute ligne modifiée est journalisée dans d177_rollback_change_log
-- et remise en état par 05_roll_forward.sql. Idempotent.

-- Instantané complet des publications D-177 au moment du repli.
CREATE TABLE IF NOT EXISTS d177_rollback_publication_snapshot AS
SELECT p.*, NOW() AS snapshot_at FROM mission_publication p
 WHERE p.expires_at IS NOT NULL OR p.closed_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS d177_rollback_change_log (
  publication_id INT NOT NULL PRIMARY KEY,
  change_kind VARCHAR(32) NOT NULL,          -- CLOSED_IN_POOL | DUE_DURING_ROLLBACK
  declined_at_set DATETIME NOT NULL,
  changed_at DATETIME NOT NULL
);

-- Priorités CLOSES (expirée, levée, destinataire indisponible…) d'une mission OPEN déjà au
-- pool : la version de repli ignore closed_at et les verrait « en attente » (demande fantôme).
-- Marquées refusées à la date de clôture : la mission reste atteignable par son POOL actif.
INSERT IGNORE INTO d177_rollback_change_log (publication_id, change_kind, declined_at_set, changed_at)
SELECT p.id, 'CLOSED_IN_POOL', p.closed_at, NOW()
  FROM mission_publication p
  JOIN mission m ON m.id = p.mission_id
 WHERE p.scope = 'TARGETED' AND p.expires_at IS NOT NULL
   AND p.closed_at IS NOT NULL AND p.declined_at IS NULL
   AND m.status = 'OPEN' AND m.instrumentist_id IS NULL
   AND EXISTS (SELECT 1 FROM (SELECT mission_id FROM mission_publication
                               WHERE scope = 'POOL' AND declined_at IS NULL AND closed_at IS NULL) q
               WHERE q.mission_id = p.mission_id);

UPDATE mission_publication p
  JOIN d177_rollback_change_log l ON l.publication_id = p.id AND l.change_kind = 'CLOSED_IN_POOL'
   SET p.declined_at = l.declined_at_set
 WHERE p.declined_at IS NULL;

SELECT change_kind, COUNT(*) FROM d177_rollback_change_log GROUP BY change_kind;
-- Priorités EN ATTENTE : non modifiées (restent exclusives, jamais ouvertes au pool par le
-- repli) → 03_due_priorities.sql les traite à leur échéance ; 04_pending_report.sql les liste.
-- Priorités closes de missions ASSIGNED / annulées : non modifiées (inertes, sémantique D-125).
