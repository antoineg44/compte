<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 2) . '/config/activity.php';

$id = filter_var(
    $_GET['id'] ?? $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($id === false || $id === null) {
    http_response_code(400);
    exit('Identifiant utilisateur invalide.');
}

try {
    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute(['id' => $id]);

    $utilisateur = $requete->fetch();

} catch (PDOException $e) {    error_log('Erreur de lecture utilisateur : ' . $e->getMessage
());

    http_response_code(500);
    exit('Impossible de charger cet utilisateur.');
}

if (!$utilisateur) {
    http_response_code(404);
    exit('Utilisateur introuvable.');
}

$identifiant = $utilisateur['identifiant'];
$email = $utilisateur['email'];
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    $identifiant = trim(
        (string) ($_POST['identifiant'] ?? '')
    );

    $email = trim(
        (string) ($_POST['email'] ?? '')
    );

    if (!verifierCsrf($csrfToken)) {
        http_response_code(403);
        exit('Requête invalide. Recharge la page.');
    }
    if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $identifiant))
{        $erreur = 'L’identifiant doit contenir entre 3 et 50 caractères : lettres, chiffres, points, tirets ou tirets bas.';
    } elseif (
        strlen($email) > 255
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $erreur = 'L’adresse e-mail est invalide.';
    } else {
        try {
            $pdo->beginTransaction();

            // Verrouiller le compte pendant la modification.
            $requete = $pdo->prepare(
                'SELECT id, identifiant, email
                 FROM utilisateurs
                 WHERE id = :id
                 FOR UPDATE'
            );

            $requete->execute(['id' => $id]);

            $ancienUtilisateur = $requete->fetch();

            if (!$ancienUtilisateur) {
                throw new RuntimeException(
                    'Cet utilisateur n’existe plus.'
                );
            }
            // Vérifier que l'identifiant n'est pas utilisé ailleurs.
            $requete = $pdo->prepare(
                'SELECT id
                 FROM utilisateurs
                 WHERE identifiant = :identifiant
                   AND id <> :id
                 LIMIT 1'
            );

            $requete->execute([
                'identifiant' => $identifiant,
                'id' => $id,
            ]);

            if ($requete->fetch()) {
                throw new DomainException(
                    'Cet identifiant est déjà utilisé.'
                );
            }

            // Vérifier que l'e-mail n'est pas utilisé ailleurs.
            $requete = $pdo->prepare(
                'SELECT id
                 FROM utilisateurs
                 WHERE email = :email
                   AND id <> :id
                 LIMIT 1'
            );

            $requete->execute([
                'email' => $email,
                'id' => $id,
            ]);

            if ($requete->fetch()) {
                throw new DomainException(
                    'Cette adresse e-mail est déjà utilisée.'
                );
            }

            $requete = $pdo->prepare(
                'UPDATE utilisateurs
                 SET identifiant = :identifiant,
                     email = :email
                 WHERE id = :id'
            );

            $requete->execute([
                'identifiant' => $identifiant,
                'email' => $email,
                'id' => $id,
            ]);

            enregistrerActivite(
                $pdo,
                'utilisateur_modifie',
                (int) $_SESSION['utilisateur_id'],
                [
                    'utilisateur_cible' => (int) $id,
                    'ancien_identifiant' =>
                        $ancienUtilisateur['identifiant'],
                    'nouvel_identifiant' => $identifiant,                    'ancien_email' => $ancienUtilisateur['email'],
                    'nouvel_email' => $email,
                ]
            );

            $pdo->commit();

            // Actualiser la session si l'administrateur
            // modifie son propre compte.
            if (
                (int) $id === (int) $_SESSION['utilisateur_id']
            ) {                $_SESSION['utilisateur_identifiant'] = $identifiant;
                $_SESSION['utilisateur_email'] = $email;
            }

            header('Location: utilisateurs.php?modifie=1');
            exit;

        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erreur = $e->getMessage();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Erreur de modification utilisateur : '
                . $e->getMessage()
            );

            $erreur = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Une erreur technique est survenue.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">    <meta name="viewport" content="width=device-width, initial-sc
ale=1.0">

    <title>Modifier un utilisateur | Administration</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        main {
            max-width: 600px;
            margin: 30px auto;
            padding: 30px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        h1 {
            margin-top: 0;
        }

        .description {
            color: #6b7280;
            line-height: 1.5;
        }

        .champ {
            margin: 22px 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #315bea;
            outline-offset: 1px;
        }

        .erreur {
            padding: 13px;
            margin: 20px 0;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            color: #b91c1c;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .bouton {
            display: inline-block;
            padding: 12px 16px;
            border: 0;
            border-radius: 8px;
            background: #315bea;
            color: white;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .role {
            margin-top: 20px;
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>

<body>

<main>

    <h1>Modifier un utilisateur</h1>

    <p class="description">
        Modifie les informations du compte
        #<?= (int) $utilisateur['id'] ?>.
    </p>

    <?php if ($erreur !== ''): ?>
        <div class="erreur" role="alert">
            <?= e($erreur) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="modifier-utilisateur.php">

        <input
            type="hidden"
            name="id"
            value="<?= (int) $id ?>"
        >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <div class="champ">
            <label for="identifiant">Identifiant</label>

            <input
                type="text"
                id="identifiant"
                name="identifiant"
                value="<?= e($identifiant) ?>"
                minlength="3"
                maxlength="50"
                pattern="[a-zA-Z0-9_.-]{3,50}"
                autocomplete="off"
                required
            >
        </div>

        <div class="champ">
            <label for="email">Adresse e-mail</label>

            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($email) ?>"
                maxlength="255"
                autocomplete="email"
                required
            >
        </div>

        <p class="role">
            Rôle actuel :
            <?= e((string) $utilisateur['role']) ?>.
            Cette page ne modifie pas les autorisations.
        </p>

        <div class="actions">
            <button class="bouton" type="submit">
                Enregistrer les modifications
            </button>

            <a class="bouton secondaire" href="utilisateurs.php">
                Annuler
            </a>
        </div>

    </form>

</main>

</body>
</html>
