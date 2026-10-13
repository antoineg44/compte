Résumé du fonctionnement de compte.partitions.ovh

Le site https://compte.partitions.ovh est le serveur central de gestion des comptes et de l'authentification de ton infrastructure. Il permet aux utilisateurs de disposer d'un compte unique pour accéder à plusieurs sites de l'écosystème partitions.ovh.

L'objectif est que l'utilisateur se connecte une seule fois et puisse ensuite accéder aux différents sites autorisés, sans devoir saisir à nouveau son identifiant et son mot de passe.
1. Architecture générale

Ton infrastructure repose actuellement sur plusieurs sites distincts :
Site	Fonction
compte.partitions.ovh	Gestion des comptes, connexion et authentification centralisée
video.partitions.ovh	Plateforme vidéo
drive.partitions.ovh	Plateforme de stockage de fichiers
musiques.partitions.ovh	Plateforme musicale
app.partitions.ovh	Application web
partitions.ovh	Site principal

Chaque site possède son propre espace web et peut avoir ses propres fonctionnalités.

Le site compte.partitions.ovh centralise les utilisateurs et les autorisations d'accès.

L'architecture peut être représentée ainsi :

                       UTILISATEUR
                            |
                            v
                 compte.partitions.ovh
                            |
              +-------------+-------------+
              |             |             |
              v             v             v
       video.partitions.ovh drive.partitions.ovh
                            |
                            v
                   autres sites autorisés

L'authentification centralisée permet aux différents sites de s'appuyer sur le même compte utilisateur.
2. Gestion des comptes utilisateurs

Les comptes sont enregistrés dans la base de données MySQL compte.

La table principale est :

utilisateurs

Elle contient notamment les colonnes suivantes :
Colonne	Fonction
id	Identifiant unique du compte
identifiant	Nom d'utilisateur
email	Adresse e-mail
mot_de_passe	Empreinte cryptographique du mot de passe
date_creation	Date de création du compte
admin	Ancien indicateur d'administration
role	Rôle de l'utilisateur

Trois rôles sont prévus :

    user : utilisateur standard.
    moderator : modérateur.
    admin : administrateur.

Les mots de passe sont enregistrés sous forme de hachages avec password_hash(). Le mot de passe en clair n'est donc pas stocké dans la base de données.
Inscription

Le fichier :

/var/www/compte/public/inscription.php

permet aux visiteurs de créer un compte.

Le traitement vérifie notamment :

    la validité de l'identifiant ;
    la validité de l'adresse e-mail ;
    la longueur du mot de passe ;
    la correspondance entre le mot de passe et sa confirmation ;
    l'absence d'un compte utilisant déjà cet identifiant ou cette adresse e-mail.

Le mot de passe est ensuite haché avant son enregistrement.
Connexion

Le fichier :

/var/www/compte/public/connexion.php

permet aux utilisateurs de se connecter.

Le fonctionnement est le suivant :

    L'utilisateur saisit son identifiant et son mot de passe.
    Le serveur recherche son compte dans MySQL.
    Il vérifie le mot de passe avec password_verify().
    Il renouvelle l'identifiant de session PHP après une authentification réussie.
    Il enregistre les informations du compte dans la session.
    Il journalise l'événement de connexion.
    Il redirige l'utilisateur vers son espace personnel ou reprend une demande SSO en attente, lorsque cette fonctionnalité est correctement intégrée.

Le système utilise également des protections contre les tentatives de connexion répétées et les attaques CSRF.
3. Espace personnel

Une fois connecté, l'utilisateur accède à :

https://compte.partitions.ovh/espace.php

Cette page affiche notamment :

    son identifiant ;
    son adresse e-mail ;
    son numéro de compte ;
    son état de connexion.

Elle constitue le point d'entrée de l'espace personnel.

Elle pourra également servir à afficher les services disponibles pour l'utilisateur, ses paramètres de compte et les fonctionnalités de gestion de son profil.
4. Gestion des services et des autorisations

C'est l'une des fonctionnalités les plus importantes de ton architecture.

Tu souhaites pouvoir décider précisément quels utilisateurs peuvent accéder à quels sites.

Pour cela, tu disposes de deux tables principales.
4.1. Table services

Cette table recense les services disponibles sur ton infrastructure.

Structure actuelle :

services
├── id
├── nom
├── slug
├── description
├── actif
└── date_creation

Les services enregistrés comprennent actuellement :
ID	Service	Slug	Actif
1	Documents	documents	Oui
2	Statistiques	statistiques	Oui
3	Outils avancés	outils-avances	Oui
4*	Vidéo	video	Oui

Le numéro 4 est indicatif : l'identifiant exact dépend de la base de données.

Chaque service possède un slug unique qui permet de l'identifier dans le code PHP.

Le champ actif permet de désactiver globalement un service.
4.2. Table acces_utilisateur_services

Cette table détermine les autorisations individuelles.

Elle contient notamment :
Colonne	Fonction
utilisateur_id	Compte concerné
service_id	Service concerné
autorise	Indique si l'accès est accordé
modifie_par	Administrateur ayant modifié le droit
date_modification	Date de la dernière modification

Le couple utilisateur_id et service_id constitue la clé primaire.

Cela permet d'enregistrer une autorisation distincte pour chaque couple utilisateur-service.

Par exemple :
Utilisateur	Vidéo	Documents	Statistiques
Antoine	Autorisé	Refusé	Autorisé
Utilisateur 1	Refusé	Autorisé	Refusé

Ce tableau est illustratif : les autorisations réelles dépendent des données enregistrées dans MySQL.
4.3. Comment les droits sont-ils vérifiés ?

Le fichier :

/var/www/compte/config/services.php

contient les fonctions de vérification des autorisations.

La fonction principale est :

utilisateurAAccesService(
    PDO $pdo,
    int $utilisateurId,
    string $slug
): bool

Elle vérifie trois conditions :

    Le service existe.
    Le service est actif.
    L'utilisateur possède une autorisation explicite.

Par exemple :

$autorise = utilisateurAAccesService(
    $pdo,
    (int) $_SESSION['utilisateur_id'],
    'video'
);

Si la fonction renvoie true, l'utilisateur est autorisé à accéder au service vidéo.

Si elle renvoie false, l'accès doit être refusé.

Une autre fonction est disponible :

exigerAccesService($pdo, 'video');

Elle bloque l'accès à une page protégée lorsque l'utilisateur ne possède pas les droits nécessaires.

Point important : masquer un lien dans une page ne suffit pas à protéger un service. Chaque page protégée, API ou téléchargement doit vérifier les autorisations côté serveur.
5. Authentification unique (SSO)

Le SSO (Single Sign-On) est le mécanisme qui permet d'utiliser le même compte sur plusieurs sites.

L'objectif est de pouvoir se connecter à :

https://compte.partitions.ovh

puis d'accéder à :

https://video.partitions.ovh

sans saisir une deuxième fois son mot de passe.
5.1. Pourquoi les sessions sont-elles distinctes ?

Les deux sites utilisent des sous-domaines différents.

Ils peuvent donc avoir chacun leur propre cookie de session :

compte.partitions.ovh
    └── Session PHP centrale

video.partitions.ovh
    └── Session PHP locale

Le navigateur ne partage pas automatiquement un cookie limité à compte.partitions.ovh avec video.partitions.ovh.

Le SSO permet de résoudre ce problème sans partager directement les cookies de session entre les sites.
5.2. Déroulement d'une authentification SSO

Le parcours prévu est le suivant :

    L'utilisateur ouvre video.partitions.ovh.
    Le site vidéo constate qu'il ne possède pas de session locale.
    Il redirige le navigateur vers le serveur central.
    Le serveur central vérifie si une session utilisateur valide existe.
    Si nécessaire, l'utilisateur se connecte avec son identifiant et son mot de passe.
    Le serveur central vérifie les autorisations du compte pour le service demandé.
    Il génère un code d'autorisation temporaire.
    Le navigateur retourne vers le site vidéo avec ce code.
    Le serveur vidéo échange le code contre les informations d'identité en communiquant directement avec le serveur central.
    Le site vidéo crée sa propre session PHP.

Le mot de passe n'est jamais transmis au site vidéo.
5.3. Protection par PKCE

Le mécanisme prévu utilise PKCE (Proof Key for Code Exchange).

Le client génère :

    un state aléatoire pour protéger le parcours contre les attaques CSRF ;
    un verifier aléatoire ;
    un challenge calculé à partir du verifier.

Le serveur central conserve le challenge associé au code temporaire.

Lors de l'échange du code, le client présente le verifier. Le serveur central vérifie que celui-ci correspond au challenge initial.

Le code d'autorisation est ainsi inutilisable sans la preuve attendue.
5.4. Table sso_codes

La base de données possède déjà une table dédiée :

sso_codes
├── code_hash
├── utilisateur_id
├── client_id
├── code_challenge
├── date_expiration
├── date_utilisation
└── date_creation

Cette table permet de gérer les codes temporaires.

Elle associe chaque code à :

    un utilisateur ;
    un client SSO ;
    une empreinte PKCE ;
    une date d'expiration ;
    une éventuelle date d'utilisation.

Les codes doivent expirer rapidement et ne pouvoir être consommés qu'une seule fois.
5.5. État réel de l'intégration

Un point important doit être distingué de l'architecture souhaitée.

Les commandes que tu as exécutées confirment que la table sso_codes existe. En revanche, la recherche de code PHP effectuée précédemment n'a trouvé aucune implémentation utilisant cette table dans les répertoires inspectés.

Les fichiers SSO et les exemples de code évoqués dans notre échange ne suffisent donc pas, à eux seuls, à confirmer que l'ensemble du mécanisme fonctionne déjà.

Il reste à vérifier que les endpoints centraux existent et sont opérationnels :

/var/www/compte/public/sso/authorize.php
/var/www/compte/public/sso/token.php

Il faut également vérifier que la connexion centrale reprend correctement les demandes SSO après authentification.

Le SSO doit être considéré comme une fonctionnalité en cours d'intégration tant que ce parcours complet n'a pas été testé avec succès.
6. Journalisation des activités

Ton application possède un système de journalisation centralisé.

Le fichier :

/var/www/compte/config/activity.php

contient la fonction :

enregistrerActivite(
    PDO $pdo,
    string $action,
    ?int $utilisateurId = null,
    array $details = []
): void

Cette fonction enregistre les événements dans :

journal_activite

La table contient notamment :

    l'utilisateur concerné ;
    le type d'événement ;
    des détails au format JSON ;
    l'adresse IP ;
    la date de création.

Les événements de connexion réussie et échouée sont déjà pris en compte dans le code de connexion que tu as partagé.

Ce journal pourra également servir à suivre les modifications d'autorisations et les opérations administratives.

Les mots de passe, les secrets d'authentification et les jetons de session ne doivent jamais être enregistrés dans les détails du journal.
7. Sécurité de l'application

Plusieurs mécanismes de sécurité sont déjà présents dans les fichiers que tu as partagés.
Protection des sessions

Les cookies de session utilisent notamment :

[
    'httponly' => true,
    'samesite' => 'Lax',
]

Lorsque la connexion passe par HTTPS, l'attribut secure est également activé.

L'identifiant de session est renouvelé après une connexion réussie.
Protection CSRF

Les formulaires sensibles utilisent un jeton CSRF vérifié côté serveur.

Cette protection est notamment prévue pour :

    la connexion ;
    la déconnexion ;
    les opérations qui modifient des données.

Limitation des tentatives de connexion

Le fichier :

/var/www/compte/config/login_security.php

est utilisé par la connexion centrale pour limiter les tentatives répétées.

La table tentatives_connexion permet de conserver les informations relatives aux tentatives.
Vérification des droits administratifs

Le fichier :

/var/www/compte/public/admin/_auth.php

vérifie le compte connecté directement dans MySQL.

Il actualise les informations de session et réserve actuellement le dossier administratif aux comptes dont le rôle est admin.

Cette vérification évite de faire confiance uniquement à un rôle stocké dans une ancienne session.
8. Gestion du mot de passe oublié

Tu as également souhaité permettre aux utilisateurs de réinitialiser leur mot de passe par e-mail.

Le principe est le suivant :

    L'utilisateur demande la réinitialisation.
    Le système génère un code temporaire.
    Un e-mail est envoyé à l'adresse associée au compte.
    L'utilisateur saisit le code reçu.
    Le serveur vérifie le code et sa durée de validité.
    Si le code est valide, l'utilisateur peut définir un nouveau mot de passe.

Tu as choisi d'utiliser Postfix pour l'envoi des e-mails.

Le serveur dispose de Postfix et écoute sur le port SMTP 25, d'après les commandes que tu as exécutées.

Il reste toutefois nécessaire de distinguer ces éléments techniques de la fonctionnalité PHP : les informations partagées ne permettent pas de confirmer ici l'état actuel de tous les fichiers de réinitialisation.
9. Organisation des fichiers

D'après les chemins que tu as partagés, l'application centrale est organisée de cette manière :

/var/www/compte/
│
├── config/
│   ├── database.php
│   ├── activity.php
│   ├── login_security.php
│   └── services.php
│
└── public/
    ├── index.php
    ├── connexion.php
    ├── inscription.php
    ├── espace.php
    ├── deconnexion.php
    │
    └── admin/
        ├── _auth.php
        └── index.php

Cette arborescence reprend les fichiers dont tu as montré le contenu ou l'existence. Elle ne constitue pas un inventaire exhaustif de tous les fichiers présents sur le serveur.

Le répertoire config contient les composants partagés, tandis que public contient les pages accessibles par le navigateur.

C'est une séparation utile : les fichiers de configuration et les identifiants MySQL ne doivent pas être directement accessibles depuis le Web.
10. Ce que tu pourras administrer depuis le site central

À terme, compte.partitions.ovh pourra devenir le véritable centre de gestion de ton infrastructure.

Voici les principales fonctionnalités que tu pourras y regrouper :
Fonctionnalité	Objectif
Gestion des utilisateurs	Consulter et administrer les comptes
Gestion des rôles	Définir les droits administratifs
Gestion des services	Ajouter, modifier ou désactiver des services
Gestion des autorisations	Accorder ou retirer les droits d'accès individuels
Authentification centrale	Permettre une connexion unique aux différents sites
Réinitialisation du mot de passe	Récupérer l'accès à un compte par e-mail
Journal d'activité	Suivre les événements importants
Sécurité des connexions	Limiter les tentatives et protéger les sessions

Certaines de ces fonctionnalités disposent déjà de structures de données ou de composants PHP ; d'autres devront encore être intégrées à l'interface d'administration.
Conclusion

Le principe de ton architecture est simple : compte.partitions.ovh devient la source centrale d'identité des utilisateurs et des autorisations d'accès à tes différents sites.

Les comptes et les droits sont stockés dans une base MySQL commune. Les sites clients peuvent s'appuyer sur le mécanisme SSO pour authentifier les utilisateurs, puis vérifier leurs autorisations spécifiques.

Les principaux composants déjà identifiés sont :

    la table utilisateurs pour les comptes ;
    les tables services et acces_utilisateur_services pour les autorisations ;
    la table journal_activite pour les événements ;
    la table tentatives_connexion pour les tentatives d'authentification ;
    la table sso_codes pour les codes temporaires du SSO ;
    les fichiers PHP de connexion, de configuration et de contrôle des accès.

La prochaine priorité technique est de vérifier et de terminer l'implémentation réelle du SSO, puis de finaliser l'administration des utilisateurs et des autorisations. Une fois ces éléments stabilisés, tu pourras ajouter de nouveaux sous-domaines sans devoir recréer un système d'authentification indépendant pour chacun d'eux.