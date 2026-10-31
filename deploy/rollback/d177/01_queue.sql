-- D-177 — repli, étape 1 (worker ARRÊTÉ) : messages Messenger que la version de repli ne sait
-- pas décoder (RoomTakeoverPriorityExpiryMessage, PRIORITY_ENDED, ROOM_TAKEOVER_PRIORITY_ENDED,
-- PRIORITY_DECLINED). Copiés dans d177_rollback_messenger_snapshot, puis retirés de la file.
-- Idempotent. Rien n'est perdu au retour en avant : l'expiration est rattrapée par la commande.
CREATE TABLE IF NOT EXISTS d177_rollback_messenger_snapshot AS
SELECT m.*, NOW() AS snapshot_at FROM messenger_messages m WHERE 1 = 0;

INSERT INTO d177_rollback_messenger_snapshot
SELECT m.*, NOW() FROM messenger_messages m
 WHERE (m.body LIKE '%RoomTakeoverPriorityExpiryMessage%'
     OR m.body LIKE '%PRIORITY_ENDED%'
     OR m.body LIKE '%PRIORITY_DECLINED%')
   AND m.id NOT IN (SELECT id FROM (SELECT id FROM d177_rollback_messenger_snapshot) s);

DELETE m FROM messenger_messages m JOIN d177_rollback_messenger_snapshot s ON s.id = m.id;
SELECT COUNT(*) AS d177_messages_set_aside FROM d177_rollback_messenger_snapshot;
