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

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/activity.php';
require_once dirname(__DIR__) . '/config/login_security.php';

// Reprendre une éventuelle demande SSO après connexion.
if (isset($_SESSION['utilisateur_id'])) {    if (isset($_SESSION['sso_pending']) && is_array($_SESSION['ss
o_pending'])) {
        header('Location: /sso/authorize.php');
    } else {
        header('Location: espace.php');
    }
    exit;
}

// Créer un jeton CSRF.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$identifiant = '';
$erreur = '';

function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim(
        (string) ($_POST['identifiant'] ?? '')
    );

    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $erreur = 'Le formulaire a expiré. Veuillez réessayer.';

    } elseif (
        !preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $identifiant)
        || $motDePasse === ''
        || strlen($motDePasse) > 72
    ) {
        $erreur = 'Identifiant ou mot de passe incorrect.';

    } else {
        $adresseIp = obtenirAdresseIpConnexion();

        try {
            // -----------------------------------------
            // 1. VÉRIFIER LA LIMITE DE TENTATIVES
            // -----------------------------------------

            if (
                connexionEstLimitee(
                    $pdo,
                    $identifiant,
                    $adresseIp
                )
            ) {
                $erreur =
                    'Trop de tentatives de connexion. '                    . 'Veuillez patienter 15 minutes avant de rée
ssayer.';

            } else {
                // -------------------------------------
                // 2. RECHERCHER LE COMPTE
                // -------------------------------------

                $requete = $pdo->prepare(
                    'SELECT
                        id,
                        identifiant,
                        email,
                        mot_de_passe,
                        role
                     FROM utilisateurs
                     WHERE identifiant = :identifiant
                     LIMIT 1'
                );

                $requete->execute([
                    'identifiant' => $identifiant,
                ]);

                $utilisateur = $requete->fetch();

                $motDePasseValide = (
                    $utilisateur
                    && password_verify(
                        $motDePasse,
                        $utilisateur['mot_de_passe']
                    )
                );

                // -------------------------------------
                // 3. CONNEXION RÉUSSIE
                // -------------------------------------

                if ($motDePasseValide) {
                    $role = (string) $utilisateur['role'];

                    if (!in_array(
                        $role,
                        ['user', 'moderator', 'admin'],
                        true
                    )) {
                        throw new RuntimeException(
                            'Rôle utilisateur invalide.'
                        );
                    }

                    // Enregistrer la tentative réussie.
                    enregistrerTentativeConnexion(
                        $pdo,
                        $identifiant,
                        $adresseIp,
                        true
                    );

                    // Renouveler l'identifiant de session.
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException(                            'Impossible de renouveler la session.
'
                        );
                    }

                    // Enregistrer les informations du compte.
                    $_SESSION['utilisateur_id'] =
                        (int) $utilisateur['id'];

                    $_SESSION['utilisateur_identifiant'] =
                        $utilisateur['identifiant'];

                    $_SESSION['utilisateur_email'] =
                        $utilisateur['email'];

                    $_SESSION['utilisateur_role'] = $role;

                    $_SESSION['utilisateur_admin'] =
                        ($role === 'admin');

                    // Renouveler le jeton CSRF.
                    $_SESSION['csrf_token'] =
                        bin2hex(random_bytes(32));

                    // Journaliser la connexion.
                    enregistrerActivite(
                        $pdo,
                        'connexion_reussie',
                        (int) $utilisateur['id'],
                        [
                            'role' => $role,
                        ]
                    );
                    if (isset($_SESSION['sso_pending']) && is_arr
ay($_SESSION['sso_pending'])) {
                        header('Location: /sso/authorize.php');
                    } else {
                        header('Location: espace.php');
                    }
                    exit;

                } else {
                    // ---------------------------------
                    // 4. CONNEXION ÉCHOUÉE
                    // ---------------------------------

                    enregistrerTentativeConnexion(
                        $pdo,
                        $identifiant,
                        $adresseIp,
                        false
                    );

                    enregistrerActivite(
                        $pdo,
                        'connexion_echouee',
                        null,
                        [
                            'identifiant' => $identifiant,
                        ]
                    );

                    $erreur =
                        'Identifiant ou mot de passe incorrect.';
                }
            }

        } catch (Throwable $e) {
            error_log(
                'Erreur de connexion : '
                . $e->getMessage()
            );

            http_response_code(500);

            $erreur =
                'Une erreur technique est survenue. '
                . 'Veuillez réessayer plus tard.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">    <meta name="viewport" content="width=device-width, initial-sc
ale=1.0">

    <title>Connexion | Mon compte</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f5f9;
            color: #172033;
        }

        .conteneur {
            width: 100%;
            max-width: 420px;
            padding: 38px;
            background: white;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(20, 35, 65, .08);
        }

        .logo {
            width: 54px;
            height: 54px;
            margin: 0 auto 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: #e8efff;
            color: #315bea;
            font-size: 27px;
            font-weight: bold;
        }

        h1 {
            margin: 0 0 10px;
            text-align: center;
            font-size: 27px;
        }

        .description {
            margin: 0 0 28px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .champ {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9deea;
            border-radius: 9px;
            background: white;
            color: #172033;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #315bea;
            box-shadow: 0 0 0 3px rgba(49, 91, 234, .12);
        }

        .erreur {
            margin-bottom: 20px;
            padding: 12px 14px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 14px;
            line-height: 1.5;
        }

        button {
            width: 100%;
            margin-top: 5px;
            padding: 14px;
            border: none;
            border-radius: 9px;
            background: #315bea;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #2448c5;
        }

        .pied {
            margin-top: 24px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }

        a {
            color: #315bea;
            font-weight: 600;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .conteneur {
                padding: 28px 22px;
            }
        }
    </style>
</head>

<body>

<main class="conteneur">

    <div class="logo" aria-hidden="true">C</div>

    <h1>Bienvenue</h1>

    <p class="description">
        Connectez-vous à votre espace personnel.
    </p>

    <?php if ($erreur !== ''): ?>
        <div class="erreur" role="alert">
            <?= e($erreur) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="connexion.php">

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
                placeholder="Votre identifiant"
                value="<?= e($identifiant) ?>"
                minlength="3"
                maxlength="50"
                pattern="[a-zA-Z0-9_.-]{3,50}"
                autocomplete="username"
                required
                autofocus
            >
        </div>

        <div class="champ">
            <label for="mot_de_passe">Mot de passe</label>

            <input
                type="password"
                id="mot_de_passe"
                name="mot_de_passe"
                placeholder="Votre mot de passe"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit">Se connecter</button>

    </form>

    <div class="pied">
        Vous n'avez pas encore de compte ?
        <a href="inscription.php">Créer un compte</a>
    </div>

</main>

</body>
</html>
