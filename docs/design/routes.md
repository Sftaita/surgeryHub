# Routes — Espace Instrumentiste

Statuts : ✅ designé (prototype) · 🟡 partiel (stub/toast, ou documentation partielle sans maquette officielle) · ⬜ à designer

| Screen | Route | React Component | Layout | Status |
|---|---|---|---|---|
| Login | `/login` | `LoginScreen` | Plein écran (sans nav) | ✅ |
| Finaliser mon compte (onboarding) | `/complete-account` | `CompleteAccountPage` | Plein écran (sans nav) | 🟡 Documentation partielle — voir `screens/complete-account/README.md` |
| Aujourd'hui (accueil) | `/` | `TodayScreen` | AppLayout (header vagues + nav) | ✅ |
| Aujourd'hui — journée libre | `/` (état) | `TodayScreen` (empty state) | AppLayout | ✅ |
| Offres | `/offres` | `OffersScreen` | AppLayout | ✅ |
| Planning — semaine | `/planning` | `PlanningScreen` | AppLayout | ✅ |
| Planning — mois | `/planning?vue=mois` | `PlanningScreen` | AppLayout | ✅ |
| Modal jour | (overlay sur `/planning` et `/`) | `DayModal` | Sheet / Dialog | ✅ |
| Encodage mission | `/missions/:id/encodage` | `EncodeScreen` | AppLayout sans header standard (header encodage propre) | ✅ |
| Ajouter du matériel (wizard 3 étapes) | (overlay sur encodage) | `MaterialWizard` | Sheet / Dialog | ✅ |
| Nouvelle intervention | (overlay sur encodage) | `NewInterventionSheet` | Sheet / Dialog | ✅ |
| Heures prestées | (overlay sur encodage) | `WorkedHoursSheet` | Sheet / Dialog | ✅ |
| Récapitulatif avant validation | (overlay sur encodage) | `EncodeRecapSheet` | Sheet / Dialog | ✅ |
| Déclarer une mission | (overlay sur `/`) | `DeclareMissionSheet` | Sheet / Dialog | ✅ |
| Détail mission | `/missions/:id` | `MissionDetailScreen` | AppLayout | ⬜ |
| Notifications | `/notifications` | `NotificationsScreen` | AppLayout | 🟡 (badge + toast stub) |
| Messages | `/messages` | `MessagesScreen` | AppLayout (desktop uniquement en nav) | 🟡 |
| Profil | `/profil` | `ProfileScreen` | AppLayout | 🟡 (menu compte + photo de profil designés, reste de l'agencement non maquetté — voir `screens/profile/README.md`) |
| Mot de passe oublié | `/login/reset` | `ResetPasswordScreen` | Plein écran | 🟡 |
| Demander une invitation | `/invitation` | `InviteRequestScreen` | Plein écran | 🟡 |

## Navigation

- **Mobile** : bottom-nav 3 onglets — Aujourd'hui `/`, Planning `/planning`, Offres `/offres` (badge = nb d'offres proposées).
- **Desktop ≥900px** : sidebar — mêmes 3 entrées + Messages, Notifications, Profil + bloc utilisateur (menu : Mon profil, Se déconnecter).
- L'encodage est un écran enfant : bouton retour ← dans son header, la bottom-nav reste visible et permet d'en sortir.
