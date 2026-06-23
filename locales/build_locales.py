#!/usr/bin/env python3
"""Generate SSO Microsoft .pot/.po/.mo locale files (no gettext tooling needed)."""
import os
import struct

HERE = os.path.dirname(os.path.abspath(__file__))

# (msgid, msgid_plural_or_None, fr_msgstr_or_tuple, en_msgstr_or_tuple)
ENTRIES = [
    ('A PHP version providing random_bytes() is required by SSO Microsoft.', None,
     "Une version de PHP fournissant random_bytes() est requise par SSO Microsoft.",
     "A PHP version providing random_bytes() is required by SSO Microsoft."),
    ('Aucun compte reçu de Microsoft : vérifiez la permission Application « User.Read.All » (avec consentement administrateur), ainsi que le tenant / client / secret de la connexion.', None,
     "Aucun compte reçu de Microsoft : vérifiez la permission Application « User.Read.All » (avec consentement administrateur), ainsi que le tenant / client / secret de la connexion.",
     "No account received from Microsoft: check the Application permission \"User.Read.All\" (with admin consent), as well as the connection's tenant / client / secret."),
    ('Aucune connexion active à synchroniser.', None,
     "Aucune connexion active à synchroniser.",
     "No active connection to synchronize."),
    ('Connexion « %1$s » : %2$d compte(s) reçu(s) de Microsoft, %3$d traité(s) après filtre de domaine.', None,
     "Connexion « %1$s » : %2$d compte(s) reçu(s) de Microsoft, %3$d traité(s) après filtre de domaine.",
     "Connection \"%1$s\": %2$d account(s) received from Microsoft, %3$d processed after the domain filter."),
    ('Application Entra ID (App registration) : renseignez le Tenant ID, le Client ID et la « Value » du Client Secret. La synchronisation utilise le flux « client credentials » : dans l\'application, ajoutez la permission de type Application « Microsoft Graph → User.Read.All » puis cliquez sur « Accorder un consentement administrateur ». ⚠️ Une permission Déléguée ne suffit PAS pour la synchronisation.', None,
     "Application Entra ID (App registration) : renseignez le Tenant ID, le Client ID et la « Value » du Client Secret. La synchronisation utilise le flux « client credentials » : dans l'application, ajoutez la permission de type Application « Microsoft Graph → User.Read.All » puis cliquez sur « Accorder un consentement administrateur ». ⚠️ Une permission Déléguée ne suffit PAS pour la synchronisation.",
     "Entra ID application (App registration): fill in the Tenant ID, Client ID and the Client Secret \"Value\". Synchronization uses the \"client credentials\" flow: in the application, add the Application-type permission \"Microsoft Graph → User.Read.All\" then click \"Grant admin consent\". Warning: a Delegated permission is NOT enough for synchronization."),
    ('Authentification SSO', None, "Authentification SSO", "SSO authentication"),
    ("La synchronisation périodique est assurée par l'action automatique « ssomicrosoft » (Synchronisation des comptes depuis Entra ID), planifiable dans Configuration → Actions automatiques. Une synchronisation immédiate est possible via le bouton « Synchroniser toutes les connexions » sur la liste des connexions.", None,
     "La synchronisation périodique est assurée par l'action automatique « ssomicrosoft » (Synchronisation des comptes depuis Entra ID), planifiable dans Configuration → Actions automatiques. Une synchronisation immédiate est possible via le bouton « Synchroniser toutes les connexions » sur la liste des connexions.",
     "Periodic synchronization is performed by the \"ssomicrosoft\" automatic action (Synchronize accounts from Entra ID), schedulable under Setup → Automatic actions. An immediate synchronization is available through the \"Synchronize all connections\" button on the connections list."),
    ("Le SSO utilise le flux délégué (OpenID Connect). Dans l'application Entra ID, ajoutez les permissions Déléguées « openid », « profile », « email » et « User.Read », et déclarez l'URL de redirection (ci-dessous) comme « Redirect URI » de type Web.", None,
     "Le SSO utilise le flux délégué (OpenID Connect). Dans l'application Entra ID, ajoutez les permissions Déléguées « openid », « profile », « email » et « User.Read », et déclarez l'URL de redirection (ci-dessous) comme « Redirect URI » de type Web.",
     "SSO uses the delegated flow (OpenID Connect). In the Entra ID application, add the Delegated permissions \"openid\", \"profile\", \"email\" and \"User.Read\", and declare the redirect URL (below) as a Web-type \"Redirect URI\"."),
    ('Ce compte est désactivé dans GLPI.', None,
     "Ce compte est désactivé dans GLPI.", "This account is disabled in GLPI."),
    ('Ce compte ne correspond pas au domaine autorisé pour cette connexion.', None,
     "Ce compte ne correspond pas au domaine autorisé pour cette connexion.",
     "This account does not match the domain allowed for this connection."),
    ('Client ID', None, "Client ID", "Client ID"),
    ('Client Secret', None, "Client Secret", "Client secret"),
    ('Connexion Entra ID', 'Connexions Entra ID',
     ("Connexion Entra ID", "Connexions Entra ID"),
     ("Entra ID connection", "Entra ID connections")),
    ('Connexion Entra ID', None, "Connexion Entra ID", "Entra ID connection"),
    ('Connexion SSO introuvable ou désactivée.', None,
     "Connexion SSO introuvable ou désactivée.", "SSO connection not found or disabled."),
    ('Connexion GLPI', None, "Connexion GLPI", "GLPI login"),
    ('Continuer', None, "Continuer", "Continue"),
    ('Créer les comptes manquants', None, "Créer les comptes manquants", "Create missing accounts"),
    ('Désactiver les comptes absents', None, "Désactiver les comptes absents", "Disable missing accounts"),
    ('Entité par défaut (nouveaux comptes)', None,
     "Entité par défaut (nouveaux comptes)", "Default entity (new accounts)"),
    ('Erreur renvoyée par Entra ID : %s', None,
     "Erreur renvoyée par Entra ID : %s", "Error returned by Entra ID: %s"),
    ('Erreur renvoyée par Microsoft : %s', None,
     "Erreur renvoyée par Microsoft : %s", "Error returned by Microsoft: %s"),
    ("Ex. : @contoso.com — seuls les comptes dont l'email se termine ainsi sont traités. Plusieurs domaines possibles, séparés par une virgule ou un point-virgule (ex. : @contoso.com, @fabrikam.com).", None,
     "Ex. : @contoso.com — seuls les comptes dont l'email se termine ainsi sont traités. Plusieurs domaines possibles, séparés par une virgule ou un point-virgule (ex. : @contoso.com, @fabrikam.com).",
     "E.g. @contoso.com — only accounts whose e-mail ends this way are processed. Several domains are allowed, separated by a comma or a semicolon (e.g. @contoso.com, @fabrikam.com)."),
    ('Filtre de domaine', None, "Filtre de domaine", "Domain filter"),
    ('Impossible de récupérer le profil utilisateur depuis Microsoft Graph.', None,
     "Impossible de récupérer le profil utilisateur depuis Microsoft Graph.",
     "Unable to retrieve the user profile from Microsoft Graph."),
    ('La connexion à GLPI a échoué (aucune habilitation valide ?).', None,
     "La connexion à GLPI a échoué (aucune habilitation valide ?).",
     "Logging in to GLPI failed (no valid authorization?)."),
    ('Laisser vide pour utiliser : %s — cette URL doit être déclarée comme "Redirect URI" (type Web) dans Entra ID.', None,
     'Laisser vide pour utiliser : %s — cette URL doit être déclarée comme "Redirect URI" (type Web) dans Entra ID.',
     'Leave empty to use: %s — this URL must be declared as a "Redirect URI" (Web type) in Entra ID.'),
    ('Profil par défaut (nouveaux comptes)', None,
     "Profil par défaut (nouveaux comptes)", "Default profile (new accounts)"),
    ('Requête SSO invalide (state).', None, "Requête SSO invalide (state).", "Invalid SSO request (state)."),
    ('Retour', None, "Retour", "Back"),
    ('SSO activé', None, "SSO activé", "SSO enabled"),
    ('Se connecter avec %s', None, "Se connecter avec %s", "Sign in with %s"),
    ('Supprimer les comptes absents', None, "Supprimer les comptes absents", "Delete missing accounts"),
    ('SSO Microsoft', None, "SSO Microsoft", "SSO Microsoft"),
    ('Synchronisation', None, "Synchronisation", "Synchronization"),
    ('Synchronisation des comptes depuis Entra ID', None,
     "Synchronisation des comptes depuis Entra ID", "Synchronize accounts from Entra ID"),
    ('Synchronisation complète terminée.', None,
     "Synchronisation complète terminée.", "Full synchronization completed."),
    ("Synchronisation de l'utilisateur terminée.", None,
     "Synchronisation de l'utilisateur terminée.", "User synchronization completed."),
    ('Synchronisation de la connexion terminée.', None,
     "Synchronisation de la connexion terminée.", "Connection synchronization completed."),
    ('Synchroniser toutes les connexions', None,
     "Synchroniser toutes les connexions", "Synchronize all connections"),
    ('Tenant ID', None, "Tenant ID", "Tenant ID"),
    ('The PHP "curl" extension is required by SSO Microsoft.', None,
     'L\'extension PHP "curl" est requise par SSO Microsoft.',
     'The PHP "curl" extension is required by SSO Microsoft.'),
    ('URL de redirection (Azure)', None, "URL de redirection (Azure)", "Redirect URL (Azure)"),
]

HEADER = (
    "Project-Id-Version: SSO Microsoft 2.1.0\n"
    "Report-Msgid-Bugs-To: \n"
    "MIME-Version: 1.0\n"
    "Content-Type: text/plain; charset=UTF-8\n"
    "Content-Transfer-Encoding: 8bit\n"
    "Plural-Forms: nplurals=2; plural=(n > 1);\n"
)


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def write_po(path, lang):  # lang: 'fr', 'en', or None for template
    lines = ['msgid ""', 'msgstr ""']
    for h in HEADER.rstrip('\n').split('\n'):
        lines.append('"%s\\n"' % po_escape(h))
    lines.append('')
    for msgid, plural, fr, en in ENTRIES:
        if lang == 'fr':
            tr = fr
        elif lang == 'en':
            tr = en
        else:
            tr = ('', '') if plural else ''
        lines.append('msgid "%s"' % po_escape(msgid))
        if plural:
            lines.append('msgid_plural "%s"' % po_escape(plural))
            lines.append('msgstr[0] "%s"' % po_escape(tr[0]))
            lines.append('msgstr[1] "%s"' % po_escape(tr[1]))
        else:
            lines.append('msgstr "%s"' % po_escape(tr))
        lines.append('')
    with open(path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))


def write_mo(path, lang):
    items = []  # (key_bytes, value_bytes)
    items.append((b'', HEADER.encode('utf-8')))
    for msgid, plural, fr, en in ENTRIES:
        tr = fr if lang == 'fr' else en
        if plural:
            key = msgid.encode('utf-8') + b'\x00' + plural.encode('utf-8')
            val = tr[0].encode('utf-8') + b'\x00' + tr[1].encode('utf-8')
        else:
            key = msgid.encode('utf-8')
            val = tr.encode('utf-8')
        items.append((key, val))

    items.sort(key=lambda kv: kv[0])
    n = len(items)
    offsets_o = 28
    offsets_t = offsets_o + n * 8
    start = offsets_t + n * 8
    keys_blob = b''
    vals_blob = b''
    key_table = []
    val_table = []
    for key, val in items:
        key_table.append((len(key), start + len(keys_blob)))
        keys_blob += key + b'\x00'
    voff = start + len(keys_blob)
    for key, val in items:
        val_table.append((len(val), voff + len(vals_blob)))
        vals_blob += val + b'\x00'

    out = struct.pack('<Iiiiiii', 0x950412de, 0, n, offsets_o, offsets_t, 0, 0)
    for length, off in key_table:
        out += struct.pack('<ii', length, off)
    for length, off in val_table:
        out += struct.pack('<ii', length, off)
    out += keys_blob + vals_blob
    with open(path, 'wb') as f:
        f.write(out)


write_po(os.path.join(HERE, 'ssomicrosoft.pot'), None)
write_po(os.path.join(HERE, 'fr_FR.po'), 'fr')
write_po(os.path.join(HERE, 'en_GB.po'), 'en')
write_mo(os.path.join(HERE, 'fr_FR.mo'), 'fr')
write_mo(os.path.join(HERE, 'en_GB.mo'), 'en')
print("Generated:", os.listdir(HERE))
