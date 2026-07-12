# MAIL_SAFE_MODE — garde-fou anti-envoi-accidentel d'emails réels

**Ce document est la référence complète du mécanisme.** Contexte : incident du
2026-07-12 (voir `docs/production.md`, section incidents) où un test manuel en
production a envoyé 16 emails réels à de vraies personnes parce qu'il réutilisait des
comptes réels au lieu de comptes jetables. Le code testé était correct — la faille était
procédurale. Ce mécanisme la rend techniquement impossible à reproduire, dans n'importe
quel environnement, sans avoir à s'en souvenir à chaque test.

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

Quand le mode sûr est **actif** :
- Tout destinataire (`To`/`Cc`/`Bcc`) dont l'adresse n'est ni explicitement autorisée
  ni sur un domaine autorisé est **retiré** du message envoyé — à la fois des en-têtes
  du message et de l'`Envelope` SMTP réellement utilisé pour la livraison (les deux
  sont nécessaires, voir D-061 pour le détail technique).
- Si plus aucun destinataire autorisé ne reste après filtrage, l'envoi est **annulé
  entièrement** (`MessageEvent::reject()`) — rien ne part, nulle part, pour personne.
- Chaque filtrage/annulation est loggué en `warning` avec le sujet, les destinataires
  bloqués et les destinataires conservés.

Quand le mode sûr est **inactif** (production normale), le listener ne fait strictement
rien — comportement identique à avant son introduction.

### ⚠️ Où lire la preuve qu'un envoi a été bloqué

Le Mailer de Symfony est câblé sur Messenger dans cette application (envoi async). En
conséquence, `MessageEvent` se déclenche **deux fois** par email : une première fois
("queued") sur un clone jetable au moment où un handler appelle
`MailerInterface::send()` — le `$email` du handler lui-même n'est **jamais modifié**,
ne pas chercher la preuve du filtrage dans les logs `SendBillingEmailMessageHandler`/
`SendTemplatedEmailMessageHandler` (ils logguent l'intention avant filtrage, pas
l'issue réelle — c'est documenté explicitement dans leur code) — puis une seconde fois,
plus tard, de façon asynchrone, sur un autre clone, juste avant l'envoi réseau réel :
c'est cette seconde passe qui détermine la livraison effective, et le listener la
couvre exactement de la même façon. **Seules les lignes de log `MAIL_SAFE_MODE: ...`
émises par le listener lui-même font foi** de ce qui a réellement été bloqué.

---

## 2. Variables d'environnement

| Variable | Défaut (`.env`) | Rôle |
|---|---|---|
| `MAIL_SAFE_MODE` | `auto` | `auto` = actif partout sauf `kernel.environment === 'prod'` · `on`/`1` = forcé actif (y compris en prod) · `off`/`0` = forcé inactif (y compris hors prod) |
| `MAIL_SAFE_ALLOWED_DOMAINS` | `surgicalhub.internal` | Domaines autorisés à recevoir, séparés par virgules. Tout destinataire dont le domaine (partie après `@`) figure dans cette liste passe sans filtrage. |
| `MAIL_SAFE_ALLOWED_RECIPIENTS` | *(vide)* | Adresses exactes autorisées en plus des domaines — utile pour une adresse personnelle réelle utilisée volontairement en test (ex: `moi@gmail.com`), sans avoir à l'ajouter comme domaine complet. |

Ces trois variables sont définies avec leurs valeurs par défaut dans `backend/.env`
(committé) — **aucune configuration n'est nécessaire pour le cas courant.**

---

## 3. Cas d'usage

### 3.1 Développement local / CI (cas par défaut, zéro action)

`APP_ENV=dev` et `APP_ENV=test` → `MAIL_SAFE_MODE=auto` résout à **actif**. Combiné au
fait que `MAILER_DSN` local pointe déjà vers le catcher Mailpit
(`smtp://mailer:1025`/`smtp://127.0.0.1:1025`, jamais un vrai relais), ceci offre une
**défense en profondeur** : même si un `MAILER_DSN` de test/dev était accidentellement
mal configuré vers un vrai relais SMTP un jour, aucun email ne pourrait atteindre une
vraie personne — seuls les comptes `@surgicalhub.internal` (ou explicitement
autorisés) passeraient, et Mailpit les capture de toute façon pour inspection.

### 3.2 Comptes de test avec des adresses jetables

Toujours utiliser le domaine `@surgicalhub.internal` pour les comptes créés via
`app:user:create` lors de tests manuels (local ou prod) — c'est le domaine autorisé par
défaut, aucune configuration supplémentaire nécessaire :

```bash
docker exec surgicalhub-php php bin/console app:user:create \
  test-manager@surgicalhub.internal 'MotDePasse!2026' ROLE_MANAGER --env=prod
```

### 3.3 Test manuel contrôlé en production réelle

**C'est le scénario qui a échoué le 2026-07-12** — désormais avec un filet de sécurité
technique en plus de la discipline procédurale :

```bash
# Sur le serveur, temporairement dans .env.prod.local :
MAIL_SAFE_MODE=on

# Recréer php + worker pour qu'ils relisent le fichier (un simple `docker restart`
# ne relit PAS .env.prod.local, seul `docker compose up -d` le fait) :
docker compose up -d

# ... exécuter les tests fonctionnels ...

# Retirer la ligne, puis à nouveau :
docker compose up -d
```

Avec `MAIL_SAFE_MODE=on`, même une erreur de manipulation réutilisant de vraies
missions/personnes ne peut plus produire un envoi réel — l'email est soit filtré
(si mélange de destinataires réels/jetables), soit intégralement rejeté. Vérifier dans
les logs worker la ligne `MAIL_SAFE_MODE: rejected an email...` ou `stripped
non-allow-listed recipient(s)` pour confirmer que la protection a bien agi.

**Ne jamais laisser `MAIL_SAFE_MODE=on` actif après la session de test** — la production
doit revenir à `auto` (résout à inactif) dès le test terminé.

### 3.4 Désactivation volontaire (staging avec envoi réel)

Si un environnement `staging` était introduit un jour avec `kernel.environment` différent
de `prod` mais devant néanmoins envoyer de vrais emails, définir explicitement
`MAIL_SAFE_MODE=off` dans son `.env.staging.local`. À utiliser avec une extrême
prudence — jamais pour contourner le garde-fou "parce que c'est plus rapide".

---

## 4. Tester avec Mailpit (développement local)

Le `MAILER_DSN` local pointe déjà vers le container `mailer` (Mailpit) — voir
`docs/docker.md` §9 pour son URL web. Le mode sûr n'a **aucun effet visible** sur les
emails déjà destinés à un compte `@surgicalhub.internal` ou à Mailpit en général — il
filtre uniquement les destinataires non autorisés, jamais le transport lui-même. Pour
observer un email de test complet :

1. Créer un compte `@surgicalhub.internal` (voir §3.2).
2. Déclencher le flux (déploiement, modification…).
3. **Important** : si le flux passe par le worker Messenger, le redémarrer d'abord après
   toute modification d'un handler ou d'un template email — voir `docs/docker.md` §9
   ("⚠️ Redémarrage obligatoire...", `make messenger-restart`).
4. Ouvrir Mailpit (interface web, port documenté dans `docs/docker.md`) pour lire l'email
   réellement rendu.

---

## 5. Tests

- `backend/tests/Unit/EventListener/MailSafeModeListenerTest.php` — matrice de décision
  complète (activation par environnement, override `on`/`off`, domaines/adresses
  autorisés, filtrage `To`/`Cc`/`Bcc` indépendant, rejet total, non-régression sur
  l'`Envelope` SMTP réel, no-op complet quand rien n'est filtré).
- `backend/tests/Integration/MailSafeModeIntegrationTest.php` — preuve que le listener
  est réellement câblé dans le vrai conteneur applicatif (un test unitaire seul ne
  détecte pas une erreur de câblage `services.yaml`).

---

## 6. Garanties et limites assumées

**Ce que ce mécanisme garantit** : aucun email ne peut techniquement atteindre une
adresse réelle depuis un environnement où `MAIL_SAFE_MODE` résout à actif — y compris
en cas d'erreur humaine sur les données de test utilisées.

**Ce qu'il ne garantit pas** : si `MAIL_SAFE_MODE` résout à inactif (production réelle
en fonctionnement normal, ou `off` explicite quelque part), le mécanisme est
volontairement transparent — c'est le comportement voulu, pas une faille. La discipline
procédurale (comptes jetables, cf. `docs/deployment-versioning.md`) reste nécessaire
pour ce cas précis ; ce garde-fou ne remplace pas cette discipline, il protège contre
sa défaillance dans tous les autres cas.
