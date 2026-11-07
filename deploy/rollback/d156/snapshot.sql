-- D-156 — instantané LECTURE SEULE des données D-156, à exécuter avant tout retour arrière du
-- code et à conserver avec le dump de la base (sortie --table ou --batch redirigée vers un
-- fichier horodaté). Sert de référence de contrôle : après le retour arrière puis au
-- redéploiement, la même requête doit donner exactement le même résultat.
START TRANSACTION READ ONLY;
SELECT 'worked_presence' AS t, COUNT(*) AS n,
       MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, instrumentist_id, surgeon_id, site_id, service_date,
           IFNULL(actual_start_at, ''), IFNULL(actual_end_at, ''), revision) ORDER BY id), '')) AS h
  FROM worked_presence
UNION ALL
SELECT 'mission_execution_presence', COUNT(*),
       MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', mission_id, presence_id, IFNULL(actual_duration_minutes, '')) ORDER BY mission_id), ''))
  FROM mission_execution WHERE presence_id IS NOT NULL
UNION ALL
SELECT 'mission_schedule_link', COUNT(*),
       MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, schedule_post_id, schedule_occurrence_date) ORDER BY id), ''))
  FROM mission WHERE schedule_post_id IS NOT NULL
UNION ALL
SELECT 'mission_room_label', COUNT(*),
       MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, room_label) ORDER BY id), ''))
  FROM mission WHERE room_label IS NOT NULL
UNION ALL
SELECT 'planning_multi_room_confirmation', COUNT(*),
       MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, planning_version_id, fingerprint) ORDER BY id), ''))
  FROM planning_multi_room_confirmation;
-- Détail lisible des présences et de leurs missions (relevé humain).
SELECT p.id AS presence, p.service_date, p.instrumentist_id, p.actual_start_at, p.actual_end_at, p.revision,
       GROUP_CONCAT(CONCAT(e.mission_id, ':', IFNULL(e.actual_duration_minutes, '-')) ORDER BY e.mission_id) AS parts
  FROM worked_presence p LEFT JOIN mission_execution e ON e.presence_id = p.id
 GROUP BY p.id ORDER BY p.id;
ROLLBACK;
