-- D-177 — décision de repli (lecture seule). À exécuter worker ARRÊTÉ (aucun message traité entre
-- la décision et la bascule). B1 (retour brut à d32dbe7) UNIQUEMENT si les trois compteurs sont à 0 ;
-- sinon B2 (version de repli RB). Toute trace D-177 compte, pas seulement les priorités :
--  * publications : colonnes expires_at / closed_at / close_reason (priorités, même closes) ;
--  * audits : les 5 valeurs d'AuditEventType ajoutées par D-177 — d32dbe7 ne sait pas les
--    hydrater (enum Doctrine → erreur 500 sur l'historique de la mission et le journal manager).
--    Une reprise publiée DIRECTEMENT au pool (aucune instrumentiste habituelle éligible) n'a
--    AUCUNE priorité mais enregistre ROOM_TAKEOVER_PRIORITY_BYPASSED ;
--  * file Messenger (toutes files, failed compris) : messages que d32dbe7 ne sait pas décoder
--    (RoomTakeoverPriorityExpiryMessage, MissionChangeType PRIORITY_ENDED, OpenEpisodeCause
--    ROOM_TAKEOVER_PRIORITY_ENDED, OpenPoolExclusionReason PRIORITY_DECLINED).
SELECT
  (SELECT COUNT(*) FROM mission_publication
    WHERE expires_at IS NOT NULL OR closed_at IS NOT NULL OR close_reason IS NOT NULL) AS publications_d177,
  (SELECT COUNT(*) FROM audit_event
    WHERE event_type IN ('ROOM_TAKEOVER_PRIORITY_OFFERED', 'ROOM_TAKEOVER_PRIORITY_BYPASSED',
                         'ROOM_TAKEOVER_PRIORITY_ENDED', 'ROOM_SLOT_TAKEOVER_REGULARIZED',
                         'ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED')) AS audits_d177,
  (SELECT COUNT(*) FROM messenger_messages
    WHERE body LIKE '%RoomTakeoverPriorityExpiryMessage%'
       OR body LIKE '%PRIORITY_ENDED%'
       OR body LIKE '%PRIORITY_DECLINED%') AS messages_d177;

SELECT event_type, COUNT(*) AS n, MIN(created_at) AS premier, MAX(created_at) AS dernier
  FROM audit_event
 WHERE event_type LIKE 'ROOM_TAKEOVER_%' OR event_type LIKE 'ROOM_SLOT_TAKEOVER_REGULARIZ%'
 GROUP BY event_type;

SELECT IF(
  (SELECT COUNT(*) FROM mission_publication
    WHERE expires_at IS NOT NULL OR closed_at IS NOT NULL OR close_reason IS NOT NULL) = 0
  AND (SELECT COUNT(*) FROM audit_event
    WHERE event_type IN ('ROOM_TAKEOVER_PRIORITY_OFFERED', 'ROOM_TAKEOVER_PRIORITY_BYPASSED',
                         'ROOM_TAKEOVER_PRIORITY_ENDED', 'ROOM_SLOT_TAKEOVER_REGULARIZED',
                         'ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED')) = 0
  AND (SELECT COUNT(*) FROM messenger_messages
    WHERE body LIKE '%RoomTakeoverPriorityExpiryMessage%'
       OR body LIKE '%PRIORITY_ENDED%'
       OR body LIKE '%PRIORITY_DECLINED%') = 0,
  'B1 — aucune trace D-177 : retour brut à d32dbe7 possible',
  'B2 — traces D-177 présentes : version de repli RB obligatoire') AS decision;
