-- D-156 — retire les verrous de retour arrière (guard-install.sql), juste AVANT de redéployer
-- une version qui contient D-156 (le nouveau code écrit les parts imputées et les présences).
DROP TRIGGER IF EXISTS d156_guard_execution_update;
DROP TRIGGER IF EXISTS d156_guard_execution_delete;
DROP TRIGGER IF EXISTS d156_guard_mission_update;
DROP TRIGGER IF EXISTS d156_guard_calculation_insert;
DROP TRIGGER IF EXISTS d156_guard_presence_update;
DROP TRIGGER IF EXISTS d156_guard_presence_delete;
