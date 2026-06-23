# Synchro AAD

Plugin GLPI **« Synchro AAD »** permettant de synchroniser les utilisateurs depuis **Microsoft Entra ID** (ex-Azure AD) et de proposer une **connexion SSO** (OpenID Connect / OAuth 2.0) aux utilisateurs GLPI.

- Compatible **GLPI 11.x** (testé pour la branche 11.0).
- Gère plusieurs connexions Entra ID indépendantes.
- Synchronisation complète, par connexion, ou ciblée sur un utilisateur.
- Filtrage des comptes par domaine d'e-mail.
- Désactivation ou suppression automatique des comptes absents d'Entra ID.
- Connexion SSO : bouton « Se connecter avec … » sur la page de login, avec création automatique des comptes (optionnelle).

## Prérequis

- GLPI 11.0.x
- PHP ≥ 8.1 avec l'extension **cURL**
- Un accès administrateur à un tenant **Microsoft Entra ID**

## Installation

1. Copier le dossier du plugin dans `plugins/syncaad` de votre installation GLPI.
2. Dans GLPI, aller dans **Configuration > Plugins**, puis **installer** et **activer** « Synchro AAD ».
3. Le menu apparaît dans **Configuration > Synchro AAD** (droit `plugin_syncaad`).

## Enregistrement de l'application dans Entra ID

Dans le portail Entra ID (Azure) → **App registrations** → **New registration** :

1. **Redirect URI** (type *Web*) : `https://<votre-glpi>/plugins/syncaad/front/sso.php`
   (cette URL est rappelée dans le formulaire de connexion du plugin ; laissez le champ vide pour utiliser la valeur par défaut).
2. Récupérez le **Application (client) ID** et le **Directory (tenant) ID**.
3. Créez un **client secret** (Certificates & secrets) et notez sa valeur.
4. **API permissions** :
   - Pour le **SSO** (flux délégué) : `openid`, `profile`, `email`, `User.Read`.
   - Pour la **synchronisation** (flux *client credentials*) : permission **Application** `User.Read.All` (avec *admin consent*).

## Configuration dans GLPI

Dans **Configuration > Synchro AAD**, créez une connexion :

- **Tenant ID**, **Client ID**, **Client Secret**.
- **Filtre de domaine** : ex. `@contoso.com`. Seuls les comptes dont l'e-mail/UPN se termine ainsi sont traités/autorisés.
- **Synchronisation** : désactivation et/ou suppression automatique des comptes absents.
- **Authentification SSO** :
  - *SSO activé* : affiche le bouton de connexion sur la page de login.
  - *Créer les comptes manquants* : crée automatiquement le compte GLPI à la première connexion SSO.
  - *Profil par défaut* et *Entité par défaut* : utilisés pour les comptes créés automatiquement.
  - *URL de redirection* : laisser vide pour utiliser l'URL par défaut.

## Synchronisation

- **Manuelle** : bouton *Synchroniser toutes les connexions* (page du plugin), ou
  `front/sync.php?connection_id=<id>` pour une connexion, ou
  `front/sync.php?user_id=<id>&connection_id=<id>` pour un utilisateur.
- **Automatique (cron)** : planifiez le script CLI

  ```bash
  php /chemin/vers/glpi/plugins/syncaad/scripts/sync.php
  ```

  Les e-mails Entra ID sont stockés dans GLPI via `glpi_useremails` ; le rapprochement
  se fait sur l'UPN (login) puis sur l'e-mail.

## Connexion SSO

Une fois le SSO activé sur une connexion, un bouton **« Se connecter avec <nom de la connexion> »**
apparaît sur la page de login GLPI. Le flux :

1. Redirection vers Entra ID (Authorization Code, paramètre `state` anti-CSRF).
2. Retour sur `front/sso.php`, échange du code contre un jeton, lecture du profil via Microsoft Graph (`/me`).
3. Vérification du domaine autorisé, puis rapprochement / création du compte GLPI.
4. Ouverture de la session GLPI (les habilitations GLPI standard s'appliquent).

## Notes

- Les comptes connectés en SSO sont marqués en authentification *externe* ; la session GLPI
  est ouverte directement après vérification du domaine et du jeton.
- Le secret client est stocké en base ; protégez l'accès à la base et au serveur en conséquence.
