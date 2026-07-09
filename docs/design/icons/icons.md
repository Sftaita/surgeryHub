# Iconographie

**Prototype : Lucide** (outline, stroke 2–2.4px, linecap/linejoin round). **Production : MUI Icons** — mapper 1:1, conserver le style outline et les tailles.

## Tailles
- Nav mobile : 21px · Sidebar : 19px · Inline card : 15–19px · Boutons icône : 17–20px dans conteneur 40px.
- Icônes en pastille : cercle 34–48px fond teinté (`green-50` info, `amber-50` alerte…), icône 15–20px `green-700`/`amber-700`.

## Mapping Lucide → MUI

| Usage | Lucide | MUI |
|---|---|---|
| Aujourd'hui | house | Home / HomeOutlined |
| Planning | calendar-days | CalendarMonth |
| Offres | tag | LocalOffer / SellOutlined |
| Notifications | bell | NotificationsNone |
| Messages | message-square | ChatBubbleOutline |
| Profil / chirurgien | user-round | PersonOutline |
| Horloge | clock | Schedule |
| Lieu | map-pin | PlaceOutlined |
| Fermer | x | Close |
| Retour / chevrons | chevron-left/right/down | ChevronLeft/Right, ExpandMore |
| Ajouter | plus / plus-circle | Add / AddCircleOutline |
| Valider (coche) | check | Check |
| Email | mail | MailOutline |
| Mot de passe | lock | LockOutlined |
| Œil (mdp) | eye / eye-off | Visibility / VisibilityOff |
| Sécurité | shield | ShieldOutlined / VerifiedUser |
| Matériel | package | Inventory2Outlined |
| Interventions | folder | FolderOutlined |
| Encodage sync | cloud-check (custom path) | CloudDone |
| Alerte | triangle-alert | WarningAmber |
| Déconnexion | log-out | Logout |
| Recherche | search | Search |
| Date déclaration | calendar | CalendarToday |

## Règles
- Pas d'emoji comme icône (exception : 👋 dans le greeting, qui est du texte).
- Pas de glyphes unicode décoratifs. Le logo (`assets/`) est le seul vecteur bespoke.
- Icônes toujours accompagnées d'un libellé ou d'un `aria-label`.
