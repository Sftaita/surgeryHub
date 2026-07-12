# MAIL_SAFE_MODE — garde-fou anti-envoi-accidentel d'emails réels

**Ce document est la référence complète du mécanisme.** Contexte : incident du
2026-07-12 (voir `docs/production.md`, section incidents) où un test manuel en
production a envoyé 16 emails réels à de vraies personnes parce qu'il réutilisait des
comptes réels au lieu de comptes jetables. Le code testé était correct — la faille était
procédurale. Ce mécanisme la rend techniquement impossible à reproduire, dans n'importe
quel environnement, sans avoir à s'en souvenir à chaque test.

**Mise à jour 2026-07-12 (le jour même) :** un second problème est apparu dès la
première utilisation réelle du garde-fou en développement local contre une copie de
production — le mode initial n'avait qu'un seul comportement (filtrer/rejeter tout
destinataire non autorisé), ce qui bloquait **aussi** les emails en local/dev/test alors
que `MAILER_DSN` y pointe déjà vers Mailpit, un simple capteur local incapable de livrer
quoi que ce soit sur Internet. Résultat : impossible de visualiser un email réel dans
Mailpit lors d'un test avec des données de copie de production. Le garde-fou a donc été
étendu avec un second mode — **capture** — décrit ci-dessous.

Voir aussi : `docs/decisions.md` D-061 (décision d'architecture complète).

---

## 1. Fonctionnement

`App\EventListener\MailSafeModeListener` (`backend/src/EventListener/MailSafeModeListener.php`)
écoute `Symfony\Component\Mailer\Event\MessageEvent` — l'événement du composant Mailer
de Symfony déclenché pour **chaque** email envoyé par l'application, quel que soit le
flux métier d'origine (invitations, déploiement de planning, modification de planning,
facturation, relances d'absences, alertes…). Audit exhaustif du repo (2026-07-12) :
seuls deux `MessageHandler` appellent `MailerInterface::send()` dans tout le backend —
`SendTemplatedEmailMessageHandler` et `SendBillingEmailMessageHandler` — et `MessageEvent`
les couvre tous les deux sans exception, plus toute future voie d'envoi (le point
d'interception est le composant Mailer lui-même, pas un des appelants).

Quand le garde-fou est **actif** (voir §2 pour `MAIL_SAFE_MODE`), il résout vers l'un
de ces deux **modes de délivrance** :

### Mode `capture`

Utilisé quand `MAILER_DSN` pointe vers un **sink local vérifié** — un capteur SMTP type
Mailpit/MailHog qui ne peut, par construction, livrer aucun email en dehors de la
machine (voir `MAIL_SAFE_LOCAL_SINKS` en §2). Dans ce mode :

- Les destinataires (`To`/`Cc`/`Bcc`) sont **laissés intacts** — aucun filtrage. C'est
  volontaire : la garantie de sécurité vient du transport vérifié, pas du filtrage. Un
  vrai email de test doit pouvoir cibler visiblement de vraies adresses dans Mailpit
  pour être utile.
- Un en-tête de diagnostic est ajouté au message : `X-SurgicalHub-Mail-Safe-Mode:
  captured-locally`.
- Un log `MAIL_SAFE_MODE: email captured locally...` est émis avec le sujet et tous les
  destinataires réels.

### Mode `allowlist`

Le comportement d'origine (avant le 2026-07-12) — utilisé partout où le transport
**peut** réellement livrer vers Internet (un vrai relais SMTP, même en dehors de la
prod — ex. staging) :

- Tout destinataire dont l'adresse n'est ni explicitement autorisée
  (`MAIL_SAFE_ALLOWED_RECIPIENTS`) ni sur un domaine autorisé
  (`MAIL_SAFE_ALLOWED_DOMAINS`) est **retiré** du message envoyé — à la fois des en-têtes
  du message et de l'`Envelope` SMTP réellement utilisé pour la livraison.
- Si plus aucun destinataire autorisé ne reste après filtrage, l'envoi est **annulé
  entièrement** (`MessageEvent::reject()`).
- Chaque filtrage/annulation est loggué en `warning` avec le sujet, les destinataires
  bloqués et les destinataires conservés.

Quand le garde-fou est **inactif** (production normale), le listener ne fait strictement
rien — comportement identique à avant son introduction.

### Comment le mode de délivrance est choisi

`MAIL_SAFE_DELIVERY_MODE` (voir §2) exprime l'intention :

- `auto` (défaut) — `capture` si `MAILER_DSN` correspond à un sink local vérifié,
  `allowlist` sinon. C'est ce qui donne un mode capture **sans configuration
  supplémentaire** en dev/test local (où `MAILER_DSN` pointe déjà vers Mailpit), tout en
  restant sûr par défaut partout où un vrai relais est configuré (staging, prod).
- `allowlist` — force le filtrage strict, quel que soit le transport.
- `capture` — force la capture, **mais est refusé** si `MAILER_DSN` ne correspond pas à
  un sink local reconnu : dans ce cas, le listener retombe sur `allowlist` et loggue un
  `critical`. Une variable mal configurée ne peut donc **jamais** désactiver le filtrage
  par accident.

**La décision ne repose jamais sur `APP_ENV`/`kernel.environment` seul** — uniquement
sur deux faits vérifiables : le garde-fou est-il actif (`MAIL_SAFE_MODE`), et le
transport configuré peut-il réellement délivrer vers Internet (`MAILER_DSN` comparé à
`MAIL_SAFE_LOCAL_SINKS`). Un environnement `dev`/`test` avec un vrai relais SMTP
configuré par erreur retombe automatiquement en `allowlist`, pas en `capture`.

### ⚠️ Où lire la preuve qu'un envoi a été bloqué ou capturé

Le Mailer de Symfony est câblé sur Messenger dans cette application (envoi async). En
conséquence, `MessageEvent` se déclenche **deux fois** par email : une première fois
("queued") sur un clone jetable au moment où un handler appelle
`MailerInterface::send()` — le `$email` du handler lui-même n'est **jamais modifié**,
ne pas chercher la preuve du filtrage/capture dans les logs `SendBillingEmailMessageHandler`/
`SendTemplatedEmailMessageHandler` (ils logguent l'intention avant filtrage, pas
l'issue réelle — c'est documenté explicitement dans leur code) — puis une seconde fois,
plus tard, de façon asynchrone, sur un autre clone, juste avant l'envoi réseau réel :
c'est cette seconde passe qui détermine la livraison effective, et le listener la
couvre exactement de la même façon. **Seules les lignes de log `MAIL_SAFE_MODE: ...`
émises par le listener lui-même font foi** de ce qui a réellement été bloqué ou capturé.

---

## 2. Variables d'environnement

| Variable | Défaut (`.env`) | Rôle |
|---|---|---|
| `MAIL_SAFE_MODE` | `auto` | Active/désactive le garde-fou. `auto` = actif partout sauf `kernel.environment === 'prod'` · `on`/`1` = forcé actif (y compris en prod) · `off`/`0` = forcé inactif (y compris hors prod) |
| `MAIL_SAFE_DELIVERY_MODE` | `auto` | Choisit le mode quand le garde-fou est actif. `auto` = `capture` si le transport est un sink local vérifié, `allowlist` sinon · `capture` = forcé, refusé (repli `allowlist`) si le transport n'est pas un sink local · `allowlist` = forcé, quel que soit le transport |
| `MAIL_SAFE_LOCAL_SINKS` | `mailer:1025,localhost:1025,127.0.0.1:1025` | Paires `host:port` reconnues comme sinks locaux (Mailpit/MailHog). Le transport `null://` de Symfony Mailer est toujours reconnu, indépendamment de cette liste. |
| `MAIL_SAFE_ALLOWED_DOMAINS` | `surgicalhub.internal` | Domaines autorisés à recevoir en mode `allowlist`, séparés par virgules. |
| `MAIL_SAFE_ALLOWED_RECIPIENTS` | *(vide)* | Adresses exactes autorisées en plus des domaines, en mode `allowlist`. |

Ces variables sont définies avec leurs valeurs par défaut dans `backend/.env`
(committé) — **aucune configuration n'est nécessaire pour le cas courant.**

---

## 3. Cas d'usage

### 3.1 Développement local / CI (cas par défaut, zéro action)

`APP_ENV=dev` et `APP_ENV=test` → `MAIL_SAFE_MODE=auto` résout à **actif**, et
`MAILER_DSN` local pointe déjà vers le container `mailer` (Mailpit,
`smtp://mailer:1025`/`smtp://127.0.0.1:1025`, jamais un vrai relais) — reconnu comme
sink local par le `MAIL_SAFE_LOCAL_SINKS` par défaut → `MAIL_SAFE_DELIVERY_MODE=auto`
résout à **`capture`**.

Concrètement : un déploiement testé en local, même avec une copie de données de
production (comptes/missions réels), fait apparaître les emails dans Mailpit avec
leurs **vrais destinataires visibles** — utile pour vérifier le ciblage réel — tout en
garantissant qu'**aucune** de ces adresses ne reçoit quoi que ce soit réellement,
puisque le transport lui-même (Mailpit) ne peut pas atteindre Internet. C'est une
défense en profondeur différente de l'ancien comportement (qui filtrait/rejetait tout,
y compris en local) : ici la garantie vient du transport vérifié, pas du filtrage des
destinataires.

Si `MAILER_DSN` local était accidentellement reconfiguré vers un vrai relais SMTP, il
ne correspondrait plus à `MAIL_SAFE_LOCAL_SINKS` → `auto` retomberait automatiquement
sur `allowlist`, qui filtre/rejette comme avant — la protection ne dépend donc jamais
d'une hypothèse sur `MAILER_DSN`, elle la vérifie à chaque résolution.

### 3.2 Comptes de test avec des adresses jetables

Le mode capture rend cette pratique optionnelle en local (les vraies adresses sont de
toute façon captées sans risque), mais elle reste recommandée par lisibilité — un email
adressé à `deploy-test-1@surgicalhub.internal` dans Mailpit est plus facile à
distinguer d'un vrai test que `arnauddeltour@hotmail.com` :

```bash
docker exec surgicalhub-php php bin/console app:user:create \
  test-manager@surgicalhub.internal 'MotDePasse!2026' ROLE_MANAGER --env=prod
```

### 3.3 Test manuel contrôlé en production réelle

**C'est le scénario qui a échoué le 2026-07-12** — désormais avec un filet de sécurité
technique en plus de la discipline procédurale :

```bash
# Sur le serveur, dans /opt/stack/apps/surgicalhub/.env (PAS backend/.env.prod.local,
# que ce stack ne lit pas — voir docs/deployment-versioning.md §5) :
MAIL_SAFE_MODE=on

# Recréer php + worker pour qu'ils relisent le fichier (un simple `docker restart`
# ne relit PAS ce fichier, seul `docker compose up -d` le fait) :
docker compose up -d

# ... exécuter les tests fonctionnels ...

# Retirer la ligne, puis à nouveau :
docker compose up -d
```

Avec `MAIL_SAFE_MODE=on`, le garde-fou est actif même en prod. `MAILER_DSN` de
production (relais Hostinger réel) ne correspond à aucun `MAIL_SAFE_LOCAL_SINKS` →
`MAIL_SAFE_DELIVERY_MODE=auto` résout automatiquement à `allowlist` — **jamais** à
`capture` — donc même une erreur de manipulation réutilisant de vraies
missions/personnes ne peut plus produire un envoi réel : l'email est soit filtré (si
mélange de destinataires réels/jetables), soit intégralement rejeté. Vérifier dans les
logs worker la ligne `MAIL_SAFE_MODE: rejected an email...` ou `stripped
non-allow-listed recipient(s)` pour confirmer que la protection a bien agi.

**Ne jamais laisser `MAIL_SAFE_MODE=on` actif après la session de test** — la production
doit revenir à `auto` (résout à inactif) dès le test terminé.

### 3.4 Désactivation volontaire (staging avec envoi réel)

Si un environnement `staging` était introduit un jour avec `kernel.environment` différent
de `prod` mais devant néanmoins envoyer de vrais emails, définir explicitement
`MAIL_SAFE_MODE=off` dans son `.env.staging.local`. À utiliser avec une extrême
prudence — jamais pour contourner le garde-fou "parce que c'est plus rapide". Si un
staging avec un vrai relais SMTP doit rester protégé mais ne pointe pas vers un sink
local, le comportement par défaut (`MAIL_SAFE_DELIVERY_MODE=auto`) l'y place déjà en
`allowlist` — aucune configuration supplémentaire n'est nécessaire pour ce cas non plus.

---

## 4. Tester avec Mailpit (développement local)

Le `MAILER_DSN` local pointe déjà vers le container `mailer` (Mailpit) — interface web
sur **http://localhost:8026** (le port 8025 appartient à un tout autre projet local,
ne pas confondre). Depuis le 2026-07-12, le mode sûr n'a **aucun effet filtrant** sur
les emails de test en local (mode `capture`, voir §1/§3.1) — les destinataires réels
apparaissent tels quels dans Mailpit, avec l'en-tête `X-SurgicalHub-Mail-Safe-Mode:
captured-locally` pour confirmer que c'est bien le garde-fou qui les a laissés passer
(et non une absence de garde-fou). Pour observer un email de test complet :

1. Déclencher le flux (déploiement, modification…) — comptes jetables `@surgicalhub.internal`
   recommandés pour la lisibilité (§3.2), mais pas obligatoires pour la sécurité.
2. **Important** : si le flux passe par le worker Messenger, le redémarrer d'abord après
   toute modification d'un handler ou d'un template email — voir `docs/docker.md` §9
   ("⚠️ Redémarrage obligatoire...", `make messenger-restart`).
3. Ouvrir **http://localhost:8026** pour lire l'email réellement rendu.

---

## 5. Tests

- `backend/tests/Unit/EventListener/MailSafeModeListenerTest.php` — matrice de décision
  complète : activation par environnement, override `on`/`off`, résolution `capture` vs
  `allowlist` (`auto`, forcé, refus de `capture` contre un relais externe avec repli
  `allowlist`), transport `null://` toujours reconnu, domaines/adresses autorisés en
  mode `allowlist`, filtrage `To`/`Cc`/`Bcc` indépendant, rejet total, non-régression sur
  l'`Envelope` SMTP réel, no-op complet quand rien n'est filtré.
- `backend/tests/Integration/MailSafeModeIntegrationTest.php` — preuve que le listener
  est réellement câblé dans le vrai conteneur applicatif, et que la résolution réelle en
  environnement `test` (DSN Mailpit committé) capture bien un destinataire réel au lieu
  de le rejeter.

---

## 6. Garanties et limites assumées

**Ce que ce mécanisme garantit** : aucun email ne peut techniquement atteindre une
adresse réelle depuis un environnement où `MAIL_SAFE_MODE` résout à actif — **soit**
parce que le destinataire est filtré/rejeté (mode `allowlist`), **soit** parce que le
transport configuré est vérifié incapable de livrer en dehors de la machine (mode
`capture`) — y compris en cas d'erreur humaine sur les données de test utilisées. Le
mode `capture` ne peut jamais s'activer contre un transport non reconnu comme sink
local, même si explicitement demandé (`MAIL_SAFE_DELIVERY_MODE=capture` retombe sur
`allowlist` avec un log `critical` dans ce cas).

**Ce qu'il ne garantit pas** : si `MAIL_SAFE_MODE` résout à inactif (production réelle
en fonctionnement normal, ou `off` explicite quelque part), le mécanisme est
volontairement transparent — c'est le comportement voulu, pas une faille. La liste
`MAIL_SAFE_LOCAL_SINKS` doit rester exacte : y ajouter un host:port qui, en réalité,
relaie vers l'extérieur (ex. un proxy SMTP mal nommé) romprait la garantie du mode
capture — cette liste est un élément de confiance, à ne modifier qu'en toute
connaissance de cause. La discipline procédurale (comptes jetables, cf.
`docs/deployment-versioning.md`) reste recommandée pour la lisibilité des tests, mais
n'est plus la seule ligne de défense en local depuis le mode `capture`.
