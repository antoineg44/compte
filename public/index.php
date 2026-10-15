<?php

declare(strict_types=1);

/*|----------------------------------------------------------------
----------
| Configuration de la session|----------------------------------------------------------------
----------
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

/*|----------------------------------------------------------------
----------
| Connexion à la base de données|----------------------------------------------------------------
----------
*/

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/services.php';

/*|----------------------------------------------------------------
----------
| Vérification de la connexion|----------------------------------------------------------------
----------
*/

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

/*|----------------------------------------------------------------
----------
| Protection CSRF pour la déconnexion|----------------------------------------------------------------
----------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*|----------------------------------------------------------------
----------
| Fonction d'échappement HTML|----------------------------------------------------------------
----------
*/

function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*|----------------------------------------------------------------
----------
| Informations de l'utilisateur|----------------------------------------------------------------
----------
*/

$id = (int) $_SESSION['utilisateur_id'];

$identifiant = (string) (
    $_SESSION['utilisateur_identifiant'] ?? ''
);

$email = (string) (
    $_SESSION['utilisateur_email'] ?? ''
);

$role = (string) (
    $_SESSION['utilisateur_role'] ?? 'user'
);

/*|----------------------------------------------------------------
----------
| Récupération des services autorisés|----------------------------------------------------------------
----------
|
| Seuls les services actifs pour lesquels l'utilisateur possède
| une autorisation explicite sont affichés.
|
*/

$services = [];

$erreurServices = false;

try {
    $requete = $pdo->prepare(
        'SELECT
            s.id,
            s.nom,
            s.slug,
            s.description
         FROM services s
         INNER JOIN acces_utilisateur_services a
             ON a.service_id = s.id
         WHERE a.utilisateur_id = :utilisateur_id
           AND a.autorise = 1
           AND s.actif = 1
         ORDER BY s.nom ASC'
    );

    $requete->execute([
        'utilisateur_id' => $id,
    ]);

    $services = $requete->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log(
        'Erreur de chargement des services : '
        . $exception->getMessage()
    );

    $erreurServices = true;
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Mon espace personnel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 20px 6%;
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }

        header strong {
            font-size: 20px;
        }

        .bouton-deconnexion {
            padding: 10px 15px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: white;
            color: #dc2626;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .bouton-deconnexion:hover {
            background: #fef2f2;
        }

        main {
            width: min(1000px, 90%);
            margin: 40px auto;
        }

        .carte {
            margin-bottom: 25px;
            padding: 30px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(20, 35, 65, .04);
        }

        h1 {
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 28px;
        }

        h2 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 21px;
        }

        .description-generale {
            color: #6b7280;
            line-height: 1.6;
        }

        .statut {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 6px;
            background: #dcfce7;
            color: #166534;
            font-size: 13px;
            font-weight: 600;
        }

        .information {
            margin-top: 25px;
            padding: 18px;
            background: #f8fafc;
            border-radius: 10px;
            line-height: 2;
            overflow-wrap: anywhere;
        }

        .liste-services {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 25px;
        }

        .service {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 23px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            transition:
                border-color .2s,
                box-shadow .2s,
                transform .2s;
        }

        .service:hover {
            border-color: #c7d2fe;
            box-shadow: 0 8px 24px rgba(49, 91, 234, .07);
            transform: translateY(-2px);
        }

        .service-icone {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            margin-bottom: 18px;
            border-radius: 12px;
            background: #e8efff;
            color: #315bea;
            font-size: 22px;
            font-weight: 700;
        }

        .service h3 {
            margin: 0 0 10px;
            font-size: 18px;
        }

        .service p {
            flex: 1;
            margin: 0 0 20px;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .bouton-service {
            display: inline-block;
            padding: 11px 15px;
            border-radius: 8px;
            background: #315bea;
            color: white;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .bouton-service:hover {
            background: #2448c5;
        }

        .message-vide {
            margin-top: 22px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f8fafc;
            color: #6b7280;
            line-height: 1.7;
        }

        .message-erreur {
            padding: 16px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            background: #fef2f2;
            color: #991b1b;
            line-height: 1.6;
        }

        .pied {
            margin-top: 30px;
            color: #9ca3af;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 650px) {
            header {
                padding: 16px 5%;
            }

            header strong {
                font-size: 18px;
            }

            main {
                width: 92%;
                margin: 25px auto;
            }

            .carte {
                padding: 22px;
            }

            .liste-services {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 24px;
            }
        }

        .bouton-admin {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid #c7d2fe;
            border-radius: 8px;
            background: #315bea;
            color: white;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background .2s;
        }
        
        .bouton-admin:hover {
            background: #2448c5;
        }
        
        .actions-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>

<body>

<header>

    <strong>Mon compte</strong>

    <div class="actions-header">

        <?php if (($_SESSION['utilisateur_admin'] ?? false) === true): ?>

            <a
                class="bouton-admin"
                href="./admin"
            >
                Administration
            </a>

        <?php endif; ?>

        <form method="POST" action="deconnexion.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <button
                class="bouton-deconnexion"
                type="submit"
            >
                Se déconnecter
            </button>

        </form>

    </div>

</header>

<main>

    <!-- Présentation du compte -->

    <section class="carte">

        <span class="statut">Connecté</span>

        <h1>
            Bonjour <?= e($identifiant) ?> !
        </h1>

        <p class="description-generale">
            Bienvenue dans votre espace personnel.
            Retrouvez ci-dessous les services auxquels
            vous êtes autorisé à accéder.
        </p>

        <div class="information">

            <strong>Vos informations</strong><br>

            Identifiant : <?= e($identifiant) ?><br>

            Adresse e-mail : <?= e($email) ?><br>

            Numéro de compte : <?= $id ?>

        </div>

    </section>

    <!-- Services accessibles -->

    <section class="carte">

        <h2>Mes services</h2>

        <p class="description-generale">
            Les services affichés dépendent des autorisations
            attribuées à votre compte.
        </p>

        <?php if ($erreurServices): ?>

            <div class="message-erreur">
                Impossible de charger les services pour le moment
.
                Veuillez réessayer ultérieurement.

            </div>

        <?php elseif ($services === []): ?>

            <div class="message-vide">

                <strong>Aucun service disponible</strong>

                <p>
                    Aucun service ne vous a encore été attribué,
                    ou les services disponibles sont désactivés.
                </p>

                <p>
                    Veuillez contacter un administrateur                    pour demander l'activation des accès nécessai
res.
                </p>

            </div>

        <?php else: ?>

            <div class="liste-services">

                <?php foreach ($services as $service): ?>

                    <article class="service">

                        <div
                            class="service-icone"
                            aria-hidden="true"
                        >
                            <?= e(
                                strtoupper(
                                    substr($service['nom'], 0, 1)
                                )
                            ) ?>
                        </div>

                        <h3>
                            <?= e($service['nom']) ?>
                        </h3>

                        <p>
                            <?= e(
                                $service['description']
                                ?? 'Accédez à ce service depuis votre espace personnel.'
                            ) ?>
                        </p>

                        <a
                            class="bouton-service"
                            href="service.php?slug=<?= rawurlencode($service['slug']) ?>"
                        >
                            Accéder au service
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    <div class="pied">
        Mon compte &mdash; Espace personnel
    </div>

</main>

</body>
</html>
