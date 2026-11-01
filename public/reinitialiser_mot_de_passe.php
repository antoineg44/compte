<?php

declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/mail.php';

if (isset($_SESSION['utilisateur_id'])) {
    header('Location: index.php');
    exit;
}

// Protection CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$etape = 'email';
$erreurs = [];
$message = '';

$email = '';
$code = '';

function e(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}

// Récupérer l'étape en cours
if (!empty($_SESSION['reset_email'])) {
    $etape = 'code';
}

if (!empty($_SESSION['reset_code_verified'])) {
    $etape = 'nouveau_mot_de_passe';
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $erreurs[] = 'Le formulaire a expiré. Veuillez réessayer.';
    } else {

        $action = (string) ($_POST['action'] ?? '');

        /*
 * RETOUR À L'ÉTAPE DE SAISIE DE L'EMAIL
 */
if ($action === 'changer_email') {

    unset(
        $_SESSION['reset_email'],
        $_SESSION['reset_code_verified'],
        $_SESSION['reset_user_id'],
        $_SESSION['reset_code_id']
    );

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header('Location: reinitialiser_mot_de_passe.php');
    exit;
}

/*
 * ETAPE 1 : DEMANDE DU CODE
 */
elseif ($action === 'demander_code') {


            $email = strtolower(trim((string) ($_POST['email'] ?? '')));

            if (
                !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 255
            ) {
                $erreurs[] = 'Veuillez saisir une adresse e-mail valide.';
            }

            if (empty($erreurs)) {

                try {

                    $requete = $pdo->prepare(
                        'SELECT id, email
                         FROM utilisateurs
                         WHERE email = :email
                         LIMIT 1'
                    );

                    $requete->execute(['email' => $email]);

                    $utilisateur = $requete->fetch(PDO::FETCH_ASSOC);

                    if ($utilisateur) {

                        $utilisateurId = (int) $utilisateur['id'];

                        // Limitation : un envoi toutes les 60 secondes
                        $requete = $pdo->prepare(
                            'SELECT id
                             FROM reinitialisation_mot_de_passe
                             WHERE utilisateur_id = :id
                               AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                             ORDER BY id DESC
                             LIMIT 1'
                        );

                        $requete->execute(['id' => $utilisateurId]);

                        if (!$requete->fetch()) {

                            // Invalider les anciens codes
                            $requete = $pdo->prepare(
                                'UPDATE reinitialisation_mot_de_passe
                                 SET utilise = 1
                                 WHERE utilisateur_id = :id
                                   AND utilise = 0'
                            );

                            $requete->execute(['id' => $utilisateurId]);

                            // Générer un code à 6 chiffres
                            $codeGenere = (string) random_int(100000, 999999);

                            // Hacher le code
                            $codeHash = password_hash(
                                $codeGenere,
                                PASSWORD_DEFAULT
                            );

                            // Enregistrer le code pendant 10 minutes
                            $requete = $pdo->prepare(
                                'INSERT INTO reinitialisation_mot_de_passe
                                    (utilisateur_id, code_hash, expires_at)
                                 VALUES
                                    (:id, :code_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                            );

                            $requete->execute([
                                'id' => $utilisateurId,
                                'code_hash' => $codeHash,
                            ]);

                            // Envoyer le code par e-mail
                            $sujet = 'Réinitialisation de votre mot de passe';

                            $contenu = '
                                <div style="font-family:Arial,sans-serif;max-width:500px;margin:auto;">
                                    <h2>Réinitialisation du mot de passe</h2>

                                    <p>Vous avez demandé à modifier votre mot de passe.</p>

                                    <p>Voici votre code de vérification :</p>

                                    <div style="
                                        padding:20px;
                                        background:#f3f5f9;
                                        text-align:center;
                                        font-size:32px;
                                        font-weight:bold;
                                        letter-spacing:8px;
                                    ">
                                        ' . e($codeGenere) . '
                                    </div>

                                    <p>Ce code est valable pendant 10 minutes.</p>

                                    <p>
                                        Si vous n\'êtes pas à l\'origine de cette demande,
                                        ignorez cet e-mail.
                                    </p>
                                </div>
                            ';

                            if (!envoyerMail(
                                $email,
                                $sujet,
                                $contenu
                            )) {
                                error_log(
                                    'Impossible d envoyer le code de réinitialisation à : '
                                    . $email
                                );
                            }

                        }

                    }

                    // Ne pas révéler si l'adresse existe ou non
                    $_SESSION['reset_email'] = $email;

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: reinitialiser_mot_de_passe.php?etape=code');
                    exit;

                } catch (PDOException $e) {

                    error_log('Erreur demande réinitialisation : ' . $e->getMessage());

                    $erreurs[] = 'Une erreur technique est survenue.';
                }
            }
        }

        /*
         * ETAPE 2 : VERIFICATION DU CODE
         */
        elseif ($action === 'verifier_code') {

            $code = trim((string) ($_POST['code'] ?? ''));

            if (empty($_SESSION['reset_email'])) {

                $erreurs[] = 'Votre session a expiré.';

            } elseif (!preg_match('/\A[0-9]{6}\z/', $code)) {

                $erreurs[] = 'Veuillez saisir un code valide à 6 chiffres.';

            } else {

                try {

                    $requete = $pdo->prepare(
                        'SELECT
                            r.id,
                            r.code_hash,
                            r.tentatives,
                            r.expires_at,
                            u.id AS utilisateur_id
                         FROM reinitialisation_mot_de_passe r
                         INNER JOIN utilisateurs u
                            ON u.id = r.utilisateur_id
                         WHERE u.email = :email
                           AND r.utilise = 0
                           AND r.expires_at > NOW()
                         ORDER BY r.id DESC
                         LIMIT 1'
                    );

                    $requete->execute([
                        'email' => $_SESSION['reset_email'],
                    ]);

                    $verification = $requete->fetch(PDO::FETCH_ASSOC);

                    if (!$verification) {

                        $erreurs[] = 'Code invalide ou expiré.';

                    } elseif ((int) $verification['tentatives'] >= 5) {

                        $erreurs[] = 'Nombre maximum de tentatives atteint. Demandez un nouveau code.';

                    } else {

                        // Incrémenter le nombre de tentatives
                        $requete = $pdo->prepare(
                            'UPDATE reinitialisation_mot_de_passe
                             SET tentatives = tentatives + 1
                             WHERE id = :id'
                        );

                        $requete->execute([
                            'id' => $verification['id'],
                        ]);

                        if (password_verify(
                            $code,
                            $verification['code_hash']
                        )) {

                            $_SESSION['reset_code_verified'] = true;

                            $_SESSION['reset_user_id'] =
                                (int) $verification['utilisateur_id'];

                            $_SESSION['reset_code_id'] =
                                (int) $verification['id'];

                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                            header('Location: reinitialiser_mot_de_passe.php?etape=nouveau');
                            exit;

                        } else {

                            $erreurs[] = 'Le code saisi est incorrect.';
                        }
                    }

                } catch (PDOException $e) {

                    error_log('Erreur vérification code : ' . $e->getMessage());

                    $erreurs[] = 'Une erreur technique est survenue.';
                }
            }
        }

        /*
         * ETAPE 3 : MODIFICATION DU MOT DE PASSE
         */
        elseif ($action === 'changer_mot_de_passe') {

            $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
            $confirmation = (string) ($_POST['confirmation'] ?? '');

            if (
                empty($_SESSION['reset_code_verified'])
                || empty($_SESSION['reset_user_id'])
                || empty($_SESSION['reset_code_id'])
            ) {

                $erreurs[] = 'Veuillez recommencer la procédure.';

            } elseif (strlen($motDePasse) < 8) {

                $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';

            } elseif (strlen($motDePasse) > 72) {

                $erreurs[] = 'Le mot de passe ne doit pas dépasser 72 octets.';

            } elseif ($motDePasse !== $confirmation) {

                $erreurs[] = 'Les mots de passe ne correspondent pas.';

            } else {

                try {

                    $pdo->beginTransaction();

                    // Vérifier que le code est toujours valide
                    $requete = $pdo->prepare(
                        'SELECT id
                         FROM reinitialisation_mot_de_passe
                         WHERE id = :code_id
                           AND utilisateur_id = :utilisateur_id
                           AND utilise = 0
                           AND tentatives <= 5
                           AND expires_at > NOW()
                         FOR UPDATE'
                    );

                    $requete->execute([
                        'code_id' => $_SESSION['reset_code_id'],
                        'utilisateur_id' => $_SESSION['reset_user_id'],
                    ]);

                    if (!$requete->fetch()) {

                        $pdo->rollBack();

                        $erreurs[] = 'Votre code a expiré. Veuillez recommencer.';

                    } else {

                        // Hacher le nouveau mot de passe
                        $hachage = password_hash(
                            $motDePasse,
                            PASSWORD_DEFAULT
                        );

                        // Mettre à jour le mot de passe
                        $requete = $pdo->prepare(
                            'UPDATE utilisateurs
                             SET mot_de_passe = :mot_de_passe
                             WHERE id = :id'
                        );

                        $requete->execute([
                            'mot_de_passe' => $hachage,
                            'id' => $_SESSION['reset_user_id'],
                        ]);

                        // Invalider tous les codes de cet utilisateur
                        $requete = $pdo->prepare(
                            'UPDATE reinitialisation_mot_de_passe
                             SET utilise = 1
                             WHERE utilisateur_id = :id'
                        );

                        $requete->execute([
                            'id' => $_SESSION['reset_user_id'],
                        ]);

                        $pdo->commit();

                        // Nettoyer la session de réinitialisation
                        unset(
                            $_SESSION['reset_email'],
                            $_SESSION['reset_code_verified'],
                            $_SESSION['reset_user_id'],
                            $_SESSION['reset_code_id']
                        );

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        header('Location: connexion.php?mot_de_passe_modifie=1');
                        exit;
                    }

                } catch (PDOException $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Erreur changement mot de passe : ' . $e->getMessage());

                    $erreurs[] = 'Une erreur technique est survenue.';
                }
            }
        }
    }
}

// Déterminer l'étape à afficher
if (!empty($_SESSION['reset_code_verified'])) {
    $etape = 'nouveau_mot_de_passe';
} elseif (!empty($_SESSION['reset_email'])) {
    $etape = 'code';
} else {
    $etape = 'email';
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Réinitialiser mon mot de passe</title>

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

        h1 {
            margin: 0 0 12px;
            text-align: center;
            font-size: 25px;
        }

        .description {
            margin-bottom: 26px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
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
            padding: 14px;
            border: 1px solid #d9deea;
            border-radius: 9px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #315bea;
            box-shadow: 0 0 0 3px rgba(49, 91, 234, .12);
        }

        button {
            width: 100%;
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

        .aide {
            display: block;
            margin-top: 8px;
            color: #6b7280;
            font-size: 12px;
        }

        .pied {
            margin-top: 24px;
            text-align: center;
            font-size: 14px;
        }

        a {
            color: #315bea;
            text-decoration: none;
            font-weight: 600;
        }

        .code {
            text-align: center;
            font-size: 25px;
            letter-spacing: 8px;
            font-weight: bold;
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

    <h1>Réinitialiser mon mot de passe</h1>

    <p class="description">
        <?php if ($etape === 'email'): ?>
            Saisissez votre adresse e-mail pour recevoir un code de vérification.
        <?php elseif ($etape === 'code'): ?>
            Saisissez le code à 6 chiffres envoyé à votre adresse e-mail.
        <?php else: ?>
            Choisissez votre nouveau mot de passe.
        <?php endif; ?>
    </p>

    <?php if ($erreurs !== []): ?>
        <div class="erreurs" role="alert">
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= e($erreur) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <?php if ($etape === 'email'): ?>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="demander_code">

            <div class="champ">
                <label for="email">Adresse e-mail</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    required
                    autofocus
                    autocomplete="email"
                >
            </div>

            <button type="submit">
                Envoyer le code
            </button>

        </form>


    <?php elseif ($etape === 'code'): ?>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="verifier_code">

            <div class="champ">
                <label for="code">Code de vérification</label>

                <input
                    type="text"
                    id="code"
                    name="code"
                    class="code"
                    maxlength="6"
                    pattern="[0-9]{6}"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                >

                <small class="aide">
                    Le code est valable pendant 10 minutes.
                </small>
            </div>

            <button type="submit">
                Vérifier le code
            </button>

        </form>
<form method="POST" style="margin-top: 12px;">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($_SESSION['csrf_token']) ?>"
    >

    <input
        type="hidden"
        name="action"
        value="changer_email"
    >

    <button
        type="submit"
        style="background:#6b7280;"
    >
        ← Changer d'adresse e-mail
    </button>

</form>
<form method="POST" style="margin-top: 12px;">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($_SESSION['csrf_token']) ?>"
    >

    <input
        type="hidden"
        name="action"
        value="renvoyer_code"
    >

    <button
        type="submit"
        style="background:#16a34a;"
    >
        Renvoyer le code
    </button>

</form>


    <?php else: ?>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="changer_mot_de_passe">

            <div class="champ">
                <label for="mot_de_passe">Nouveau mot de passe</label>

                <input
                    type="password"
                    id="mot_de_passe"
                    name="mot_de_passe"
                    minlength="8"
                    autocomplete="new-password"
                    required
                    autofocus
                >

                <small class="aide">
                    Au moins 8 caractères, maximum 72 octets.
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

            <button type="submit">
                Modifier mon mot de passe
            </button>

        </form>

    <?php endif; ?>


    <div class="pied">
        <a href="connexion.php">← Retour à la connexion</a>
    </div>

</main>

</body>
</html>
