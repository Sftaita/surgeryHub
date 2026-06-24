# SurgicalHub — Configuration des clés SSH

Ce fichier centralise les clés SSH nécessaires au déploiement. **Seules les
clés PUBLIQUES vont ici**. **Ne mettez jamais de clé PRIVÉE dans ce fichier
ni dans le repo.**

---

## Serveur de production actuel — VPS Docker

```bash
ssh deploy@187.124.55.15
```

Clés publiques autorisées (`~deploy/.ssh/authorized_keys` sur le serveur) :

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMDcVFk8oihxXDhVj+iKUTAytqwBhRXSuL/ZsFTL5rW5 samy.ftaita89@gmail.com
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIN11NuB3Lv0viPPzy+vKUMf6NcJEyoBzHFhOcLFV89Xw samy@surgicalhub.be
```

La seconde a été ajoutée le 2026-06-24 pour permettre les déploiements assistés
(clé privée locale `~/.ssh/surgicalhub_prod`).

Alias `~/.ssh/config` pratique (déjà configuré) :

```text
Host surgicalhub-prod
  HostName 187.124.55.15
  User deploy
  IdentityFile ~/.ssh/surgicalhub_prod
```

Ensuite : `ssh surgicalhub-prod` suffit.

Voir [`docs/production.md`](production.md) pour la procédure de déploiement complète.

---

## Historique — Hostinger (obsolète depuis 2026-06-16)

L'hébergement Hostinger (`u245913739@91.108.115.96:65002`) a été remplacé par
le VPS Docker. Les infos ci-dessous sont conservées à titre d'archive.

### Connexion SSH locale → Hostinger

```bash
ssh-keygen -t ed25519 -C "samy@surgicalhub-deploy" -f ~/.ssh/surgicalhub_hostinger
```

Clé publique Hostinger :

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMDcVFk8oihxXDhVj+iKUTAytqwBhRXSuL/ZsFTL5rW5 samy.ftaita89@gmail.com
```

Alias Hostinger :

```text
Host surgicalhub-hostinger
  HostName 91.108.115.96
  Port 65002
  User u245913739
  IdentityFile ~/.ssh/surgicalhub_hostinger
```

---

## 2. Deploy key serveur → GitHub (pour `git pull` privé)

Si le dépôt `REPOSITORY_URL` est privé, le serveur a besoin de sa propre clé
pour `git pull`.

### 2.1 Générer la clé SUR LE SERVEUR

```bash
ssh -p 65002 u245913739@91.108.115.96
ssh-keygen -t ed25519 -C "surgicalhub-hostinger-deploy" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
```

### 2.2 Coller la clé publique générée ici (pour traçabilité)

Clé générée sur le serveur le 2026-06-13 (`~/.ssh/github_deploy`,
`~/.ssh/github_deploy.pub`) :

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICKJiwltwHt0UqSdjUfKoK3CLFPAZZQMDfuINGfJAvrJ surgicalhub-hostinger-deploy
```

### 2.3 Ajouter la clé sur GitHub

GitHub > Repo `SurgicalHub` > Settings > Deploy keys > "Add deploy key" >
coller la clé publique. Cocher "Allow write access" **uniquement** si le
serveur doit pousser (normalement non — lecture seule suffit pour `git
pull`).

### 2.4 Configurer SSH côté serveur pour utiliser cette clé avec GitHub

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  HostName github.com
  User git
  IdentityFile ~/.ssh/github_deploy
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

# Test
ssh -T git@github.com
```

Puis cloner avec l'URL SSH (`git@github.com:ORG/SurgicalHub.git`), pas HTTPS.

---

## Rappel sécurité

- Clé privée locale (`surgicalhub_hostinger`) : reste sur votre machine,
  jamais uploadée.
- Clé privée serveur (`github_deploy`) : reste sur le serveur (`~/.ssh/`,
  permissions `600`), jamais téléchargée ni commitée.
- Seules les clés **`.pub`** (publiques) peuvent être collées dans ce
  fichier — elles ne donnent aucun accès par elles-mêmes sans la clé privée
  correspondante.
