<?php

declare(strict_types=1);
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off
';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once dirname(__DIR__) . '/config/database.php';

if (isset($_SESSION['utilisateur_id'])) {
    header('Location: espace.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$identifiant = '';
$email = '';
$erreurs = [];

function e(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim((string) ($_POST['identifiant'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    $confirmation = (string) ($_POST['confirmation'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    // Protection CSRF
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {        $erreurs[] = 'Le formulaire a expiré. Veuillez réessayer.
';
    }

    // Validation de l'identifiant    if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $identifiant))
{        $erreurs[] = 'L’identifiant doit contenir entre 3 et 50 caractères : lettres sans accent, chiffres, points, tirets ou unde
rscores.';
    }

    // Validation de l'adresse e-mail
    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 255
    ) {        $erreurs[] = 'Veuillez saisir une adresse e-mail valide d
e 255 caractères maximum.';
    }

    // Validation du mot de passe
    if (strlen($motDePasse) < 8) {        $erreurs[] = 'Le mot de passe doit contenir au moins 8 oc
tets.';
    }
    // PASSWORD_DEFAULT utilise actuellement bcrypt dans les vers
ions
    // PHP usuelles : bcrypt ne prend en compte que 72 octets.
    if (strlen($motDePasse) > 72) {        $erreurs[] = 'Le mot de passe ne doit pas dépasser 72 oct
ets.';
    }

    if ($motDePasse !== $confirmation) {
        $erreurs[] = 'Les mots de passe ne correspondent pas.';
    }

    if (empty($erreurs)) {
        try {
            // Vérifier si l'identifiant ou l'e-mail existe déjà.
            $requete = $pdo->prepare(
                'SELECT id
                 FROM utilisateurs
                 WHERE identifiant = :identifiant
                    OR email = :email
                 LIMIT 1'
            );

            $requete->execute([
                'identifiant' => $identifiant,
                'email' => $email,
            ]);

            if ($requete->fetch()) {                $erreurs[] = 'Cet identifiant ou cette adresse e-
mail est déjà utilisé.';
            } else {
                $hachage = password_hash(
                    $motDePasse,
                    PASSWORD_DEFAULT
                );

                $requete = $pdo->prepare(
                    'INSERT INTO utilisateurs
                        (identifiant, email, mot_de_passe)
                     VALUES
                        (:identifiant, :email, :mot_de_passe)'
                );

                $requete->execute([
                    'identifiant' => $identifiant,
                    'email' => $email,
                    'mot_de_passe' => $hachage,
                ]);

                // Redirection après inscription                $_SESSION['csrf_token'] = bin2hex(random_bytes(32
));

                header('Location: inscription.php?succes=1');
                exit;
            }
        } catch (PDOException $e) {            // 23000 correspond notamment à une violation de cont
rainte UNIQUE.
            if ($e->getCode() === '23000') {                $erreurs[] = 'Cet identifiant ou cette adresse e-
mail est déjà utilisé.';
            } else {                error_log('Erreur inscription : ' . $e->getMessag
e());
                http_response_code(500);
                $erreurs[] = 'Une erreur technique est survenue.
Veuillez réessayer plus tard.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">    <meta name="viewport" content="width=device-width, initial-sc
ale=1.0">

    <title>Inscription | Mon compte</title>

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
            max-width: 460px;
            padding: 36px;
            background: white;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(20, 35, 65, .08);
        }

        .logo {
            width: 54px;
            height: 54px;
            margin: 0 auto 20px;
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
            margin: 0 0 26px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .champ {
            margin-bottom: 18px;
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

        .aide {
            display: block;
            margin-top: 7px;
            color: #6b7280;
            font-size: 12px;
            line-height: 1.5;
        }

        .erreurs {
            margin-bottom: 20px;
            padding: 14px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 14px;
            line-height: 1.6;
        }

        .succes {
            padding: 20px;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            background: #f0fdf4;
            color: #166534;
            text-align: center;
            line-height: 1.6;
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
                padding: 26px 21px;
            }
        }
    </style>
</head>

<body>

<main class="conteneur">

    <div class="logo" aria-hidden="true">C</div>

    <h1>Créer un compte</h1>

    <p class="description">
        Choisissez votre identifiant et créez votre compte.
    </p>
    <?php if (isset($_GET['succes']) && $_GET['succes'] === '1'):
 ?>

        <div class="succes" role="status">
            <strong>Inscription réussie !</strong>

            <p>Votre compte a bien été créé.</p>

            <a href="connexion.php">Se connecter →</a>
        </div>

    <?php else: ?>

        <?php if ($erreurs !== []): ?>
            <div class="erreurs" role="alert">
                <ul>
                    <?php foreach ($erreurs as $erreur): ?>
                        <li><?= e($erreur) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="inscription.php">

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
                    autocomplete="username"
                    required
                    autofocus
                >

                <small class="aide">
                    3 à 50 caractères : lettres sans accent,
                    chiffres, points, tirets et underscores.
                </small>
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

            <div class="champ">
                <label for="mot_de_passe">Mot de passe</label>

                <input
                    type="password"
                    id="mot_de_passe"
                    name="mot_de_passe"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >

                <small class="aide">                    Au moins 12 caractères, sans dépasser 72 octe
ts.
                </small>
            </div>

            <div class="champ">
                <label for="confirmation">Confirmer le mot de passe</label>

                <input
                    type="password"
                    id="confirmation"
                    name="confirmation"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit">Créer mon compte</button>

        </form>

        <div class="pied">
            Vous avez déjà un compte ?
            <a href="connexion.php">Se connecter</a>
        </div>

    <?php endif; ?>

</main>

</body>
</html>
