#!/usr/bin/env bash
# D-177 — test croisé de la décision B1/B2 et du repli, sur un MySQL ISOLÉ (jamais la production).
# Trois backends prêts (vendor + config/jwt) : NEW = 953ce9a (D-177), OLD = d32dbe7, RB = version de repli.
#   NEW=… OLD=… RB=… MYSQL="mysql -uroot -h127.0.0.1 -P3368" DB_URL_BASE="mysql://root@127.0.0.1:3368" \
#   bash deploy/rollback/d177/tests/run_rollback_cycle.sh
# Bases créées : d177_rbcycle_<horodatage> et …_b1 (supprimées à la fin si tout passe).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; D="$HERE/.."
: "${NEW:?}" "${OLD:?}" "${RB:?}" "${MYSQL:?}" "${DB_URL_BASE:?}"
STAMP=$(date +%Y%m%d%H%M%S); DB=d177_rbcycle_$STAMP; DB0=$DB; DB1=${DB0}_b1; W=$(mktemp -d)
export APP_ENV=prod APP_DEBUG=0 MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0" MAILER_DSN="null://null"
export MAIL_SAFE_MODE=auto MEDVUE_API_BASE_URL= SENTRY_DSN= FRONTEND_URL="http://127.0.0.1:5181" DEFAULT_URI="http://127.0.0.1:8099"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local label="$1"; shift; if "$@"; then ok "$label"; else ko "$label"; fi; }
cycle() { php "$HERE/rollback_cycle.php" "$1" "$2" "$3" 2>/dev/null | grep -E '^  '; }
console() { (cd "$1" && shift && php bin/console "$@" --no-interaction 2>/dev/null); }
worker() { console "$1" messenger:consume async --limit=60 --time-limit="${2:-30}" -q >/dev/null; }
q() { $MYSQL -N "$DB" -e "$1" 2>/dev/null; }
use_db() { DB=$1; export DATABASE_URL="$DB_URL_BASE/$DB?serverVersion=8.0.32&charset=utf8mb4"; }
fresh_db() { use_db "$1"; console "$NEW" doctrine:database:create -q && console "$NEW" doctrine:migrations:migrate -q && console "$NEW" messenger:setup-transports -q; }
field() { grep -E "^  $1" "$2" | sed -E "s/^  $1 *: //"; }

echo "== 1. Reprise publiée DIRECTEMENT au pool (aucune instrumentiste habituelle) — code D-177"
fresh_db "$DB"
cycle "$NEW" seed "$W/s1.json" | tee "$W/seed1"
worker "$NEW"
check "aucune priorité : 0 publication D-177 (ancienne règle B1 satisfaite)" [ "$(q 'SELECT COUNT(*) FROM mission_publication WHERE expires_at IS NOT NULL OR closed_at IS NOT NULL')" = 0 ]
check "mais audit ROOM_TAKEOVER_PRIORITY_BYPASSED enregistré" [ "$(q "SELECT COUNT(*) FROM audit_event WHERE event_type='ROOM_TAKEOVER_PRIORITY_BYPASSED'")" = 1 ]
check "00_decision.sql → B2" bash -c "$MYSQL -N $DB < '$D/00_decision.sql' 2>/dev/null | grep -q '^B2'"
cycle "$NEW" check "$W/s1.json" > "$W/new"
check "code D-177 : lectures sans erreur" grep -q 'aucune erreur 500' "$W/new"

echo "== 2. Hypothèse : retour BRUT à d32dbe7 sur ces données"
cycle "$OLD" check "$W/s1.json" > "$W/old"; grep -E '500|lectures' "$W/old"
check "d32dbe7 brut : historique de la mission en 500" grep -qE "/audit +500" "$W/old"
check "d32dbe7 brut : journal manager en 500" grep -qE "audit-events +500" "$W/old"

echo "== 3. Chemin B2 : 01 → 02 → 04, version de repli, worker et crons du repli"
for f in 01_queue.sql 02_transition.sql 04_pending_report.sql; do $MYSQL "$DB" < "$D/$f" >/dev/null 2>&1 || ko "$f"; done
cycle "$RB" check "$W/s1.json" > "$W/rb"
check "repli : toutes les lectures sans erreur" grep -q 'aucune erreur 500' "$W/rb"
check "repli : missions, affectations et publications identiques" [ "$(field empreinte "$W/rb")" = "$(field empreinte "$W/new")" ]
check "repli : demande nominative ordinaire toujours en attente" grep -qE "demande=PENDING" "$W/rb"
worker "$RB" 15
for c in app:missions:start-due app:planning:check-uncovered-escalations app:notifications:send-encoding-reminders; do console "$RB" "$c" -q >/dev/null; done
worker "$RB" 10
cycle "$RB" check "$W/s1.json" > "$W/rb2"
check "repli : aucune notification supplémentaire (worker + 3 crons)" [ "$(field compteurs "$W/rb2")" = "$(field compteurs "$W/new")" ]
check "repli : la mission de reprise reste prenable depuis le pool" bash -c "php '$HERE/rollback_cycle.php' '$RB' claim '$W/s1.json' 2>/dev/null | grep -q '^  ok'"

echo "== 4. Repli pendant que les messages de la reprise sont encore en file"
cycle "$NEW" seed "$W/s2.json" > "$W/seed2"
T1=$(sed -nE 's/^  T = mission ([0-9]+).*/\1/p' "$W/seed1"); T2=$(sed -nE 's/^  T = mission ([0-9]+).*/\1/p' "$W/seed2")
N1=$(q "SELECT COUNT(*) FROM outbound_notification WHERE mission_id=${T1:-0} AND notification_type='OPEN_MISSION_AVAILABLE'")
check "file : aucun message D-177 indécodable" [ "$(q "SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%RoomTakeoverPriority%' OR body LIKE '%PRIORITY_ENDED%' OR body LIKE '%PRIORITY_DECLINED%'")" = 0 ]
worker "$RB" 40
check "file traitée par le repli sans échec" [ "$(q "SELECT COUNT(*) FROM messenger_messages")" = 0 ]
N2=$(q "SELECT COUNT(*) FROM outbound_notification WHERE mission_id=${T2:-0} AND notification_type='OPEN_MISSION_AVAILABLE'")
check "sollicitation du pool envoyée une seule fois : reprise 2 (worker repli) $N2 = reprise 1 (worker D-177) $N1" [ "${N1:-0}" -gt 0 -a "$N1" = "$N2" ]

echo "== 5. Retour en avant (05) — code D-177"
$MYSQL "$DB" < "$D/05_roll_forward.sql" >/dev/null 2>&1
BEFORE=$(q "SELECT COUNT(*) FROM outbound_notification")
cycle "$NEW" check "$W/s2.json" > "$W/fwd"
check "D-177 : lectures sans erreur après retour en avant" grep -q 'aucune erreur 500' "$W/fwd"
check "expire-priorities --dry-run : 0 / 0" bash -c "cd '$NEW' && php bin/console app:room-takeover:expire-priorities --dry-run 2>/dev/null | grep -q 'Priorities due: 0, recipient absent: 0'"
worker "$NEW" 10
check "retour en avant : aucune notification supplémentaire" [ "$(q "SELECT COUNT(*) FROM outbound_notification")" = "$BEFORE" ]

echo "== 6. Contrôle : sans reprise, B1 reste valable"
fresh_db "$DB1"
cycle "$NEW" seed-ordinary "$W/s3.json" >/dev/null; worker "$NEW"
check "missions ordinaires seules → 00_decision.sql = B1" bash -c "$MYSQL -N $DB1 < '$D/00_decision.sql' 2>/dev/null | grep -q '^B1'"
cycle "$OLD" check "$W/s3.json" > "$W/b1"
check "d32dbe7 brut : lectures sans erreur" grep -q 'aucune erreur 500' "$W/b1"

echo; echo "== $PASS ok, $FAIL échec(s)"
if [ "$FAIL" = 0 ]; then $MYSQL -e "DROP DATABASE $DB0; DROP DATABASE $DB1" 2>/dev/null; rm -rf "$W"; else echo "bases conservées : $DB0 $DB1 — traces : $W"; fi
exit $((FAIL > 0))
