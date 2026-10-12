<?php
declare(strict_types=1);

session_start();

$config = require '/var/www/compte/config/config.php';
require_once '/var/www/compte/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

if (!extension_loaded('ldap')) {
    http_response_code(500);
    exit('L’extension LDAP de PHP est indisponible.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Requête invalide.');
    }

    $uid = trim((string)($_POST['uid'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (
        $uid === '' ||
        $password === '' ||
        strlen($uid) > 128 ||
        strlen($password) > 1024
    ) {
        $error = 'Identifiant ou mot de passe invalide.';
    } else {
        $ldap = ldap_connect(
            $config['ldap']['host'],
            (int)$config['ldap']['port']
        );

        if ($ldap === false) {
            $error = 'Le service d’authentification est indisponible.';
        } else {
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

            // Recherche anonyme limitée à uid et entry.
            $search = @ldap_search(
                $ldap,
                $config['ldap']['people_dn'],
                '(uid=' . ldap_escape($uid, '', LDAP_ESCAPE_FILTER) . ')',
                ['uid', 'mail', 'cn']
            );

            $entries = $search !== false
                ? ldap_get_entries($ldap, $search)
                : ['count' => 0];

            if (($entries['count'] ?? 0) !== 1) {
                $error = 'Identifiant ou mot de passe incorrect.';
            } else {
                $entry = $entries[0];
                $dn = $entry['dn'] ?? '';
                $mail = $entry['mail'][0] ?? '';

                // Vérification du mot de passe LDAP sous l'identité de l'utilisateur.
                $authenticated = $dn !== ''
                    && @ldap_bind($ldap, $dn, $password);

                if (!$authenticated) {
                    $error = 'Identifiant ou mot de passe incorrect.';
              } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Aucune adresse e-mail valide n’est associée à ce compte. Contactez l’administrateur.';
                } else {
                    // Ne conserve pas le mot de passe en session.
                    session_regenerate_id(true);

                    $_SESSION['pending_uid'] = $uid;
                    $_SESSION['pending_dn'] = $dn;
                    $_SESSION['pending_mail'] = $mail;
                    $_SESSION['pending_expiry'] = time() + 600;
                    $_SESSION['pending_attempts'] = 0;

                    $code = (string)random_int(100000, 999999);

                    $_SESSION['verification_hash'] = password_hash(
                        $code,
                        PASSWORD_DEFAULT
                    );

                    try {
                        $mailClient = new PHPMailer(true);
                        $mailClient->isSMTP();
                        $mailClient->Host = $config['smtp']['host'];
                        $mailClient->SMTPAuth = true;
                        $mailClient->Username = $config['smtp']['username'];
                        $mailClient->Password = $config['smtp']['password'];
                        $mailClient->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mailClient->Port = (int)$config['smtp']['port'];
                        $mailClient->CharSet = 'UTF-8';

                        $mailClient->setFrom(
                            $config['smtp']['from_email'],
                            $config['smtp']['from_name']
                        );

                        $mailClient->addAddress($mail, $uid);
                        $mailClient->isHTML(false);
                        $mailClient->Subject = 'Votre code de vérification';
                        $mailClient->Body =
                            "Bonjour,\n\n" .
                            "Votre code de vérification est : {$code}\n\n" .
                            "Ce code expire dans 10 minutes. " .
                            "Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.";

                        $mailClient->send();

                        unset($_SESSION['csrf']);
                        header('Location: verification.php');
                        exit;
                    } catch (Throwable $e) {
                        unset(
                            $_SESSION['pending_uid'],
                            $_SESSION['pending_dn'],
                            $_SESSION['pending_mail'],
                            $_SESSION['pending_expiry'],
                            $_SESSION['pending_attempts'],
                            $_SESSION['verification_hash']
                        );

                        error_log('Échec envoi code de vérification : ' . $e->getMessage());
                        $error = 'Impossible d’envoyer le code de vérification. Réessayez plus tard.';
                    }
                }
            }

            ldap_unbind($ldap);
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — Partitions</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Mon compte</h1>
    <p class="muted">Connectez-vous à votre compte Partitions.</p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf"
               value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="uid">Identifiant</label>
        <input id="uid" name="uid" autocomplete="username"
               maxlength="128" required autofocus>

        <label for="password">Mot de passe</label>
        <input id="password" type="password" name="password"
               autocomplete="current-password" required>

        <button type="submit">Se connecter</button>
    </form>
</main>
</body>
</html>
