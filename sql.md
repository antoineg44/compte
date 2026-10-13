# Construire compte.partitions.ovh de zéro : toutes les requêtes SQL

Voici une procédure complète pour créer la base de données du site compte.partitions.ovh, en reprenant l'architecture que nous avons mise en place ensemble.

L'objectif est de disposer d'une base MySQL permettant de gérer :

    Les utilisateurs : identifiant, adresse e-mail, mot de passe et rôle.
    Les rôles : utilisateur, modérateur et administrateur.
    Les services du site : Documents, Statistiques, Vidéo, Outils avancés, etc.
    Les autorisations individuelles : chaque utilisateur peut avoir accès à certains services et pas à d'autres.
    Les tentatives de connexion : suivi des connexions réussies et échouées.
    Le journal d'activité : historique des événements importants.
    La récupération du mot de passe : codes temporaires envoyés par e-mail.
    Le SSO (Single Sign-On) : connexion partagée entre compte.partitions.ovh, video.partitions.ovh et les futurs sous-domaines autorisés.

Les requêtes ci-dessous concernent MySQL 8 et utilisent InnoDB, des clés étrangères et le jeu de caractères utf8mb4.

Important : une base recréée de zéro sera vide. Si tu exécutes ces requêtes sur ta base actuelle, ne supprime pas les tables existantes sans sauvegarde. Certaines instructions de cette procédure sont destinées à une installation neuve.
1. Créer la base de données et son utilisateur MySQL

Connecte-toi à MySQL en administrateur :

sudo mysql

Crée la base de données :

CREATE DATABASE compte
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

Crée un utilisateur dédié à l'application :

CREATE USER 'compte_app'@'localhost'
IDENTIFIED BY 'REMPLACE_PAR_UN_MOT_DE_PASSE_LONG_ET_ALEATOIRE';

Accorde-lui les droits nécessaires sur cette base :

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, INDEX, REFERENCES
ON compte.*
TO 'compte_app'@'localhost';

Applique les changements :

FLUSH PRIVILEGES;

Quitte MySQL :

EXIT;

Remarques de sécurité

    Remplace le mot de passe d'exemple par un véritable secret aléatoire.
    Utilise ce même mot de passe dans /var/www/compte/config/database.php.
    Ne stocke pas ce secret dans un fichier accessible depuis le Web.
    Le compte applicatif ne doit pas être un compte MySQL administrateur.
    Pour une exploitation normale, les droits de création et de modification des tables peuvent être retirés après les migrations.

Si la base compte existe déjà, ne relance pas sa création : passe directement à la vérification des tables.
2. Créer la table des utilisateurs

Cette table constitue le cœur de l'application. Elle permet de stocker les comptes et leurs informations d'authentification.

USE compte;

CREATE TABLE utilisateurs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifiant VARCHAR(50)
        COLLATE utf8mb4_unicode_ci NOT NULL,
    email VARCHAR(255)
        COLLATE utf8mb4_unicode_ci NOT NULL,
    mot_de_passe VARCHAR(255)
        COLLATE utf8mb4_unicode_ci NOT NULL,
    date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('user', 'moderator', 'admin')
        NOT NULL DEFAULT 'user',

    PRIMARY KEY (id),

    UNIQUE KEY uq_utilisateurs_identifiant (identifiant),
    UNIQUE KEY uq_utilisateurs_email (email),

    KEY idx_utilisateurs_role (role)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

À quoi servent les colonnes ?
Colonne	Fonction
id	Identifiant numérique unique du compte
identifiant	Nom utilisé pour se connecter
email	Adresse e-mail du compte
mot_de_passe	Empreinte cryptographique du mot de passe
date_creation	Date de création du compte
admin	Ancien indicateur d'administration, conservé pour compatibilité
role	Rôle actuel du compte

Le mot de passe doit être enregistré avec password_hash() en PHP et vérifié avec password_verify(). Il ne doit jamais être enregistré en clair.

Le champ role est la référence principale pour les autorisations administratives. Le champ admin est conservé pour correspondre à la structure existante, mais il ne doit pas servir à décider seul des permissions.

Les rôles disponibles sont :

    user : utilisateur standard ;
    moderator : modérateur ;
    admin : administrateur.

Attention : cette table ne crée aucun compte administrateur automatiquement. Il faudra créer un compte et lui attribuer le rôle admin.
3. Créer la table des services

Cette table répertorie les fonctionnalités proposées sur le site.

Exemples :

    Documents ;
    Statistiques ;
    Outils avancés ;
    Vidéo.

CREATE TABLE services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    nom VARCHAR(100)
        COLLATE utf8mb4_unicode_ci NOT NULL,

    slug VARCHAR(100)
        COLLATE utf8mb4_unicode_ci NOT NULL,

    description VARCHAR(255)
        COLLATE utf8mb4_unicode_ci DEFAULT NULL,

    actif TINYINT(1) NOT NULL DEFAULT 1,

    date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_services_nom (nom),
    UNIQUE KEY uq_services_slug (slug),

    KEY idx_services_actif (actif)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

Signification des colonnes

    nom : nom visible dans l'interface.
    slug : identifiant technique utilisé dans le code PHP.
    description : présentation du service.
    actif : indique si le service est globalement disponible.
    date_creation : date d'ajout du service.

Le slug doit rester stable, car il est utilisé dans les vérifications d'autorisation.
Ajouter les services initiaux

INSERT INTO services (
    nom,
    slug,
    description,
    actif
)
VALUES
(
    'Documents',
    'documents',
    'Accès aux documents',
    1
),
(
    'Statistiques',
    'statistiques',
    'Accès aux statistiques',
    1
),
(
    'Outils avancés',
    'outils-avances',
    'Accès aux outils avancés',
    1
),
(
    'Vidéo',
    'video',
    'Accès à la plateforme vidéo',
    1
);

Cette requête ajoute les quatre services de départ. Tu peux modifier leurs noms et leurs descriptions selon les fonctionnalités réellement présentes sur ton site.

Pour ajouter ultérieurement un service, par exemple une bibliothèque musicale :

INSERT INTO services (
    nom,
    slug,
    description,
    actif
)
VALUES (
    'Musique',
    'musique',
    'Accès à la plateforme musicale',
    1
);

Pour désactiver temporairement un service sans supprimer sa configuration ni ses autorisations :

UPDATE services
SET actif = 0
WHERE slug = 'musique';

Pour le réactiver :

UPDATE services
SET actif = 1
WHERE slug = 'musique';

Un service désactivé doit être inaccessible, même aux utilisateurs qui disposent d'une autorisation individuelle.
4. Créer la table des autorisations individuelles

La table acces_utilisateur_services associe chaque utilisateur à chaque service auquel ses droits ont été définis.

Elle permet de répondre à la question suivante :

    L'utilisateur 12 a-t-il le droit d'accéder au service Vidéo ?

CREATE TABLE acces_utilisateur_services (
    utilisateur_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,

    autorise TINYINT(1) NOT NULL DEFAULT 0,

    modifie_par BIGINT UNSIGNED DEFAULT NULL,

    date_modification TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (utilisateur_id, service_id),

    KEY idx_acces_service (service_id),
    KEY idx_acces_modifie_par (modifie_par),

    CONSTRAINT fk_acces_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_acces_service
        FOREIGN KEY (service_id)
        REFERENCES services (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_acces_modifie_par
        FOREIGN KEY (modifie_par)
        REFERENCES utilisateurs (id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

Comment fonctionne cette table ?

Imaginons que l'utilisateur antoine possède l'identifiant numérique 2.

Pour lui accorder l'accès au service Vidéo :

INSERT INTO acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise,
    modifie_par
)
SELECT
    2,
    s.id,
    1,
    NULL
FROM services s
WHERE s.slug = 'video'
ON DUPLICATE KEY UPDATE
    autorise = 1;

Pour lui retirer cet accès :

UPDATE acces_utilisateur_services
SET autorise = 0
WHERE utilisateur_id = 2
  AND service_id = (
      SELECT id
      FROM services
      WHERE slug = 'video'
  );

La valeur 1 signifie que l'accès est autorisé ; 0 signifie qu'il est refusé.

L'absence de ligne signifie également que l'utilisateur ne possède aucune autorisation explicite. L'absence d'une autorisation ne doit jamais être interprétée comme une autorisation accordée.

Pour enregistrer l'identifiant de l'administrateur ayant modifié un droit, remplace NULL dans la requête d'attribution par son identifiant numérique.

Par exemple, si l'administrateur possède l'identifiant 1 :

INSERT INTO acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise,
    modifie_par
)
SELECT
    2,
    s.id,
    1,
    1
FROM services s
WHERE s.slug = 'video'
ON DUPLICATE KEY UPDATE
    autorise = 1,
    modifie_par = 1;

Donner à un utilisateur l'accès à tous les services actifs

Pour un utilisateur dont l'identifiant est 2 :

INSERT INTO acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise
)
SELECT
    2,
    id,
    1
FROM services
WHERE actif = 1
ON DUPLICATE KEY UPDATE
    autorise = 1;

Cette requête accorde l'accès à tous les services actifs, y compris ceux ajoutés précédemment et ceux qui seront ajoutés ultérieurement si la requête est exécutée de nouveau.

Pour retirer tous les accès d'un utilisateur sans supprimer son compte :

UPDATE acces_utilisateur_services
SET autorise = 0
WHERE utilisateur_id = 2;

Cette opération ne désactive pas le compte lui-même : elle lui retire uniquement ses autorisations d'accès aux services.
5. Créer la table des tentatives de connexion

Cette table permet de suivre les tentatives de connexion afin de limiter les attaques par force brute.

CREATE TABLE tentatives_connexion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    identifiant VARCHAR(50) NOT NULL,
    adresse_ip VARCHAR(45) NOT NULL,

    reussie TINYINT(1) NOT NULL DEFAULT 0,

    date_creation TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_tentatives_identifiant_date (
        identifiant,
        date_creation
    ),

    KEY idx_tentatives_ip_date (
        adresse_ip,
        date_creation
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

Les colonnes principales sont :

    identifiant : identifiant fourni lors de la tentative ;
    adresse_ip : adresse IP à l'origine de la tentative ;
    reussie : 1 si la connexion a réussi, 0 sinon ;
    date_creation : date de la tentative.

Enregistrer une tentative

Pour enregistrer une connexion échouée :

INSERT INTO tentatives_connexion (
    identifiant,
    adresse_ip,
    reussie
)
VALUES (
    'antoine',
    '203.0.113.10',
    0
);

L'adresse IP utilisée ici est fictive et sert uniquement d'exemple.

Pour enregistrer une connexion réussie :

INSERT INTO tentatives_connexion (
    identifiant,
    adresse_ip,
    reussie
)
VALUES (
    'antoine',
    '203.0.113.10',
    1
);

Dans l'application réelle, ces requêtes doivent être exécutées avec des requêtes préparées PDO.
Compter les échecs des 15 dernières minutes

SELECT COUNT(*) AS nombre_echecs
FROM tentatives_connexion
WHERE identifiant = 'antoine'
  AND reussie = 0
  AND date_creation >= NOW() - INTERVAL 15 MINUTE;

Pour compter les échecs associés à une adresse IP :

SELECT COUNT(*) AS nombre_echecs
FROM tentatives_connexion
WHERE adresse_ip = '203.0.113.10'
  AND reussie = 0
  AND date_creation >= NOW() - INTERVAL 15 MINUTE;

Ces requêtes fournissent les compteurs nécessaires, mais ne constituent pas à elles seules une protection complète. La logique PHP doit appliquer une limite cohérente, contrôler les tentatives simultanées et éviter de révéler si un identifiant existe.
Supprimer les anciennes tentatives

Par exemple, pour supprimer les tentatives datant de plus de 90 jours :

DELETE FROM tentatives_connexion
WHERE date_creation < NOW() - INTERVAL 90 DAY;

Cette opération peut être exécutée périodiquement à l'aide d'une tâche planifiée.
6. Créer le journal d'activité

Le journal conserve les événements importants de l'application : connexions, déconnexions, changements de droits et actions administratives.

CREATE TABLE journal_activite (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    utilisateur_id BIGINT UNSIGNED DEFAULT NULL,

    action VARCHAR(100) NOT NULL,

    details JSON DEFAULT NULL,

    adresse_ip VARCHAR(45) DEFAULT NULL,

    date_creation TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_journal_utilisateur (utilisateur_id),
    KEY idx_journal_action (action),
    KEY idx_journal_date (date_creation),

    CONSTRAINT fk_journal_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs (id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

La colonne details permet d'enregistrer des informations complémentaires au format JSON.

Par exemple, un événement de connexion réussie peut contenir :

{
  "role": "admin"
}

Exemples de requêtes

Enregistrer une connexion réussie :

INSERT INTO journal_activite (
    utilisateur_id,
    action,
    details,
    adresse_ip
)
VALUES (
    2,
    'connexion_reussie',
    JSON_OBJECT('role', 'user'),
    '203.0.113.10'
);

Enregistrer une modification des droits :

INSERT INTO journal_activite (
    utilisateur_id,
    action,
    details,
    adresse_ip
)
VALUES (
    1,
    'droits_service_modifies',
    JSON_OBJECT(
        'utilisateur_cible', 2,
        'service', 'video',
        'autorise', TRUE
    ),
    '203.0.113.10'
);

Dans cet exemple, l'utilisateur 1 est celui qui effectue la modification, tandis que l'identifiant 2 correspond à l'utilisateur ciblé.

Consulter les 100 derniers événements :

SELECT
    j.id,
    u.identifiant,
    j.action,
    j.details,
    j.adresse_ip,
    j.date_creation
FROM journal_activite j
LEFT JOIN utilisateurs u
    ON u.id = j.utilisateur_id
ORDER BY j.date_creation DESC, j.id DESC
LIMIT 100;

Le journal ne doit pas contenir de mots de passe, de codes de récupération, de jetons SSO ou d'autres secrets d'authentification.
7. Créer la table de récupération du mot de passe

Le fonctionnement souhaité est le suivant :

    L'utilisateur indique son identifiant ou son adresse e-mail.
    Le serveur génère un code aléatoire.
    Le code est envoyé par e-mail, via Postfix.
    Seule une empreinte du code est conservée dans MySQL.
    L'utilisateur saisit le code reçu.
    Si le code est valide et non expiré, il peut choisir un nouveau mot de passe.
    Le code est marqué comme utilisé pour empêcher sa réutilisation.

La table suivante est une proposition de schéma pour ce mécanisme.

CREATE TABLE codes_reinitialisation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    utilisateur_id BIGINT UNSIGNED NOT NULL,

    code_hash CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin NOT NULL,

    date_expiration DATETIME NOT NULL,

    date_utilisation DATETIME DEFAULT NULL,

    date_creation TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_reinitialisation_code_hash (code_hash),

    KEY idx_reinitialisation_utilisateur (
        utilisateur_id,
        date_creation
    ),

    KEY idx_reinitialisation_expiration (
        date_expiration
    ),

    CONSTRAINT fk_reinitialisation_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs (id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

Pourquoi enregistrer code_hash plutôt que le code ?

Le code envoyé par e-mail est un secret temporaire. En enregistrant uniquement son empreinte, on évite de conserver directement ce code dans la base.

Le PHP peut calculer cette empreinte avec :

$codeHash = hash('sha256', $code);

Pour vérifier le code, le serveur calcule à nouveau son empreinte et recherche une correspondance, tout en vérifiant sa date d'expiration et son statut d'utilisation.
Consulter les demandes de réinitialisation

SELECT
    id,
    utilisateur_id,
    date_creation,
    date_expiration,
    date_utilisation
FROM codes_reinitialisation
ORDER BY date_creation DESC
LIMIT 100;

Supprimer les codes expirés et utilisés depuis plus de 24 heures :

DELETE FROM codes_reinitialisation
WHERE date_expiration < NOW()
  AND date_utilisation IS NOT NULL
  AND date_utilisation < NOW() - INTERVAL 24 HOUR;

Il est préférable de supprimer aussi les codes expirés non utilisés après une période de conservation adaptée. Une tâche de nettoyage peut gérer les deux cas.

Important : la table seule ne réalise pas la récupération du mot de passe. Le code PHP doit notamment imposer une durée de validité courte, limiter les demandes et les essais, invalider les anciens codes selon la politique choisie et mettre à jour le mot de passe avec password_hash().
8. Créer la table des codes SSO

Le SSO permet à un utilisateur connecté sur compte.partitions.ovh d'accéder à un autre site autorisé, par exemple video.partitions.ovh, sans ressaisir son mot de passe.

Dans l'architecture envisagée, le site principal émet un code temporaire à usage unique. Le site secondaire l'échange contre une confirmation d'identité, en utilisant un mécanisme de type PKCE.

La table sso_codes doit être créée dans la base compte.

CREATE TABLE sso_codes (
    code_hash CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin NOT NULL,

    utilisateur_id BIGINT UNSIGNED NOT NULL,

    client_id VARCHAR(50)
        COLLATE utf8mb4_unicode_ci NOT NULL,

    code_challenge CHAR(43)
        CHARACTER SET ascii
        COLLATE ascii_bin NOT NULL,

    date_expiration DATETIME NOT NULL,

    date_utilisation DATETIME DEFAULT NULL,

    date_creation TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (code_hash),

    KEY idx_sso_expiration (date_expiration),
    KEY idx_sso_utilisateur (utilisateur_id),

    CONSTRAINT fk_sso_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs (id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

Rôle des colonnes
Colonne	Fonction
code_hash	Empreinte du code temporaire
utilisateur_id	Compte authentifié à l'origine du code
client_id	Identifiant du site destinataire
code_challenge	Empreinte du vérificateur PKCE
date_expiration	Date limite d'utilisation
date_utilisation	Date d'échange, si le code a été utilisé
date_creation	Date de création

Les codes SSO doivent être générés aléatoirement, expirer rapidement et être consommés une seule fois.

Le client_id doit correspondre à un client connu et autorisé par le serveur. Il ne faut pas accepter librement une adresse de redirection fournie par le navigateur.
Nettoyer les codes SSO expirés

DELETE FROM sso_codes
WHERE date_expiration < NOW();

Cette requête peut être exécutée périodiquement.

Attention : créer cette table ne suffit pas à activer le SSO. Il faut également développer les points d'entrée PHP côté compte et côté site secondaire, vérifier PKCE, consommer chaque code de façon atomique et limiter les redirections aux URL préalablement autorisées.
9. Créer le premier administrateur

Il faut au moins un compte administrateur pour gérer les utilisateurs, les services et les autorisations.
9.1. Générer une empreinte de mot de passe

Sur le serveur Ubuntu, exécute :

php -r 'echo password_hash(
    readline("Mot de passe administrateur : "),
    PASSWORD_DEFAULT
), PHP_EOL;'

Saisis le mot de passe demandé. La commande affiche son empreinte cryptographique.

Conserve cette empreinte pour la requête suivante. Ne saisis jamais le mot de passe en clair dans la base de données.
9.2. Créer le compte

Remplace COLLER_ICI_LE_HASH_GENERE par l'empreinte obtenue.

INSERT INTO compte.utilisateurs (
    identifiant,
    email,
    mot_de_passe,
    admin,
    role
)
VALUES (
    'administrateur',
    'admin@partitions.ovh',
    'COLLER_ICI_LE_HASH_GENERE',
    1,
    'admin'
);

Remplace également l'adresse e-mail par celle que tu souhaites utiliser.

Si l'identifiant ou l'adresse e-mail existe déjà, la requête échouera en raison des contraintes UNIQUE.
9.3. Vérifier le compte

SELECT
    id,
    identifiant,
    email,
    role,
    admin,
    date_creation
FROM compte.utilisateurs
WHERE identifiant = 'administrateur';

Tu dois retrouver le compte avec le rôle admin.
9.4. Accorder les droits sur tous les services actifs

Pour permettre à l'administrateur de tester tous les services :

INSERT INTO compte.acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise,
    modifie_par
)
SELECT
    u.id,
    s.id,
    1,
    u.id
FROM compte.utilisateurs u
CROSS JOIN compte.services s
WHERE u.identifiant = 'administrateur'
  AND s.actif = 1
ON DUPLICATE KEY UPDATE
    autorise = 1,
    modifie_par = VALUES(modifie_par);

Cette requête accorde les autorisations aux services actifs et enregistre l'administrateur comme auteur des modifications.

À retenir : le rôle admin autorise la gestion de l'application, mais il ne doit pas contourner automatiquement les contrôles d'accès aux services métier. C'est le comportement prévu par la fonction utilisateurAAccesService() de ton application.
10. Ajouter un nouvel utilisateur

La méthode recommandée est de passer par la page PHP d'inscription, qui valide les données et génère correctement le hachage du mot de passe.

Si tu veux créer un compte manuellement, commence par générer une empreinte avec PHP :

php -r 'echo password_hash(
    readline("Mot de passe : "),
    PASSWORD_DEFAULT
), PHP_EOL;'

Puis utilise cette empreinte dans la requête :

INSERT INTO compte.utilisateurs (
    identifiant,
    email,
    mot_de_passe,
    admin,
    role
)
VALUES (
    'nouvel_utilisateur',
    'utilisateur@example.com',
    'COLLER_ICI_LE_HASH_GENERE',
    0,
    'user'
);

Pour un nouveau compte, il est recommandé de ne donner aucun accès aux services par défaut.

Tu peux ensuite accorder une autorisation précise. Par exemple, pour permettre à ce compte d'accéder à Vidéo :

INSERT INTO compte.acces_utilisateur_services (
    utilisateur_id,
    service_id,
    autorise
)
SELECT
    u.id,
    s.id,
    1
FROM compte.utilisateurs u
JOIN compte.services s
    ON s.slug = 'video'
WHERE u.identifiant = 'nouvel_utilisateur'
ON DUPLICATE KEY UPDATE
    autorise = 1;

Pour retirer ultérieurement ce droit :

UPDATE compte.acces_utilisateur_services a
JOIN compte.utilisateurs u
    ON u.id = a.utilisateur_id
JOIN compte.services s
    ON s.id = a.service_id
SET a.autorise = 0
WHERE u.identifiant = 'nouvel_utilisateur'
  AND s.slug = 'video';

11. Requêtes utiles pour administrer le site

Voici les requêtes de consultation et de gestion les plus utiles au quotidien.
Lister les utilisateurs

SELECT
    id,
    identifiant,
    email,
    role,
    date_creation
FROM compte.utilisateurs
ORDER BY id;

Lister les services

SELECT
    id,
    nom,
    slug,
    description,
    actif,
    date_creation
FROM compte.services
ORDER BY nom;

Voir tous les droits de tous les utilisateurs

Cette requête affiche également les services pour lesquels aucun droit n'a été défini.

SELECT
    u.id AS utilisateur_id,
    u.identifiant,
    s.id AS service_id,
    s.nom AS service,
    s.slug,
    CASE
        WHEN s.actif = 0 THEN 'Service désactivé'
        WHEN a.autorise = 1 THEN 'Autorisé'
        WHEN a.autorise = 0 THEN 'Refusé'
        ELSE 'Non défini'
    END AS statut
FROM compte.utilisateurs u
CROSS JOIN compte.services s
LEFT JOIN compte.acces_utilisateur_services a
    ON a.utilisateur_id = u.id
   AND a.service_id = s.id
ORDER BY u.identifiant, s.nom;

Vérifier les droits d'un utilisateur

Par exemple, pour antoine :

SELECT
    u.identifiant,
    s.nom AS service,
    s.slug,
    s.actif,
    a.autorise,
    CASE
        WHEN s.actif = 0 THEN 0
        WHEN a.autorise = 1 THEN 1
        ELSE 0
    END AS acces_effectif
FROM compte.utilisateurs u
CROSS JOIN compte.services s
LEFT JOIN compte.acces_utilisateur_services a
    ON a.utilisateur_id = u.id
   AND a.service_id = s.id
WHERE u.identifiant = 'antoine'
ORDER BY s.nom;

La colonne acces_effectif représente le résultat attendu du contrôle d'accès, sous réserve que le compte soit authentifié.
Changer le rôle d'un utilisateur

Pour promouvoir un utilisateur au rang de modérateur :

UPDATE compte.utilisateurs
SET role = 'moderator',
    admin = 0
WHERE identifiant = 'antoine';

Pour promouvoir un utilisateur au rang d'administrateur :

UPDATE compte.utilisateurs
SET role = 'admin',
    admin = 1
WHERE identifiant = 'antoine';

Pour rétrograder un administrateur :

UPDATE compte.utilisateurs
SET role = 'user',
    admin = 0
WHERE identifiant = 'antoine';

Le changement de rôle doit être réservé aux administrateurs autorisés et réalisé depuis une interface sécurisée.
Désactiver un service

UPDATE compte.services
SET actif = 0
WHERE slug = 'video';

Réactiver un service

UPDATE compte.services
SET actif = 1
WHERE slug = 'video';

Supprimer un utilisateur

Cette opération est définitive. Les lignes liées aux autorisations, aux codes SSO et aux codes de réinitialisation seront supprimées automatiquement grâce aux clés étrangères.

DELETE FROM compte.utilisateurs
WHERE identifiant = 'utilisateur_a_supprimer';

Attention : les entrées du journal d'activité associées à cet utilisateur seront conservées, mais leur utilisateur_id deviendra NULL.
12. Vérifier la structure complète de la base

Une fois les tables créées, exécute :

SHOW TABLES FROM compte;

Avec les requêtes présentées dans cette procédure, tu dois retrouver les tables suivantes :
Table	Fonction
utilisateurs	Comptes, mots de passe et rôles
services	Catalogue des services
acces_utilisateur_services	Autorisations individuelles
tentatives_connexion	Tentatives de connexion
journal_activite	Historique des événements
codes_reinitialisation	Réinitialisation des mots de passe
sso_codes	Codes temporaires du SSO

La table codes_2fa n'est pas incluse dans cette nouvelle architecture, conformément à ton choix de ne pas utiliser l'authentification à deux facteurs.

Pour vérifier les colonnes et les contraintes d'une table :

SHOW CREATE TABLE compte.utilisateurs\G

Tu peux répéter cette commande pour les autres tables.
Vérifier les clés étrangères

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'compte'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;

Vérifier les services enregistrés

SELECT id, nom, slug, actif
FROM compte.services
ORDER BY id;

Vérifier les comptes et leurs rôles

SELECT id, identifiant, email, role
FROM compte.utilisateurs
ORDER BY id;

13. Architecture finale du site

Une fois ces tables créées, le fonctionnement de compte.partitions.ovh peut être résumé ainsi :

compte.partitions.ovh
│
├── Authentification
│   ├── utilisateurs
│   ├── tentatives_connexion
│   └── journal_activite
│
├── Gestion des services
│   ├── services
│   └── acces_utilisateur_services
│
├── Récupération du mot de passe
│   ├── codes_reinitialisation
│   └── Postfix pour l'envoi des e-mails
│
├── Authentification partagée (SSO)
│   └── sso_codes
│
└── Sites connectés
    ├── video.partitions.ovh
    ├── compte.partitions.ovh
    └── futurs sous-domaines autorisés

Les tables assurent le stockage des données, mais la sécurité dépend également du code PHP : validation des entrées, requêtes préparées, protection CSRF, gestion des sessions, vérification des rôles et contrôle des autorisations à chaque accès aux services.

Le SSO doit également vérifier l'identité du site demandeur, l'expiration des codes et leur utilisation unique. Un cookie de session partagé entre tous les sous-domaines n'est pas nécessaire : une redirection sécurisée entre les sites peut assurer l'authentification partagée.
Dernière recommandation

Pour une installation neuve, exécute les requêtes dans cet ordre :

    Créer la base et son utilisateur MySQL.
    Créer utilisateurs.
    Créer services, puis insérer les services initiaux.
    Créer acces_utilisateur_services.
    Créer tentatives_connexion.
    Créer journal_activite.
    Créer codes_reinitialisation.
    Créer sso_codes.
    Créer le premier administrateur.
    Lui accorder les autorisations nécessaires.

Ces requêtes construisent la structure SQL, mais ne recréent pas à elles seules le site. Il faudra également installer les fichiers PHP correspondants, configurer Apache, renseigner les identifiants MySQL, configurer Postfix et développer les endpoints SSO.

Si tu veux reproduire exactement ton installation actuelle, vérifie aussi les tables déjà présentes avant d'exécuter les instructions : ta base utilise notamment des collations différentes selon les tables, ce qui n'empêche pas le fonctionnement tant que les clés étrangères sont compatibles.