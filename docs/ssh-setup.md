# SurgicalHub — Configuration des clés SSH (Hostinger)

Ce fichier centralise les clés SSH nécessaires au déploiement. **Seules les
clés PUBLIQUES vont ici** (et même celles-ci ne devraient idéalement pas
être commitées si le repo est public — préférez un gestionnaire de secrets
si possible). **Ne mettez jamais de clé PRIVÉE dans ce fichier ni dans le
repo.**

Il y a deux usages distincts :

1. **Connexion SSH locale → serveur Hostinger** (pour déployer)
2. **Deploy key serveur → GitHub** (pour `git clone` / `git pull` du dépôt
   privé depuis le serveur)

---

## 1. Connexion SSH locale → Hostinger

### 1.1 Générer une paire de clés (sur votre machine, si pas déjà fait)

```bash
ssh-keygen -t ed25519 -C "samy@surgicalhub-deploy" -f ~/.ssh/surgicalhub_hostinger
```

→ Crée `~/.ssh/surgicalhub_hostinger` (privée, **ne la partagez jamais**) et
`~/.ssh/surgicalhub_hostinger.pub` (publique).

### 1.2 Coller votre clé PUBLIQUE ici (pour référence / à ajouter sur Hostinger)

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMDcVFk8oihxXDhVj+iKUTAytqwBhRXSuL/ZsFTL5rW5 samy.ftaita89@gmail.com
```

### 1.3 Ajouter la clé publique sur Hostinger

Soit via hPanel (Avancé > SSH Access > "Manage SSH Keys" > coller la clé
publique), soit manuellement :

```bash
# Depuis votre machine — copie la clé publique vers le serveur
ssh-copy-id -p 65002 -i ~/.ssh/surgicalhub_hostinger.pub u245913739@91.108.115.96

# Si ssh-copy-id n'est pas disponible (Windows) :
cat ~/.ssh/surgicalhub_hostinger.pub | ssh -p 65002 u245913739@91.108.115.96 \
  "mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys"
```

### 1.4 Configurer un alias pratique (`~/.ssh/config` local)

```text
Host surgicalhub-prod
  HostName 91.108.115.96
  Port 65002
  User u245913739
  IdentityFile ~/.ssh/surgicalhub_hostinger
```

Ensuite : `ssh surgicalhub-prod` suffit (plus besoin de retaper port/host/user).

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
