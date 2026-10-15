<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 2) . '/config/activity.php';

$message = '';
$erreur = '';

/*
 * Déterminer l'utilisateur à gérer.
 */
$utilisateurId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurId = $_POST['utilisateur_id'] ?? null;
} else {
    $valeurId = $_GET['utilisateur_id'] ?? null;
}

if ($valeurId !== null) {
    $idValide = filter_var(
        $valeurId,
        FILTER_VALIDATE_INT
    );

    if ($idValide === false || $idValide < 1) {
        http_response_code(400);
        exit('Identifiant utilisateur invalide.');
    }

    $utilisateurId = $idValide;
}

/*
 * Enregistrer les autorisations.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!verifierCsrf($csrf)) {
        $erreur = 'Le formulaire a expiré. Veuillez réessayer.';

    } elseif ($action !== 'enregistrer') {
        $erreur = 'Action inconnue.';

    } else {
        try {
            $pdo->beginTransaction();

            // Vérifier que l'utilisateur existe.
            $requete = $pdo->prepare(
                'SELECT id, identifiant, email, role
                 FROM utilisateurs
                 WHERE id = :id
                 LIMIT 1'
            );

            $requete->execute([
                'id' => $utilisateurId,
            ]);

            $utilisateur = $requete->fetch();

            if (!$utilisateur) {
                throw new InvalidArgumentException(
                    'Cet utilisateur n’existe pas.'
                );
            }

            // Récupérer les services existants.
            $services = $pdo->query(
                'SELECT id, nom, slug
                 FROM services
                 ORDER BY nom ASC'
            )->fetchAll();

            $idsServicesExistants = array_map(
                static fn(array $service): int =>
                    (int) $service['id'],
                $services
            );

            $servicesSelectionnes = $_POST['services'] ?? [];

            if (!is_array($servicesSelectionnes)) {
                throw new InvalidArgumentException(
                    'La liste des services est invalide.'
                );
            }

            $idsSelectionnes = [];

            foreach ($servicesSelectionnes as $id) {
                $idValide = filter_var(
                    $id,
                    FILTER_VALIDATE_INT
                );

                if (
                    $idValide === false
                    || $idValide < 1
                    || !in_array(
                        $idValide,
                        $idsServicesExistants,
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Un service sélectionné est invalide.'
                    );
                }

                $idsSelectionnes[] = $idValide;
            }

            $idsSelectionnes = array_values(
                array_unique($idsSelectionnes)
            );

            // Une ligne est enregistrée pour chaque service.
            // Une case cochée signifie autorise = 1.
            // Une case décochée signifie autorise = 0.
            $requete = $pdo->prepare(
                'INSERT INTO acces_utilisateur_services
                    (
                        utilisateur_id,
                        service_id,
                        autorise,
                        modifie_par
                    )
                 VALUES
                    (
                        :utilisateur_id,
                        :service_id,
                        :autorise,
                        :modifie_par
                    )
                 ON DUPLICATE KEY UPDATE
                    autorise = :autorise_update,
                    modifie_par = :modifie_par_update'
            );

            foreach ($services as $service) {
                $serviceId = (int) $service['id'];

                $autorise = in_array(
                    $serviceId,
                    $idsSelectionnes,
                    true
                ) ? 1 : 0;

                $requete->execute([
                    'utilisateur_id' => $utilisateurId,
                    'service_id' => $serviceId,
                    'autorise' => $autorise,                    'modifie_par' => (int) $_SESSION['utilisateur_id'],
                    'autorise_update' => $autorise,                    'modifie_par_update' => (int) $_SESSION['utilisateur_id'],
                ]);
            }

            $pdo->commit();

            enregistrerActivite(
                $pdo,
                'acces_services_modifies',
                (int) $_SESSION['utilisateur_id'],
                [
                    'utilisateur_cible' => $utilisateurId,
                    'nombre_services_autorises' => count(
                        $idsSelectionnes
                    ),
                ]
            );
            // Éviter de renvoyer le formulaire lors d'un rafraîchissement.
            header(
                'Location: acces-services.php?utilisateur_id='
                . $utilisateurId
                . '&enregistre=1'
            );

            exit;

        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erreur = $e->getMessage();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Erreur de gestion des accès aux services : '
                . $e->getMessage()
            );

            $erreur =
                'Une erreur technique est survenue. '
                . 'Aucune modification n’a été confirmée.';
        }
    }
}

/*
 * Afficher le message de confirmation après redirection.
 */
if (isset($_GET['enregistre']) && $_GET['enregistre'] === '1') {
    $message = 'Les autorisations ont été enregistrées.';
}

/*
 * Récupérer les utilisateurs.
 */
$utilisateurs = $pdo->query(
    'SELECT id, identifiant, email, role
     FROM utilisateurs
     ORDER BY identifiant ASC'
)->fetchAll();

/*
 * Si aucun utilisateur n'est sélectionné,
 * afficher la liste des comptes.
 */
$utilisateur = null;
$services = [];
$accesAutorises = [];

if ($utilisateurId !== null) {
    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute([
        'id' => $utilisateurId,
    ]);

    $utilisateur = $requete->fetch();

    if (!$utilisateur) {
        http_response_code(404);
        exit('Utilisateur introuvable.');
    }

    $services = $pdo->query(
        'SELECT id, nom, slug, description, actif
         FROM services
         ORDER BY nom ASC'
    )->fetchAll();

    $requete = $pdo->prepare(
        'SELECT service_id
         FROM acces_utilisateur_services
         WHERE utilisateur_id = :id
           AND autorise = 1'
    );

    $requete->execute([
        'id' => $utilisateurId,
    ]);

    $accesAutorises = array_map(
        'intval',
        $requete->fetchAll(PDO::FETCH_COLUMN)
    );
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

    <title>Gestion des accès | Administration</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        main {
            width: min(1000px, 100%);
            margin: 0 auto;
        }

        h1 {
            margin-bottom: 8px;
        }

        h2 {
            font-size: 20px;
        }

        .description {
            color: #6b7280;
            line-height: 1.6;
        }

        .navigation {
            margin-bottom: 25px;
        }

        .navigation a {
            margin-right: 15px;
            color: #315bea;
            text-decoration: none;
        }

        .section {
            margin-bottom: 25px;
            padding: 25px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .alerte {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 8px;
        }

        .succes {
            background: #dcfce7;
            color: #166534;
        }

        .danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .liste-utilisateurs {
            display: flex;
            flex-direction: column;
        }

        .utilisateur {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 16px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .utilisateur:last-child {
            border-bottom: none;
        }

        .utilisateur-nom {
            font-weight: 700;
        }

        .utilisateur-email {
            margin-top: 5px;
            color: #6b7280;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .role {
            display: inline-block;
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
        }

        .service {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 18px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .service:last-child {
            border-bottom: none;
        }

        .service input {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            accent-color: #315bea;
        }

        .service label {
            flex: 1;
            cursor: pointer;
        }

        .service-nom {
            margin-bottom: 6px;
            font-weight: 700;
        }

        .service-description {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.5;
        }

        .statut {
            display: inline-block;
            margin-top: 7px;
            font-size: 12px;
            font-weight: 600;
        }

        .actif {
            color: #15803d;
        }

        .inactif {
            color: #b91c1c;
        }

        .bouton {
            display: inline-block;
            padding: 11px 15px;
            border: none;
            border-radius: 8px;
            background: #315bea;
            color: white;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .bouton-secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .section {
                padding: 17px;
            }

            .utilisateur {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<main>

    <nav class="navigation">
        <a href="index.php">Tableau de bord</a>
        <a href="utilisateurs.php">Utilisateurs</a>
        <a href="services.php">Services</a>
    </nav>

    <h1>Gestion des accès aux services</h1>

    <p class="description">
        Choisis les services accessibles à chaque utilisateur.
        Les autorisations sont vérifiées côté serveur.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alerte succes">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($erreur !== ''): ?>
        <div class="alerte danger">
            <?= e($erreur) ?>
        </div>
    <?php endif; ?>

    <?php if ($utilisateur === null): ?>

        <section class="section">
            <h2>Choisir un utilisateur</h2>

            <?php if ($utilisateurs === []): ?>

                <p>Aucun utilisateur enregistré.</p>

            <?php else: ?>

                <div class="liste-utilisateurs">

                    <?php foreach ($utilisateurs as $compte): ?>

                        <article class="utilisateur">

                            <div>
                                <div class="utilisateur-nom">                                    <?= e($compte['identifiant']) ?>
                                </div>

                                <div class="utilisateur-email">
                                    <?= e($compte['email']) ?>
                                </div>

                                <div class="role">
                                    Rôle :
                                    <?= e($compte['role']) ?>
                                </div>
                            </div>

                            <a
                                class="bouton"                                href="acces-services.php?utilisateur_id=<?= (int) $compte['id'] ?>"
                            >
                                Gérer les accès
                            </a>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>
        </section>

    <?php else: ?>

        <section class="section">

            <h2>
                Autorisations de
                <?= e($utilisateur['identifiant']) ?>
            </h2>

            <p class="description">
                Adresse e-mail :
                <?= e($utilisateur['email']) ?>
                <br>
                Rôle :
                <?= e($utilisateur['role']) ?>
            </p>

            <?php if ($services === []): ?>

                <p>
                    Aucun service n'a été créé.
                    Commence par en ajouter dans la                    <a href="services.php">gestion des services</a>.
                </p>

            <?php else: ?>

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="enregistrer"
                    >

                    <input
                        type="hidden"
                        name="utilisateur_id"
                        value="<?= (int) $utilisateur['id'] ?>"
                    >

                    <?php foreach ($services as $service): ?>

                        <?php
                        $serviceId = (int) $service['id'];

                        $autorise = in_array(
                            $serviceId,
                            $accesAutorises,
                            true
                        );
                        $serviceActif = (int) $service['actif'] === 1;
                        ?>

                        <div class="service">

                            <input
                                type="checkbox"
                                id="service-<?= $serviceId ?>"
                                name="services[]"
                                value="<?= $serviceId ?>"
                                <?= $autorise ? 'checked' : '' ?>
                            >
                            <label for="service-<?= $serviceId ?>">

                                <div class="service-nom">
                                    <?= e($service['nom']) ?>
                                </div>

                                <div class="service-description">
                                    <?= e(                                        (string) ($service['description'] ?? '')
                                    ) ?>
                                    <br>
                                    Identifiant :                                    <code><?= e($service['slug']) ?></code>
                                </div>
                                <span class="statut <?= $serviceActif
                                    ? 'actif'
                                    : 'inactif' ?>">
                                    <?= $serviceActif
                                        ? 'Service actif'                                        : 'Service désactivé globalement' ?>
                                </span>

                            </label>

                        </div>

                    <?php endforeach; ?>

                    <div class="actions">

                        <button
                            class="bouton"
                            type="submit"
                        >
                            Enregistrer les autorisations
                        </button>

                        <a
                            class="bouton bouton-secondaire"
                            href="acces-services.php"
                        >
                            Changer d'utilisateur
                        </a>

                    </div>

                </form>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

</body>
</html>
