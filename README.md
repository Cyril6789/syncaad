# SSO Microsoft

Plugin GLPI **« SSO Microsoft »** qui synchronise les utilisateurs depuis **Microsoft Entra ID** (ex‑Azure AD) et offre une **connexion SSO** (OpenID Connect / OAuth 2.0) aux utilisateurs GLPI.

Compatible **GLPI 11.x** (testé sur la branche 11.0).

## Fonctionnalités

- **Connexions multiples** : plusieurs tenants / applications Entra ID indépendants.
- **Synchronisation des comptes** depuis Entra ID (Microsoft Graph, flux *client credentials*) :
  - complète, par connexion, ou ciblée sur un utilisateur ;
  - **manuelle** (bouton dans GLPI) ou **automatique** via une action automatique GLPI ;
  - rapprochement sur l'**UPN** (login) puis sur l'**e‑mail** (stocké dans `glpi_useremails`).
- **Filtrage par domaine** : un ou plusieurs domaines (séparés par `,` ou `;`). Le filtre
  est appliqué sur **tous** les attributs porteurs du domaine : `mail`, `userPrincipalName`,
  `otherMails` et `proxyAddresses` (alias).
- **Hygiène des comptes** : **désactivation** et/ou **suppression** automatique des comptes
  absents d'Entra ID (par connexion).
- **Connexion SSO** : bouton « Se connecter avec … » sur la page de login GLPI, avec
  **création automatique** des comptes à la première connexion (optionnelle), profil et
  entité par défaut paramétrables.
- **Page de login remaniée** : quand au moins une connexion SSO est active, les boutons SSO
  deviennent l'accès principal et le formulaire GLPI classique est replié sous un discret
  « Connexion GLPI ».
- **Gestion fine des droits** : le droit `plugin_ssomicrosoft` est visible et modifiable par
  profil (Administration → Profils → onglet « SSO Microsoft »).
- **Diagnostic intégré** : la synchronisation manuelle affiche à l'écran le nombre de comptes
  reçus de Microsoft, le nombre traité après filtre, et **le message d'erreur exact** renvoyé
  par Microsoft en cas de problème.

## Prérequis

- GLPI 11.0.x
- PHP ≥ 8.1 avec l'extension **cURL**
- Un accès administrateur à un tenant **Microsoft Entra ID**

## Installation

1. Copier le dossier du plugin dans `plugins/ssomicrosoft` de votre installation GLPI.
2. Dans GLPI : **Configuration → Plugins**, puis **installer** et **activer** « SSO Microsoft ».
3. Le menu apparaît dans **Configuration → SSO Microsoft** (droit `plugin_ssomicrosoft`).

> Le droit `plugin_ssomicrosoft` est accordé automatiquement au profil **Super‑Admin**
> (et au profil de l'installateur) lors de l'installation, puis modifiable pour chaque profil
> via **Administration → Profils → onglet « SSO Microsoft »**.

## Enregistrement de l'application dans Entra ID

Portail Entra ID (Azure) → **App registrations** → **New registration**.

1. **Redirect URI** (type *Web*) : `https://<votre-glpi>/plugins/ssomicrosoft/front/sso.php`
   (rappelée dans le formulaire de connexion ; laissez le champ vide côté GLPI pour utiliser
   cette valeur par défaut).
2. Récupérez le **Application (client) ID** et le **Directory (tenant) ID**.
3. **Certificates & secrets** → créez un **client secret** et notez sa **Value**
   (⚠️ pas le *Secret ID*).
4. **API permissions** — deux flux distincts, deux types de permissions :

   | Usage | Type de permission | Permissions |
   |-------|--------------------|-------------|
   | **SSO** (flux délégué) | **Déléguée** | `openid`, `profile`, `email`, `User.Read` |
   | **Synchronisation** (client credentials) | **Application** | `User.Read.All` **+ « Grant admin consent »** |

   > ⚠️ **Point crucial** : la synchronisation s'exécute **sans utilisateur connecté**
   > (flux *client credentials*) et n'utilise **que les permissions de type Application**.
   > Une permission `User.Read.All` ajoutée en **Déléguée** est ignorée et renverra une
   > erreur `403 Authorization_RequestDenied: Insufficient privileges`. Vérifiez que la ligne
   > affiche bien **Type = Application** avec une **coche verte « Granted »**.

## Configuration dans GLPI

**Configuration → SSO Microsoft → +**, créez une connexion :

- **Tenant ID**, **Client ID**, **Client Secret** (la *Value* du secret).
- **Filtre de domaine** : ex. `@contoso.com`. Seuls les comptes dont le domaine se termine
  ainsi sont traités/autorisés. Plusieurs domaines : séparés par **virgule** ou
  **point‑virgule** (`@contoso.com, @fabrikam.com`).
- **Synchronisation** : désactivation et/ou suppression automatique des comptes absents.
- **Authentification SSO** :
  - *SSO activé* : affiche le bouton de connexion sur la page de login ;
  - *Créer les comptes manquants* : crée le compte GLPI à la première connexion SSO ;
  - *Profil par défaut* / *Entité par défaut* : pour les comptes créés automatiquement ;
  - *URL de redirection* : laisser vide pour utiliser l'URL par défaut.

Des aides contextuelles rappellent, directement dans le formulaire, les permissions Entra ID
à déclarer.

## Synchronisation

- **Manuelle** : bouton **« Synchroniser toutes les connexions »** (au‑dessus de la liste des
  connexions), ou `front/sync.php?connection_id=<id>` (une connexion), ou
  `front/sync.php?user_id=<id>&connection_id=<id>` (un utilisateur). Le résultat
  (comptes reçus / traités, erreurs éventuelles) s'affiche à l'écran.
- **Automatique (action GLPI)** — *recommandé* : à l'installation, le plugin enregistre
  l'action automatique **`ssomicrosoft`** (*Synchronisation des comptes depuis Entra ID*).
  Réglez fréquence et mode dans **Configuration → Actions automatiques → ssomicrosoft**.
  Elle est exécutée par le **cron GLPI** déjà en place — idéal en **Docker** (rien de plus à
  planifier ; il suffit que le cron GLPI tourne, p. ex. `php bin/console glpi:cron`).
- **Automatique (cron dédié)** — alternative : planifiez le script CLI

  ```bash
  php /chemin/vers/glpi/plugins/ssomicrosoft/scripts/sync.php
  ```

  En Docker, par exemple via la crontab de l'hôte :

  ```cron
  */15 * * * * docker exec -u www-data <conteneur_glpi> \
      php /var/www/glpi/plugins/ssomicrosoft/scripts/sync.php >/dev/null 2>&1
  ```

## Connexion SSO

Une fois le SSO activé sur une connexion, un bouton **« Se connecter avec <connexion> »**
apparaît sur la page de login GLPI. Le flux :

1. Redirection vers Entra ID (Authorization Code, paramètre `state` anti‑CSRF, replié aussi
   dans un cookie pour résister à l'aller‑retour cross‑site).
2. Retour sur `front/sso.php`, échange du code contre un jeton, lecture du profil via
   Microsoft Graph (`/me`).
3. Vérification du domaine autorisé, puis rapprochement / création du compte GLPI.
4. Ouverture de la session GLPI (les habilitations GLPI standard s'appliquent).

## Droits & profils

Le plugin déclare le droit `plugin_ssomicrosoft` (Lecture / Création / Modification /
Suppression / Purge). Gérez‑le par profil dans **Administration → Profils → onglet
« SSO Microsoft »**. Le droit **Modifier** est requis pour lancer une synchronisation.

## Dépannage

La synchronisation manuelle affiche un message par connexion :
**« N compte(s) reçu(s) de Microsoft, M traité(s) après filtre de domaine »**.

- **0 reçu** → problème d'**authentification / permission**. Le message « Erreur renvoyée par
  Microsoft » précise la cause :
  - `Authorization_RequestDenied: Insufficient privileges` → la permission **Application
    `User.Read.All`** manque ou le **consentement admin** n'est pas accordé (voir plus haut) ;
  - `AADSTS7000215: Invalid client secret` → mauvais secret (souvent le *Secret ID* collé au
    lieu de la *Value*) ou secret **expiré** ;
  - `AADSTS700016 / AADSTS90002` → Client ID ou Tenant ID erroné.
- **Beaucoup reçus mais 0 traités** → le **filtre de domaine** est trop restrictif (aucun
  attribut du compte ne se termine par les domaines déclarés).

Un journal détaillé est également écrit dans `files/_log/ssomicrosoft.log` (le dossier peut
être relocalisé selon la configuration `GLPI_VAR_DIR`, fréquent en Docker).

## Notes & sécurité

- Les comptes connectés en SSO sont marqués en authentification **externe** ; la session GLPI
  est ouverte après vérification du domaine et du jeton.
- Le **client secret** est stocké en base : protégez l'accès à la base et au serveur en
  conséquence.
