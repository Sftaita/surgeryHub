# Buttons

Captures : tous les écrans.

## Variantes

| Variante | Fond | Texte | Hauteur | Radius | Ombre | Usage |
|---|---|---|---|---|---|---|
| Primaire marque | `green-500` → hover `green-600` | blanc 14–16/700 | 46–56 | 12–14 | `ctaBrand` | Prendre la mission, Se connecter (v. login = `green-700`) |
| Primaire sombre | `green-700/800` → +1 cran | blanc 15/700 | 52–54 | 12–13 | `ctaGreen` | CTAs sheets, hero, Terminer l'encodage |
| Ambre | `amber-600` → `amber-700` | blanc 13.5/700 | 40–46 | 10–12 | — | Encoder (rappel), action « à encoder » |
| Secondaire outline | blanc, bord 1.5 `gray-200` | `gray-700` 14/600 | 46–50 | 12 | — | Refuser (hover : bord/texte rouges + fond `red-50`), Je ne trouve pas le matériel (hover ambre) |
| Dashed | `green-50`, bord 1.5 dashed `green-400` | `green-800` 14.5/700 | 52 | 14 | — | Déclarer une mission non prévue |
| Fantôme | transparent | `gray-500` 14/600 | 44 | — | — | Annuler, Retour, Continuer l'encodage |
| Icône | transparent ou blanc 12% (sur vert) | icône 17–20 | 40 | 10–12 | — | cloche, retour, œil |

## États
Hover : fond +1 cran (pleins) / teinte légère (autres) · Press : `translateY(0.5px)` (pleins) ou `scale(.96)` (icônes) · Focus : ring 3px · Loading : spinner 17px + libellé (« Connexion… ») · Disabled (production) : opacité .45, cursor default.

## Règles
Un seul bouton plein par vue. Paires : Refuser (flex 1) + Prendre (flex 1.7). CTA de sheet toujours pleine largeur, suivi de l'action fantôme.
