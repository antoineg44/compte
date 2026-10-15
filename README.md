# Déploiement depuis la page web

L'initialisation avec `sudo bash init/init.sh <domaine>` crée l'utilisateur
`deploy-compte`, son répertoire personnel et une autorisation sudo limitée au
script `/usr/local/sbin/deploy-compte`.

L'initialisation génère également `public/deploy.php` (en remplaçant son contenu)
avec le nom de l'utilisateur et le chemin du script adaptés au site.
La page appelle ce script avec
`sudo -n -H -u deploy-compte` : `-H` définit `HOME` sur le répertoire personnel
de l'utilisateur de déploiement, nécessaire à `git config --global`, et `-n`
fait échouer explicitement la commande si sudo exige un mot de passe.
Le nom `deploy-compte` suppose que le répertoire du dépôt s'appelle `compte`,
car l'initialisation déduit le nom de l'utilisateur du nom de ce répertoire.

Pour vérifier la commande avec les droits du serveur web :

```sh
sudo -u www-data sudo -n -H -u deploy-compte /usr/local/sbin/deploy-compte
```

Cette commande effectue réellement le déploiement (`git pull --ff-only`).

# Ajouter l'authentification sur d'autres sites

Procédure : ajouter l'authentification commune à un nouveau site

L'objectif est de pouvoir ajouter un nouveau sous-domaine, par exemple exemple.partitions.ovh, qui utilise la même authentification que compte.partitions.ovh et video.partitions.ovh.

Nous allons conserver ton architecture actuelle :

    compte.partitions.ovh : site central pour les comptes utilisateurs et l'authentification.
    video.partitions.ovh : site utilisant l'authentification commune.
    exemple.partitions.ovh : prochain site, qui utilisera le même système.
    MySQL compte : base de données commune contenant les utilisateurs, les services et les autorisations.
    SSO (Single Sign-On) : mécanisme permettant de se connecter une seule fois pour accéder aux différents sites.

La procédure ci-dessous est conçue pour ton serveur Ubuntu avec Apache, PHP et MySQL.

Attention : les commandes proposées s'appuient sur les fichiers que tu m'as montrés. Le mécanisme SSO n'est pas encore implémenté dans tes fichiers PHP actuels, même si la table sso_codes existe. Nous allons donc prévoir une intégration qui ne suppose pas que le SSO fonctionne déjà.
Étape 1 — Créer le sous-domaine
1.1. Configurer le DNS

Dans la gestion DNS de ton domaine partitions.ovh, ajoute un enregistrement :
Type	Nom	Valeur
A	exemple	Adresse IPv4 de ton VPS

Si tu utilises également IPv6, tu peux ajouter un enregistrement AAAA correspondant à l'adresse IPv6 de ton serveur.

Vérifie ensuite la résolution DNS :

dig +short exemple.partitions.ovh

L'adresse retournée doit correspondre à celle de ton serveur.
1.2. Créer les répertoires

Sur ton VPS :

sudo mkdir -p /var/www/exemple/public
sudo chown -R www-data:www-data /var/www/exemple
sudo chmod 755 /var/www/exemple
sudo chmod 755 /var/www/exemple/public

Le nouveau site utilisera :

/var/www/exemple/
└── public/
    ├── index.php
    ├── connexion.php
    └── deconnexion.php

Nous ajouterons d'autres fichiers si nécessaire.

Il n'est pas nécessaire de créer une nouvelle base de données pour les comptes utilisateurs.
Étape 2 — Créer le service dans MySQL

Tu disposes déjà de la table services, qui permet de gérer les autorisations d'accès à chaque site.

Nous allons enregistrer le nouveau service.

Exécute :

sudo mysql compte

Puis :

INSERT INTO services (nom, slug, description, actif)
VALUES (
    'Exemple',
    'exemple',
    'Accès au site Exemple',
    1
);

Vérifie que le service a été créé :

SELECT id, nom, slug, actif
FROM services
WHERE slug = 'exemple';

Puis quitte MySQL :

EXIT;

Accorder l'accès à un utilisateur

Pour autoriser l'utilisateur antoine à accéder au nouveau site :

sudo mysql compte

Puis :

INSERT INTO acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise,
    modifie_par
)
SELECT
    u.id,
    s.id,
    1,
    NULL
FROM utilisateurs u
CROSS JOIN services s
WHERE u.identifiant = 'antoine'
  AND s.slug = 'exemple'
ON DUPLICATE KEY UPDATE
    autorise = 1;

Cette requête crée ou actualise l'autorisation.

Pour la désactiver ultérieurement :

UPDATE acces_utilisateur_services a
JOIN utilisateurs u
    ON u.id = a.utilisateur_id
JOIN services s
    ON s.id = a.service_id
SET a.autorise = 0
WHERE u.identifiant = 'antoine'
  AND s.slug = 'exemple';

Ainsi, tu peux gérer indépendamment l'accès de chaque utilisateur à chaque site.
Étape 3 — Configurer Apache

Nous allons créer deux configurations Apache : une pour HTTP et une pour HTTPS.
3.1. Créer le VirtualHost HTTP

Ouvre :

sudo nano /etc/apache2/sites-available/exemple.partitions.ovh.conf

Ajoute :

<VirtualHost *:80>
    ServerName exemple.partitions.ovh

    DocumentRoot /var/www/exemple/public

    <Directory /var/www/exemple/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/exemple-error.log
    CustomLog ${APACHE_LOG_DIR}/exemple-access.log combined
</VirtualHost>

Enregistre le fichier.
3.2. Activer le site

sudo a2ensite exemple.partitions.ovh.conf

Vérifie la configuration :

sudo apache2ctl configtest

Si Apache répond :

Syntax OK

Recharge Apache :

sudo systemctl reload apache2

3.3. Activer HTTPS

Une fois le DNS correctement configuré, demande un certificat Let's Encrypt :

sudo certbot --apache -d exemple.partitions.ovh

Certbot peut créer automatiquement la configuration HTTPS.

Vérifie ensuite :

sudo apache2ctl -S

Tu dois retrouver exemple.partitions.ovh parmi les VirtualHosts.
Étape 4 — Réutiliser la base de données commune

Ton fichier de connexion MySQL est actuellement :

/var/www/compte/config/database.php

Il est déjà utilisé par le site vidéo.

Le nouveau site pourra donc le réutiliser :

require_once '/var/www/compte/config/database.php';

Cela évite de créer des comptes utilisateurs distincts pour chaque site.

Les informations d'identification restent centralisées dans la base compte.

Important : le fichier database.php contient le mot de passe MySQL. Il doit rester en dehors du répertoire public et ne doit jamais être accessible directement depuis le navigateur.
Étape 5 — Préparer le système SSO

C'est l'étape essentielle.

Tu possèdes déjà cette table :

compte.sso_codes

Elle contient notamment :

    code_hash : empreinte du code d'autorisation ;
    utilisateur_id : utilisateur concerné ;
    client_id : site destinataire ;
    code_challenge : élément de vérification PKCE ;
    date_expiration : date d'expiration ;
    date_utilisation : date à laquelle le code a été consommé.

Cette structure permet de mettre en place un échange de codes d'autorisation à usage unique.

Cependant, les recherches que tu as effectuées précédemment n'ont trouvé aucun code PHP utilisant cette table.

Il faut donc implémenter le protocole dans les fichiers PHP avant de pouvoir considérer le SSO comme opérationnel.
Architecture recommandée

Le fonctionnement attendu est le suivant :

    L'utilisateur ouvre exemple.partitions.ovh.
    Le site constate qu'aucune session locale n'existe.
    Il redirige le navigateur vers le service central de connexion de compte.partitions.ovh.
    Si l'utilisateur est déjà connecté au site central, il n'a pas besoin de saisir à nouveau son mot de passe.
    Le site central vérifie que la demande provient d'un client autorisé.
    Il émet un code d'autorisation temporaire.
    Le navigateur retourne vers une URL de rappel prédéfinie du nouveau site.
    Le nouveau site échange le code contre l'identité de l'utilisateur, en vérifiant notamment le mécanisme PKCE.
    Le site crée sa propre session PHP.
    Le site vérifie les autorisations de l'utilisateur pour le service exemple.

Le code d'autorisation doit être à usage unique et expirer rapidement.

Il ne faut surtout pas transmettre directement un identifiant utilisateur dans l'URL en considérant que cela suffit pour authentifier la personne.
Pourquoi ne pas copier simplement video/connexion.php ?

Ton fichier actuel /var/www/video/public/connexion.php vérifie directement l'identifiant et le mot de passe dans MySQL.

Il crée ensuite une session PHP locale.

Cette méthode permet une connexion indépendante au site vidéo, mais elle ne met pas en œuvre, à elle seule, le SSO.

Pour obtenir une authentification commune, il faut remplacer cette logique par une redirection vers le site central et un échange de code sécurisé.
Étape 6 — Créer les fichiers du nouveau site

Une fois le protocole SSO implémenté, voici les responsabilités des principaux fichiers.
Fichier	Rôle
connexion.php	Redirige vers le service central pour authentifier l'utilisateur.
index.php	Vérifie la session locale et les droits d'accès au service exemple.
deconnexion.php	Détruit la session locale du site.
callback.php	Reçoit le code d'autorisation et établit la session locale.
config.php	Contient les paramètres propres au client SSO.

Le fichier de configuration pourrait contenir :

<?php

declare(strict_types=1);

return [
    'client_id' => 'exemple',
    'redirect_uri' => 'https://exemple.partitions.ovh/callback.php',
    'service_slug' => 'exemple',
];

Ce fichier ne contient aucun secret. Les éventuels secrets nécessaires au protocole doivent être conservés hors du répertoire public.

Le serveur central devra reconnaître le client exemple et autoriser exclusivement son URL de rappel enregistrée.

Ne mets pas en production ces fichiers tant que le protocole d'autorisation correspondant n'existe pas côté central.
Étape 7 — Vérifier les autorisations sur chaque page

Ton fichier existant :

/var/www/compte/config/services.php

contient déjà les fonctions :

utilisateurAAccesService()

et :

exigerAccesService()

Nous pouvons les réutiliser.

Par exemple, sur une page protégée :

<?php

declare(strict_types=1);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

exigerAccesService($pdo, 'exemple');

Cette vérification garantit que l'utilisateur possède une autorisation explicite pour le service exemple, et que ce service est actif.

Attention : ce fragment illustre uniquement le contrôle d'accès. Il ne remplace pas la mise en place d'une session sécurisée ni le traitement du retour SSO.

Dans ton application, il faudra également conserver les protections CSRF, les paramètres de session sécurisés et les vérifications de session nécessaires.
Étape 8 — Vérifier que tout fonctionne

Une fois l'intégration terminée, effectue les tests suivants.
Test 1 : connexion commune

    Ouvre https://compte.partitions.ovh.
    Connecte-toi.
    Ouvre https://exemple.partitions.ovh.
    Vérifie que tu arrives sur le nouveau site sans saisir une seconde fois ton mot de passe.

Test 2 : utilisateur non autorisé

Désactive temporairement l'accès au service :

UPDATE acces_utilisateur_services a
JOIN utilisateurs u
    ON u.id = a.utilisateur_id
JOIN services s
    ON s.id = a.service_id
SET a.autorise = 0
WHERE u.identifiant = 'antoine'
  AND s.slug = 'exemple';

L'utilisateur doit être authentifié, mais l'accès au service doit être refusé.
Test 3 : service désactivé

UPDATE services
SET actif = 0
WHERE slug = 'exemple';

Même si l'utilisateur possède une autorisation, il ne doit plus pouvoir accéder au service.

Réactive-le ensuite :

UPDATE services
SET actif = 1
WHERE slug = 'exemple';

Test 4 : déconnexion

Vérifie que la déconnexion détruit correctement la session locale.

Il faut décider séparément si la déconnexion d'un site doit également déconnecter l'utilisateur de tous les autres sites.
Étape 9 — La procédure à répéter pour chaque nouveau site

Une fois le SSO central correctement implémenté, l'ajout d'un nouveau site pourra suivre cette checklist :

    Créer l'enregistrement DNS du sous-domaine.
    Créer /var/www/NOUVEAU_SITE/public.
    Configurer le VirtualHost Apache.
    Activer HTTPS.
    Ajouter le service dans compte.services.
    Enregistrer le client SSO côté serveur central, avec son URL de rappel exacte.
    Créer la configuration propre au nouveau client.
    Réutiliser la base de données commune.
    Ajouter les fichiers de connexion, de rappel, d'accueil et de déconnexion.
    Vérifier les autorisations via services.php.
    Tester la connexion commune, les refus d'accès et la déconnexion.

Le nom du service, son identifiant SSO et son slug doivent rester cohérents.

Par exemple :
Élément	Valeur
Domaine	exemple.partitions.ovh
client_id	exemple
service_slug	exemple
URL de rappel	https://exemple.partitions.ovh/callback.php
Recommandation pour ton architecture

À terme, je te conseille de centraliser le protocole SSO dans un ensemble de fichiers dédié sous :

/var/www/compte/
├── config/
│   ├── database.php
│   ├── services.php
│   └── sso.php
└── public/
    ├── connexion.php
    ├── espace.php
    └── sso/
        ├── authorize.php
        └── token.php

Cette organisation est indicative : elle ne signifie pas que les endpoints SSO existent déjà.

Chaque nouveau site n'aurait alors plus besoin de gérer lui-même les mots de passe. Il se contenterait de rediriger l'utilisateur vers le serveur central, de recevoir le retour d'authentification et de vérifier ses propres droits.

Le point à traiter en priorité est donc l'implémentation effective du SSO dans compte.partitions.ovh. La table sso_codes existe déjà, mais elle ne suffit pas à elle seule : il faut encore que les endpoints centraux et le client vidéo utilisent réellement ce mécanisme. Une fois cette partie terminée et testée, l'ajout de exemple.partitions.ovh deviendra une opération répétable et beaucoup plus simple.


# Mettre en place un véritable SSO en PHP pour tes sites partitions.ovh

Nous allons mettre en place une authentification centralisée entre :

    https://compte.partitions.ovh : serveur central d'authentification.
    https://video.partitions.ovh : premier site client.
    https://exemple.partitions.ovh : site que tu pourras ajouter ensuite.
    MySQL compte : base commune contenant les utilisateurs, les services et les autorisations.

L'objectif est de pouvoir se connecter une seule fois sur compte.partitions.ovh, puis d'accéder aux autres sites sans saisir de nouveau son mot de passe.

Point important : d'après les fichiers que tu m'as transmis, video.partitions.ovh authentifie actuellement les utilisateurs directement dans MySQL. La table sso_codes existe, mais aucun des fichiers PHP examinés ne l'utilise encore. Nous allons donc mettre en place le mécanisme SSO au lieu de supposer qu'il existe déjà.

Je te propose une architecture fondée sur un code d'autorisation à usage unique, avec PKCE, des URL de rappel enregistrées côté serveur et des sessions PHP indépendantes pour chaque site.
1. Comprendre le fonctionnement

Voici le parcours d'un utilisateur qui a déjà ouvert une session sur le site central.

Navigateur
    |
    | 1. Visite video.partitions.ovh
    v
Serveur vidéo
    |
    | 2. Aucune session locale
    |    Redirection vers compte.partitions.ovh
    v
Serveur central
    |
    | 3. Vérifie la session centrale
    |    L'utilisateur est déjà connecté
    |
    | 4. Vérifie que le client vidéo est autorisé
    | 5. Vérifie l'autorisation du service "video"
    | 6. Génère un code temporaire
    v
Navigateur
    |
    | 7. Retour vers video.partitions.ovh/callback.php
    |    avec le code temporaire
    v
Serveur vidéo
    |
    | 8. Échange le code contre l'identité
    |    en présentant sa preuve PKCE
    v
Serveur central
    |
    | 9. Vérifie et consomme le code
    | 10. Renvoie l'identité autorisée
    v
Serveur vidéo
    |
    | 11. Crée une session PHP locale
    | 12. Vérifie les droits du service
    v
Utilisateur connecté

Le mot de passe n'est jamais transmis entre les sites.

Le navigateur transporte uniquement un code temporaire. Le serveur central vérifie que ce code correspond au client et à la preuve PKCE attendus avant de délivrer l'identité.

Attention à une distinction importante : le SSO permet de réutiliser la connexion centrale, mais chaque site doit toujours vérifier ses propres autorisations. Un utilisateur connecté n'est pas automatiquement autorisé à utiliser tous les services.
2. Vérifier les éléments déjà présents

Tu as déjà les éléments suivants :

    /var/www/compte/config/database.php
    /var/www/compte/config/services.php
    /var/www/compte/public/connexion.php
    /var/www/video/public/connexion.php
    /var/www/video/public/index.php
    La table compte.sso_codes
    La table compte.services
    La table compte.acces_utilisateur_services

Nous allons réutiliser ces éléments.
2.1. Vérifier les colonnes de la table SSO

Exécute :

sudo mysql -e "SHOW CREATE TABLE compte.sso_codes\G"

La structure que tu m'as montrée est adaptée à notre mécanisme :
Colonne	Fonction
code_hash	Empreinte SHA-256 du code d'autorisation
utilisateur_id	Identifiant du compte authentifié
client_id	Site destinataire
code_challenge	Empreinte PKCE attendue
date_expiration	Expiration du code
date_utilisation	Empêche la réutilisation du code
date_creation	Date de création

Nous conserverons cette structure.
2.2. Vérifier les droits sur les services

Exécute :

sudo mysql -e "
SELECT
    u.identifiant,
    s.nom,
    s.slug,
    s.actif,
    a.autorise
FROM compte.utilisateurs u
CROSS JOIN compte.services s
LEFT JOIN compte.acces_utilisateur_services a
    ON a.utilisateur_id = u.id
    AND a.service_id = s.id
ORDER BY u.identifiant, s.nom;
"

Une absence de ligne dans acces_utilisateur_services signifie qu'aucune autorisation explicite n'a été accordée.

Dans notre implémentation, l'absence d'autorisation entraîne un refus d'accès.
3. Enregistrer les clients autorisés

Le serveur central ne doit jamais accepter une URL de rappel arbitraire. Sinon, un attaquant pourrait essayer de détourner le retour d'authentification vers un autre site.

Nous allons donc créer une liste blanche des clients SSO.
3.1. Créer le fichier de configuration

Exécute :

sudo nano /var/www/compte/config/sso_clients.php

Ajoute :

<?php

declare(strict_types=1);

/**
 * Liste blanche des clients SSO.
 *
 * Toute nouvelle URL de rappel doit être déclarée ici.
 * Ne jamais construire une URL de rappel à partir
 * d'une valeur fournie librement par le navigateur.
 */

return [
    'video' => [
        'redirect_uri' => 'https://video.partitions.ovh/callback.php',
        'service_slug' => 'video',
    ],

    'exemple' => [
        'redirect_uri' => 'https://exemple.partitions.ovh/callback.php',
        'service_slug' => 'exemple',
    ],
];

Enregistre le fichier.

Protège-le :

sudo chown root:www-data /var/www/compte/config/sso_clients.php
sudo chmod 640 /var/www/compte/config/sso_clients.php

Ce fichier définit les clients autorisés à utiliser l'authentification centrale.

Pour ajouter un futur site, tu ajouteras simplement une entrée avec son client_id, son URL de rappel exacte et son service_slug.

Le client_id n'est pas un mot de passe : il identifie le site.
4. Créer le point d'entrée d'autorisation du serveur central

Ce fichier reçoit les demandes de connexion provenant des sites clients.

Il devra :

    vérifier que le client est enregistré ;
    vérifier que l'URL de rappel correspond exactement à celle déclarée ;
    vérifier que l'utilisateur possède une session centrale ;
    vérifier que l'utilisateur est autorisé à accéder au service ;
    générer un code aléatoire temporaire ;
    enregistrer uniquement l'empreinte du code ;
    rediriger vers le site client.

4.1. Créer le répertoire SSO

sudo mkdir -p /var/www/compte/public/sso

4.2. Créer authorize.php

sudo nano /var/www/compte/public/sso/authorize.php

Colle le code suivant :

<?php

declare(strict_types=1);

/*
 * Serveur central d'autorisation SSO.
 *
 * URL :
 * https://compte.partitions.ovh/sso/authorize.php
 */

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/services.php';

$clients = require dirname(__DIR__, 2)
    . '/config/sso_clients.php';

/**
 * Réponse d'erreur sans révéler de détails internes.
 */
function erreurSso(string $message, int $statut = 400): never
{
    http_response_code($statut);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');

    exit($message);
}

/*
 * 1. Lire les paramètres de la demande.
 */

$clientId = (string) ($_GET['client_id'] ?? '');
$redirectUri = (string) ($_GET['redirect_uri'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$codeChallenge = (string) ($_GET['code_challenge'] ?? '');
$codeChallengeMethod = (string) (
    $_GET['code_challenge_method'] ?? ''
);

/*
 * 2. Vérifier le client.
 */

if (!isset($clients[$clientId])) {
    erreurSso('Client SSO inconnu.');
}

$client = $clients[$clientId];

/*
 * 3. Vérifier strictement l'URL de rappel.
 */

if (
    !is_string($redirectUri)
    || !hash_equals(
        $client['redirect_uri'],
        $redirectUri
    )
) {
    erreurSso('URL de rappel non autorisée.');
}

/*
 * 4. Vérifier les paramètres PKCE et state.
 *
 * Un challenge S256 Base64URL SHA-256 contient 43 caractères
 * sans caractère de remplissage "=".
 */

if (
    !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $state)
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $codeChallenge)
    || $codeChallengeMethod !== 'S256'
) {
    erreurSso('Paramètres SSO invalides.');
}

/*
 * 5. Vérifier la session centrale.
 */

if (!isset($_SESSION['utilisateur_id'])) {
    /*
     * Conserver la demande pendant la connexion.
     *
     * Les paramètres seront validés de nouveau après
     * l'authentification. Aucun code SSO n'est émis ici.
     */

    $_SESSION['sso_demande_en_attente'] = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'expiration' => time() + 600,
    ];

    /*
     * La page de connexion existante redirige actuellement
     * vers espace.php après authentification.
     *
     * Nous utiliserons un paramètre interne dédié pour
     * revenir à la demande SSO après la connexion.
     */

    header('Location: /connexion.php?sso=1');
    exit;
}

/*
 * 6. Vérifier que le compte existe toujours.
 *
 * La session ne suffit pas : on recharge l'identité depuis
 * la base de données centrale.
 */

try {
    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute([
        'id' => (int) $_SESSION['utilisateur_id'],
    ]);

    $utilisateur = $requete->fetch();

} catch (Throwable $e) {
    error_log('Erreur SSO authorize : ' . $e->getMessage());

    erreurSso(
        'Le service d’authentification est temporairement indisponible.',
        500
    );
}

if (!$utilisateur) {
    erreurSso('Session utilisateur invalide.', 401);
}

/*
 * 7. Vérifier les droits d'accès au service demandé.
 *
 * Le fait d'être connecté ne donne pas automatiquement
 * accès à tous les sites.
 */

try {
    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $utilisateur['id'],
        $client['service_slug']
    );

} catch (Throwable $e) {
    error_log('Erreur SSO services : ' . $e->getMessage());

    erreurSso(
        'Impossible de vérifier les autorisations.',
        500
    );
}

if (!$autorise) {
    erreurSso(
        'Accès refusé : votre compte ne dispose pas des droits nécessaires.',
        403
    );
}

/*
 * 8. Générer un code d'autorisation aléatoire.
 *
 * Le code en clair ne sera jamais enregistré en base.
 */

try {
    $code = rtrim(
        strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ),
        '='
    );

    $codeHash = hash('sha256', $code);

    $requete = $pdo->prepare(
        'INSERT INTO sso_codes (
            code_hash,
            utilisateur_id,
            client_id,
            code_challenge,
            date_expiration,
            date_utilisation
        ) VALUES (
            :code_hash,
            :utilisateur_id,
            :client_id,
            :code_challenge,
            DATE_ADD(NOW(), INTERVAL 2 MINUTE),
            NULL
        )'
    );

    $requete->execute([
        'code_hash' => $codeHash,
        'utilisateur_id' => (int) $utilisateur['id'],
        'client_id' => $clientId,
        'code_challenge' => $codeChallenge,
    ]);

} catch (Throwable $e) {
    error_log('Erreur émission code SSO : ' . $e->getMessage());

    erreurSso(
        'Impossible de démarrer la connexion.',
        500
    );
}

/*
 * 9. Rediriger vers l'URL enregistrée.
 *
 * Ne pas utiliser directement l'URL reçue sans validation.
 */

$parametres = http_build_query(
    [
        'code' => $code,
        'state' => $state,
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);

header('Cache-Control: no-store');
header('Pragma: no-cache');

header(
    'Location: '
    . $client['redirect_uri']
    . '?'
    . $parametres
);

exit;

Enregistre le fichier.
4.3. Pourquoi ce code est important

Le serveur central ne transmet pas le mot de passe au site client.

Il émet un code aléatoire valable deux minutes, stocké sous forme d'empreinte SHA-256.

Le code est associé :

    à l'utilisateur authentifié ;
    au client SSO ;
    à la preuve PKCE ;
    à une date d'expiration.

Le code sera consommé une seule fois par le prochain endpoint que nous allons créer.

Il reste toutefois une partie à ajouter : la reprise de la demande SSO après la connexion. Nous la traiterons plus loin, car ton fichier connexion.php possède déjà son propre flux d'authentification et ses protections contre les tentatives répétées.
5. Créer le point d'échange des codes

Nous allons maintenant créer l'endpoint qui échange un code d'autorisation contre l'identité de l'utilisateur.

Il sera appelé par le serveur PHP du site client, pas directement par une page JavaScript.
5.1. Créer token.php

sudo nano /var/www/compte/public/sso/token.php

Colle :

<?php

declare(strict_types=1);

/*
 * Échange un code d'autorisation contre une identité.
 *
 * Appel serveur-à-serveur uniquement, via HTTPS.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/services.php';

$clients = require dirname(__DIR__, 2)
    . '/config/sso_clients.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function reponseJson(array $donnees, int $statut = 200): never
{
    http_response_code($statut);

    echo json_encode(
        $donnees,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    exit;
}

/*
 * Accepter uniquement POST.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    reponseJson([
        'error' => 'method_not_allowed',
    ], 405);
}

/*
 * Limiter la taille des données reçues.
 */

if (
    isset($_SERVER['CONTENT_LENGTH'])
    && (int) $_SERVER['CONTENT_LENGTH'] > 4096
) {
    reponseJson([
        'error' => 'request_too_large',
    ], 413);
}

$clientId = (string) ($_POST['client_id'] ?? '');
$redirectUri = (string) ($_POST['redirect_uri'] ?? '');
$code = (string) ($_POST['code'] ?? '');
$verifier = (string) ($_POST['code_verifier'] ?? '');

/*
 * Vérifier le client et son URL de rappel enregistrée.
 */

if (
    !isset($clients[$clientId])
    || !hash_equals(
        $clients[$clientId]['redirect_uri'],
        $redirectUri
    )
) {
    reponseJson([
        'error' => 'invalid_client',
    ], 400);
}

/*
 * Le code émis par authorize.php contient 32 octets
 * aléatoires encodés en Base64URL sans "=".
 *
 * La longueur attendue est donc de 43 caractères.
 */

if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)) {
    reponseJson([
        'error' => 'invalid_grant',
    ], 400);
}

/*
 * Un verifier PKCE valide contient entre 43 et 128
 * caractères alphanumériques ou - . _ ~.
 */

if (!preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier)) {
    reponseJson([
        'error' => 'invalid_grant',
    ], 400);
}

/*
 * Calculer le challenge S256 attendu.
 */

$challengeAttendu = rtrim(
    strtr(
        base64_encode(hash('sha256', $verifier, true)),
        '+/',
        '-_'
    ),
    '='
);

$codeHash = hash('sha256', $code);

try {
    /*
     * Transaction pour empêcher deux échanges simultanés
     * du même code.
     */

    $pdo->beginTransaction();

    $requete = $pdo->prepare(
        'SELECT
            utilisateur_id,
            client_id,
            code_challenge,
            date_expiration,
            date_utilisation
         FROM sso_codes
         WHERE code_hash = :code_hash
         LIMIT 1
         FOR UPDATE'
    );

    $requete->execute([
        'code_hash' => $codeHash,
    ]);

    $autorisation = $requete->fetch();

    if (
        !$autorisation
        || $autorisation['date_utilisation'] !== null
        || $autorisation['client_id'] !== $clientId
        || strtotime($autorisation['date_expiration']) <= time()
        || !hash_equals(
            $autorisation['code_challenge'],
            $challengeAttendu
        )
    ) {
        $pdo->rollBack();

        reponseJson([
            'error' => 'invalid_grant',
        ], 400);
    }

    /*
     * Consommer le code avant de renvoyer l'identité.
     */

    $requete = $pdo->prepare(
        'UPDATE sso_codes
         SET date_utilisation = NOW()
         WHERE code_hash = :code_hash
           AND date_utilisation IS NULL'
    );

    $requete->execute([
        'code_hash' => $codeHash,
    ]);

    if ($requete->rowCount() !== 1) {
        $pdo->rollBack();

        reponseJson([
            'error' => 'invalid_grant',
        ], 400);
    }

    /*
     * Recharger l'identité depuis MySQL.
     */

    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute([
        'id' => (int) $autorisation['utilisateur_id'],
    ]);

    $utilisateur = $requete->fetch();

    if (!$utilisateur) {
        $pdo->rollBack();

        reponseJson([
            'error' => 'invalid_grant',
        ], 400);
    }

    /*
     * Revérifier l'autorisation au moment de l'échange.
     *
     * Cela évite qu'un droit retiré entre l'émission
     * et l'échange du code permette une connexion.
     */

    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $utilisateur['id'],
        $clients[$clientId]['service_slug']
    );

    if (!$autorise) {
        $pdo->rollBack();

        reponseJson([
            'error' => 'access_denied',
        ], 403);
    }

    $pdo->commit();

    reponseJson([
        'sub' => (string) $utilisateur['id'],
        'identifiant' => $utilisateur['identifiant'],
        'email' => $utilisateur['email'],
        'role' => $utilisateur['role'],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur échange SSO : ' . $e->getMessage());

    reponseJson([
        'error' => 'server_error',
    ], 500);
}

Enregistre le fichier.
5.2. Ce que fait token.php

L'endpoint :

    reçoit le code et le vérificateur PKCE ;
    calcule l'empreinte attendue du vérificateur ;
    recherche le code en base ;
    vérifie son client, son expiration et son utilisation ;
    compare la preuve PKCE ;
    marque le code comme utilisé dans une transaction ;
    recharge l'utilisateur ;
    vérifie à nouveau son autorisation ;
    renvoie l'identité au serveur client.

La transaction et le verrou FOR UPDATE sont importants : ils évitent que deux requêtes simultanées échangent avec succès le même code.

Le point d'échange ne délivre pas un jeton d'accès permanent. Il renvoie uniquement les informations nécessaires pour créer la session locale du site client.

Attention : cette réponse JSON doit rester un échange serveur-à-serveur via HTTPS. Le client PHP doit vérifier le certificat TLS et ne doit pas transmettre les identifiants MySQL au navigateur.
6. Modifier la connexion centrale pour reprendre la demande SSO

Il faut maintenant résoudre un point important : si l'utilisateur n'est pas connecté à compte.partitions.ovh, le serveur central doit lui permettre de se connecter, puis reprendre automatiquement la demande SSO initiale.

Ton fichier actuel :

/var/www/compte/public/connexion.php

enregistre déjà les informations de session après une authentification réussie.

Nous allons conserver son mécanisme de vérification du mot de passe, ses protections contre les tentatives de connexion et son journal d'activité.
6.1. Repérer la redirection après connexion

Dans ton fichier, tu as actuellement une redirection similaire à celle-ci après une connexion réussie :

header('Location: espace.php');
exit;

Nous allons remplacer cette redirection par un traitement qui reprend la demande SSO lorsqu'elle existe.

Ne remplace pas tout ton fichier connexion.php : il contient déjà plusieurs protections de sécurité qu'il faut conserver.
6.2. Ajouter le traitement de reprise

Dans /var/www/compte/public/connexion.php, ajoute cette fonction après session_start() et avant le traitement du formulaire :

/**
 * Reprend une demande SSO après authentification.
 *
 * Les paramètres proviennent exclusivement de la session.
 */
function destinationApresConnexion(): string
{
    $demande = $_SESSION['sso_demande_en_attente'] ?? null;

    if (!is_array($demande)) {
        return 'espace.php';
    }

    if (
        !isset(
            $demande['client_id'],
            $demande['redirect_uri'],
            $demande['state'],
            $demande['code_challenge'],
            $demande['expiration']
        )
        || !is_int($demande['expiration'])
        || $demande['expiration'] < time()
    ) {
        unset($_SESSION['sso_demande_en_attente']);

        return 'espace.php';
    }

    /*
     * Les paramètres sont réutilisés par authorize.php,
     * qui vérifiera à nouveau le client et l'URL de rappel.
     */
    $url = 'sso/authorize.php?' . http_build_query(
        [
            'client_id' => $demande['client_id'],
            'redirect_uri' => $demande['redirect_uri'],
            'state' => $demande['state'],
            'code_challenge' => $demande['code_challenge'],
            'code_challenge_method' => 'S256',
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    unset($_SESSION['sso_demande_en_attente']);

    return $url;
}

6.3. Remplacer la redirection après authentification

Dans le bloc de connexion réussie, conserve toutes les opérations existantes :

    enregistrement de la tentative réussie ;
    renouvellement de l'identifiant de session ;
    enregistrement de l'identifiant utilisateur ;
    enregistrement du rôle ;
    renouvellement du jeton CSRF ;
    journalisation de la connexion.

À la place de :

header('Location: espace.php');
exit;

utilise :

$destination = destinationApresConnexion();

header('Location: ' . $destination);
exit;

Cette fonction ne construit pas une URL externe : elle retourne soit espace.php, soit un chemin interne vers sso/authorize.php.

Le serveur central revalidera ensuite les paramètres.
6.4. Vérifier le comportement

Le parcours sera désormais le suivant :

    Le site vidéo redirige vers le serveur central.
    Le serveur central constate l'absence de session.
    Il conserve la demande dans la session PHP.
    Il redirige vers connexion.php.
    L'utilisateur saisit son identifiant et son mot de passe.
    Le code existant vérifie les identifiants.
    Le serveur central reprend la demande SSO.
    Il émet le code d'autorisation si les droits sont valides.

Il n'est pas nécessaire de modifier le formulaire de connexion pour ajouter un champ spécifique au SSO.
7. Créer le client SSO du site vidéo

Nous allons maintenant remplacer l'authentification indépendante de video.partitions.ovh par une redirection vers le serveur central.

Le client utilisera deux fichiers :

    connexion.php : prépare la demande SSO et redirige vers le serveur central.
    callback.php : reçoit le code, l'échange côté serveur et crée la session locale.

7.1. Remplacer video/connexion.php

Avant de modifier le fichier, fais une sauvegarde :

sudo cp \
    /var/www/video/public/connexion.php \
    /var/www/video/public/connexion.php.bak

Ouvre ensuite :

sudo nano /var/www/video/public/connexion.php

Remplace son contenu par :

<?php

declare(strict_types=1);

/*
 * Client SSO de video.partitions.ovh.
 *
 * Ce fichier ne vérifie aucun mot de passe.
 * L'authentification est déléguée au serveur central.
 */

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('Cache-Control: no-store');

if (isset($_SESSION['utilisateur_id'])) {
    header('Location: index.php');
    exit;
}

/*
 * Paramètres fixes du client.
 */

$clientId = 'video';

$redirectUri =
    'https://video.partitions.ovh/callback.php';

$authorizeUrl =
    'https://compte.partitions.ovh/sso/authorize.php';

/*
 * Générer un état anti-CSRF et un verifier PKCE.
 */

try {
    $state = rtrim(
        strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ),
        '='
    );

    $verifier = rtrim(
        strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ),
        '='
    );

    $challenge = rtrim(
        strtr(
            base64_encode(
                hash('sha256', $verifier, true)
            ),
            '+/',
            '-_'
        ),
        '='
    );

} catch (Throwable $e) {
    error_log('Erreur de génération SSO vidéo.');

    http_response_code(500);

    exit('Impossible de démarrer la connexion.');
}

/*
 * Enregistrer les valeurs dans la session locale.
 *
 * Le verifier ne doit jamais apparaître dans l'URL.
 */

$_SESSION['sso_pending'] = [
    'state' => $state,
    'verifier' => $verifier,
    'created_at' => time(),
];

/*
 * Construire la demande d'autorisation.
 */

$parametres = http_build_query(
    [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);

/*
 * Rediriger vers le serveur central.
 */

header(
    'Location: '
    . $authorizeUrl
    . '?'
    . $parametres
);

exit;

Enregistre le fichier.
7.2. Comment fonctionne le PKCE ?

Le client crée une valeur aléatoire appelée verifier.

Il calcule ensuite :

challenge = Base64URL(SHA256(verifier))

Le serveur central conserve uniquement le challenge.

Lors de l'échange du code, le client doit présenter le verifier original. Le serveur central calcule à nouveau son empreinte et vérifie qu'elle correspond à celle enregistrée.

Le verifier est conservé dans la session PHP locale du client, jamais dans l'URL.

Cela empêche un tiers qui récupérerait seulement le code d'autorisation de l'utiliser sans disposer également du verifier.
8. Créer video/callback.php

Ce fichier reçoit la redirection du serveur central.

Il vérifie l'état anti-CSRF, échange le code par une requête serveur-à-serveur, puis ouvre une session locale.
8.1. Créer le fichier

sudo nano /var/www/video/public/callback.php

Colle :

<?php

declare(strict_types=1);

/*
 * Callback SSO de video.partitions.ovh.
 */

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('Cache-Control: no-store');
header('Pragma: no-cache');

function erreurCallback(string $message, int $statut = 400): never
{
    http_response_code($statut);
    header('Content-Type: text/plain; charset=UTF-8');

    exit($message);
}

/*
 * 1. Récupérer le code et l'état.
 */

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

$demande = $_SESSION['sso_pending'] ?? null;

if (!is_array($demande)) {
    erreurCallback(
        'Aucune demande de connexion en attente. Veuillez recommencer.',
        400
    );
}

/*
 * 2. Vérifier l'état anti-CSRF.
 */

$stateAttendu = $demande['state'] ?? null;

if (
    !is_string($stateAttendu)
    || $state === ''
    || !hash_equals($stateAttendu, $state)
) {
    unset($_SESSION['sso_pending']);

    erreurCallback('État SSO invalide.', 400);
}

/*
 * 3. Vérifier l'expiration de la demande locale.
 */

$createdAt = $demande['created_at'] ?? null;

if (
    !is_int($createdAt)
    || $createdAt > time()
    || time() - $createdAt > 600
) {
    unset($_SESSION['sso_pending']);

    erreurCallback(
        'La demande de connexion a expiré. Veuillez recommencer.',
        400
    );
}

$verifier = $demande['verifier'] ?? null;

if (
    !is_string($verifier)
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $verifier)
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)
) {
    unset($_SESSION['sso_pending']);

    erreurCallback('Code SSO invalide.', 400);
}

/*
 * 4. Consommer la demande locale.
 *
 * Une nouvelle tentative devra démarrer un nouveau flux SSO.
 */

unset($_SESSION['sso_pending']);

/*
 * 5. Échanger le code avec le serveur central.
 *
 * La requête part directement du serveur PHP vidéo.
 */

if (!function_exists('curl_init')) {
    error_log('Extension PHP cURL indisponible sur le site vidéo.');

    erreurCallback(
        'Le service de connexion est temporairement indisponible.',
        500
    );
}

$donnees = [
    'client_id' => 'video',
    'redirect_uri' => 'https://video.partitions.ovh/callback.php',
    'code' => $code,
    'code_verifier' => $verifier,
];

$curl = curl_init(
    'https://compte.partitions.ovh/sso/token.php'
);

curl_setopt_array($curl, [
    CURLOPT_POST => true,

    CURLOPT_POSTFIELDS => http_build_query(
        $donnees,
        '',
        '&',
        PHP_QUERY_RFC3986
    ),

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,

    // Ne jamais désactiver la vérification TLS.
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,

    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);

$reponse = curl_exec($curl);

$erreurCurl = curl_error($curl);
$statutHttp = (int) curl_getinfo(
    $curl,
    CURLINFO_HTTP_CODE
);

curl_close($curl);

if (
    $reponse === false
    || $statutHttp !== 200
) {
    error_log(
        'Échec échange SSO vidéo. HTTP='
        . $statutHttp
        . '; cURL='
        . $erreurCurl
    );

    erreurCallback(
        'La connexion a échoué. Veuillez recommencer.',
        502
    );
}

/*
 * 6. Vérifier la réponse JSON.
 */

try {
    $identite = json_decode(
        $reponse,
        true,
        16,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    error_log('Réponse SSO vidéo invalide.');

    erreurCallback(
        'Réponse d’authentification invalide.',
        502
    );
}

if (
    !is_array($identite)
    || !isset(
        $identite['sub'],
        $identite['identifiant'],
        $identite['email'],
        $identite['role']
    )
    || !ctype_digit((string) $identite['sub'])
    || !is_string($identite['identifiant'])
    || !is_string($identite['email'])
    || !in_array(
        $identite['role'],
        ['user', 'moderator', 'admin'],
        true
    )
) {
    erreurCallback(
        'Identité reçue invalide.',
        502
    );
}

/*
 * 7. Renouveler l'identifiant de session.
 */

if (!session_regenerate_id(true)) {
    error_log('Impossible de renouveler la session vidéo.');

    erreurCallback(
        'Impossible de créer votre session.',
        500
    );
}

/*
 * 8. Créer la session locale.
 */

$_SESSION['utilisateur_id'] =
    (int) $identite['sub'];

$_SESSION['utilisateur_identifiant'] =
    $identite['identifiant'];

$_SESSION['utilisateur_email'] =
    $identite['email'];

$_SESSION['utilisateur_role'] =
    $identite['role'];

$_SESSION['utilisateur_admin'] =
    ($identite['role'] === 'admin');

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/*
 * 9. Retourner sur le site.
 *
 * L'index vérifiera l'autorisation d'accès au service.
 */

header('Location: index.php');
exit;

Enregistre le fichier.
8.2. Vérifier la configuration PHP

Ton serveur dispose déjà de l'extension cURL, d'après les commandes que tu as exécutées.

Vérifie également que PHP est en mesure de communiquer avec le domaine central :

curl -I https://compte.partitions.ovh

La commande doit atteindre le serveur central avec un certificat TLS valide.
9. Vérifier l'autorisation sur le site vidéo

L'authentification et l'autorisation sont deux choses différentes.

Même si le serveur central a confirmé l'identité de l'utilisateur, le site vidéo doit toujours vérifier que le compte possède le droit video.

Ton fichier :

/var/www/compte/config/services.php

contient déjà la fonction :

utilisateurAAccesService($pdo, $utilisateurId, 'video')

Tu peux donc conserver le contrôle existant de /var/www/video/public/index.php.

Pour une page protégée, la vérification peut être réalisée ainsi :

<?php

declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

exigerAccesService($pdo, 'video');

// La page protégée peut maintenant continuer.

Cette vérification doit être effectuée avant tout affichage HTML.

Pour une application avec plusieurs pages vidéo, protège chaque point d'entrée, ainsi que les éventuelles API, téléchargements et traitements AJAX. Protéger uniquement index.php ne suffit pas si d'autres fichiers permettent d'accéder aux ressources.
10. Ajouter le site exemple.partitions.ovh

Une fois le client vidéo opérationnel, le deuxième site sera beaucoup plus simple à configurer.

Nous avons déjà enregistré ce client dans :

/var/www/compte/config/sso_clients.php

avec :

'exemple' => [
    'redirect_uri' => 'https://exemple.partitions.ovh/callback.php',
    'service_slug' => 'exemple',
],

Le serveur central sait donc déjà quel service vérifier et où renvoyer le navigateur.
10.1. Créer la connexion SSO

Crée :

sudo nano /var/www/exemple/public/connexion.php

Le fichier est presque identique à celui de la vidéo. Il faut modifier les trois paramètres suivants :

$clientId = 'exemple';

$redirectUri =
    'https://exemple.partitions.ovh/callback.php';

$authorizeUrl =
    'https://compte.partitions.ovh/sso/authorize.php';

Le reste du code de génération de state, du verifier PKCE et du challenge reste identique.
10.2. Créer le callback

Crée :

sudo nano /var/www/exemple/public/callback.php

Reprends le code de video/callback.php, en remplaçant les deux valeurs suivantes :

'client_id' => 'exemple',

et :

'redirect_uri' => 'https://exemple.partitions.ovh/callback.php',

Le callback utilise toujours :

https://compte.partitions.ovh/sso/token.php

pour échanger le code.

Il n'est pas nécessaire de créer une nouvelle table sso_codes pour chaque site : la table commune contient déjà le client_id qui permet de distinguer les demandes.
10.3. Créer la page d'accueil

Crée :

sudo nano /var/www/exemple/public/index.php

Tu peux partir de ce modèle :

<?php

declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

/*
 * Vérifier l'autorisation à chaque requête.
 */
exigerAccesService($pdo, 'exemple');

function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

$identifiant = (string) (
    $_SESSION['utilisateur_identifiant'] ?? ''
);

$csrfToken = $_SESSION['csrf_token'] ?? '';

if (!is_string($csrfToken) || $csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Exemple | Partitions</title>
</head>

<body>

    <main>
        <h1>Bienvenue sur Exemple</h1>

        <p>
            Bonjour <?= e($identifiant) ?> !
        </p>

        <p>
            Vous êtes connecté grâce à l'authentification centralisée.
        </p>

        <form method="POST" action="deconnexion.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <button type="submit">
                Se déconnecter
            </button>
        </form>
    </main>

</body>
</html>

Cette page réutilise l'authentification centrale et vérifie les autorisations propres au service exemple.
10.4. Créer la déconnexion locale

Crée :

sudo nano /var/www/exemple/public/deconnexion.php

Tu peux reprendre le fichier deconnexion.php du site vidéo.

Il doit :

    accepter uniquement les requêtes POST ;
    vérifier le jeton CSRF ;
    vider la session PHP locale ;
    supprimer le cookie de session ;
    détruire la session ;
    rediriger vers connexion.php.

La déconnexion locale ne doit pas supprimer la session centrale sur compte.partitions.ovh. Ainsi, l'utilisateur pourra se reconnecter au site sans ressaisir son mot de passe tant que sa session centrale est encore valide.
11. Améliorations de sécurité à effectuer avant la mise en production

Le code précédent constitue une base de travail, mais je te recommande plusieurs améliorations avant de l'exposer publiquement.
11.1. Vérifier le type de réponse demandé

Dans authorize.php, ajoute une lecture du paramètre :

$responseType = (string) ($_GET['response_type'] ?? '');

Puis, avec les autres validations :

if ($responseType !== 'code') {
    erreurSso('Type de réponse non autorisé.');
}

Le client doit obligatoirement demander un code d'autorisation.
11.2. Limiter les tentatives d'autorisation

Il est préférable de limiter le nombre de demandes d'autorisation et d'échanges de codes par adresse IP et par client.

Cela évite qu'un client malveillant génère un volume excessif de demandes.

Tu disposes déjà d'un mécanisme de limitation des tentatives de connexion dans :

/var/www/compte/config/login_security.php

Tu peux t'en inspirer, mais les demandes SSO doivent avoir leur propre politique de limitation : elles ne correspondent pas nécessairement à des tentatives de mot de passe.
11.3. Nettoyer les codes expirés

Les codes expirés et déjà consommés peuvent être supprimés régulièrement.

Par exemple, une fois par jour :

DELETE FROM compte.sso_codes
WHERE date_expiration < NOW()
   OR (
       date_utilisation IS NOT NULL
       AND date_utilisation < DATE_SUB(NOW(), INTERVAL 1 DAY)
   );

Tu peux automatiser cette opération avec un cron.

Cette requête ne supprime que les codes expirés ou consommés depuis plus d'un jour.
11.4. Ne jamais désactiver la vérification TLS

Dans le client PHP, conserve impérativement :

CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2,

Sans cela, la communication entre le site client et le serveur central pourrait être vulnérable à une interception.
11.5. Ne pas confondre rôle et autorisation

Ton application possède des rôles (user, moderator, admin) et des autorisations par service.

Le rôle administrateur ne doit pas automatiquement contourner les autorisations d'accès aux services si ce n'est pas une règle que tu souhaites explicitement mettre en place.

Dans notre modèle, un administrateur doit lui aussi avoir une autorisation explicite pour accéder à un service métier.
11.6. Ne pas transmettre de données sensibles dans les URL

Le navigateur peut transporter le code temporaire et le paramètre state, mais jamais :

    le mot de passe ;
    le verifier PKCE ;
    le mot de passe MySQL ;
    un secret permanent d'authentification.

Le serveur central ne doit pas non plus retourner les informations utilisateur au navigateur dans une réponse destinée à établir la session. C'est le serveur PHP du site client qui effectue l'échange.
12. Tester l'installation progressivement

Avant de tester le parcours complet dans le navigateur, vérifie la syntaxe PHP.
12.1. Vérifier les fichiers

Exécute :

sudo php -l /var/www/compte/config/sso_clients.php

sudo php -l /var/www/compte/public/sso/authorize.php

sudo php -l /var/www/compte/public/sso/token.php

sudo php -l /var/www/compte/public/connexion.php

sudo php -l /var/www/video/public/connexion.php

sudo php -l /var/www/video/public/callback.php

sudo php -l /var/www/video/public/index.php

Chaque commande doit répondre :

No syntax errors detected

Si une erreur apparaît, corrige-la avant de poursuivre.
12.2. Vérifier Apache et HTTPS

sudo apache2ctl configtest
sudo systemctl reload apache2

Puis vérifie que les deux endpoints centraux sont accessibles :

curl -sS -o /dev/null -w '%{http_code}\n' \
    https://compte.partitions.ovh/sso/authorize.php

Sans paramètres, le résultat doit être une erreur HTTP correspondant à une demande invalide, et non une erreur PHP ou une page de serveur introuvable.

Pour token.php, une requête GET doit répondre 405 Method Not Allowed, puisqu'il n'accepte que POST.

curl -sS -o /dev/null -w '%{http_code}\n' \
    https://compte.partitions.ovh/sso/token.php

12.3. Vérifier le parcours de connexion

Une fois les vérifications précédentes effectuées :

    Déconnecte-toi de video.partitions.ovh.
    Ouvre une fenêtre de navigation privée.
    Accède à https://video.partitions.ovh/.
    Vérifie que tu es redirigé vers compte.partitions.ovh.
    Connecte-toi avec ton compte habituel.
    Vérifie que tu reviens sur video.partitions.ovh.
    Vérifie que ton nom d'utilisateur apparaît sur la page d'accueil.

La navigation privée est utile pour vérifier le parcours sans session préexistante.
12.4. Vérifier la sécurité des autorisations

Connecte-toi avec un compte qui n'a pas accès au service vidéo.

Le serveur central doit refuser l'autorisation et ne doit pas émettre de code utilisable pour établir une session vidéo.

Accorde ensuite explicitement l'autorisation au compte et recommence le test.
12.5. Vérifier qu'un code ne peut pas être réutilisé

Après une connexion réussie, un code d'autorisation doit être inutilisable une deuxième fois.

La table sso_codes doit contenir une date d'utilisation pour le code consommé.

Tu peux examiner les dernières entrées :

sudo mysql -e "
SELECT
    client_id,
    date_expiration,
    date_utilisation,
    date_creation
FROM compte.sso_codes
ORDER BY date_creation DESC
LIMIT 10;
"

Le code en clair ne doit jamais être enregistré dans cette table.
13. Point de vigilance : terminer l'intégration avant de l'utiliser

Le code fourni constitue l'ossature d'un SSO avec PKCE, mais plusieurs points doivent être vérifiés avant de considérer l'ensemble comme prêt pour la production.

En particulier :

    La reprise de la demande SSO après connexion doit être intégrée proprement à ton connexion.php existant.
    Le paramètre response_type=code doit être validé par le serveur central. Si tu ajoutes cette validation, pense aussi à inclure response_type=code dans les paramètres reconstruits par destinationApresConnexion().
    Le serveur central doit être le seul à pouvoir émettre et consommer les codes SSO.
    Le site client doit toujours vérifier les droits de l'utilisateur sur ses pages protégées.
    Les erreurs techniques ne doivent pas divulguer les informations internes de MySQL.
    La déconnexion locale doit rester distincte de la déconnexion centrale, sauf si tu souhaites ultérieurement mettre en place une déconnexion globale.

Ne remplace pas immédiatement les fichiers en production sans sauvegarde. Les modifications concernent le mécanisme d'authentification : une erreur pourrait empêcher les utilisateurs de se connecter.

Pour conserver un retour arrière possible, tu peux notamment sauvegarder le fichier central avant de le modifier :

sudo cp \
    /var/www/compte/public/connexion.php \
    /var/www/compte/public/connexion.php.avant-sso

Et conserver également les sauvegardes des fichiers du site vidéo.
Résultat attendu

Une fois l'intégration terminée et testée, le fonctionnement sera le suivant :

    L'utilisateur se connecte à compte.partitions.ovh.
    Il ouvre video.partitions.ovh sans ressaisir son mot de passe.
    Il ouvre ensuite exemple.partitions.ovh sans nouvelle authentification, tant que sa session centrale reste valide.
    Chaque site dispose de sa propre session PHP.
    Chaque site vérifie indépendamment les autorisations accordées dans acces_utilisateur_services.

Tu pourras alors ajouter de nouveaux sous-domaines en enregistrant leur client SSO et leur service dans la configuration centrale, sans avoir à recréer un système de comptes ou de mots de passe pour chaque site.
