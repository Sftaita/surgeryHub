-- D-156 — verrous de retour arrière NON destructif (base `surgicalhub`).
--
-- À installer UNIQUEMENT si le code est ramené à une version antérieure à D-156 (d9e6ee9)
-- après que des présences communes (worked_presence) ont été créées. Le schéma D-156 et ses
-- données restent en place (jamais de down()) ; ces triggers empêchent l'ancien code, qui ne
-- connaît pas les présences communes, de les altérer :
--   - les heures et le lien d'une mission liée à une présence ne changent pas, ne s'effacent pas ;
--   - une mission liée ne change ni d'instrumentiste, ni d'horaire, n'est ni annulée ni refusée ;
--   - aucun calcul financier n'est créé pour une mission liée ;
--   - worked_presence est en lecture seule.
-- Toute autre mission fonctionne normalement. Réversible : guard-uninstall.sql (avant de
-- redéployer D-156). Idempotent : supprime puis recrée chaque trigger.

DROP TRIGGER IF EXISTS d156_guard_execution_update;
DROP TRIGGER IF EXISTS d156_guard_execution_delete;
DROP TRIGGER IF EXISTS d156_guard_mission_update;
DROP TRIGGER IF EXISTS d156_guard_calculation_insert;
DROP TRIGGER IF EXISTS d156_guard_presence_update;
DROP TRIGGER IF EXISTS d156_guard_presence_delete;

DELIMITER $$

CREATE TRIGGER d156_guard_execution_update BEFORE UPDATE ON mission_execution FOR EACH ROW
BEGIN
  IF OLD.presence_id IS NOT NULL AND (
       NOT (NEW.presence_id <=> OLD.presence_id)
    OR NOT (NEW.actual_duration_minutes <=> OLD.actual_duration_minutes)
    OR NEW.actual_start_at IS NOT NULL OR NEW.actual_end_at IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : heures communes d une prestation multi-salles protegees pendant le retour arriere';
  END IF;
END$$

CREATE TRIGGER d156_guard_execution_delete BEFORE DELETE ON mission_execution FOR EACH ROW
BEGIN
  IF OLD.presence_id IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : execution liee a une presence commune protegee pendant le retour arriere';
  END IF;
END$$

CREATE TRIGGER d156_guard_mission_update BEFORE UPDATE ON mission FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM mission_execution e WHERE e.mission_id = OLD.id AND e.presence_id IS NOT NULL) AND (
       NOT (NEW.instrumentist_id <=> OLD.instrumentist_id)
    OR NOT (NEW.start_at <=> OLD.start_at)
    OR NOT (NEW.end_at <=> OLD.end_at)
    OR (NEW.status <> OLD.status AND NEW.status IN ('CANCELLED', 'REJECTED'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : mission d une prestation multi-salles gelee pendant le retour arriere';
  END IF;
END$$

CREATE TRIGGER d156_guard_calculation_insert BEFORE INSERT ON financial_calculation FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM mission_execution e WHERE e.mission_id = NEW.mission_id AND e.presence_id IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : valorisation d une prestation multi-salles suspendue pendant le retour arriere';
  END IF;
END$$

CREATE TRIGGER d156_guard_presence_update BEFORE UPDATE ON worked_presence FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : presence commune en lecture seule pendant le retour arriere';
END$$

CREATE TRIGGER d156_guard_presence_delete BEFORE DELETE ON worked_presence FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'D-156 : presence commune en lecture seule pendant le retour arriere';
END$$

DELIMITER ;
