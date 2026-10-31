-- D-177 — liste fiable des priorités en attente (à remettre aux managers au moment du repli,
-- puis chaque matin tant que le repli dure). Lecture seule.
SELECT m.id AS mission_id,
       DATE_FORMAT(m.start_at, '%d/%m/%Y %H:%i') AS debut_mission,
       h.name AS site,
       CONCAT(s.firstname, ' ', s.lastname) AS chirurgien,
       CONCAT(i.firstname, ' ', i.lastname) AS instrumentiste_prioritaire,
       p.published_at AS proposee_le,
       p.expires_at AS echeance,
       IF(m.start_at < p.expires_at, 'OUI - décider avant le début', 'non') AS mission_avant_echeance
  FROM mission_publication p
  JOIN mission m ON m.id = p.mission_id
  JOIN hospital h ON h.id = m.site_id
  LEFT JOIN user s ON s.id = m.surgeon_id
  LEFT JOIN user i ON i.id = p.target_instrumentist_id
 WHERE p.scope = 'TARGETED' AND p.expires_at IS NOT NULL
   AND p.declined_at IS NULL AND p.closed_at IS NULL
   AND m.status = 'OPEN' AND m.instrumentist_id IS NULL
 ORDER BY p.expires_at;
