<?php
declare(strict_types=1);

session_start();

if (
    empty($_SESSION['pending_uid']) ||
    empty($_SESSION['verification_hash']) ||
    empty($_SESSION['pending_expiry']) ||
    time() > $_SESSION['pending_expiry']
) {
    unset(
        $_SESSION['pending_uid'],
        $_SESSION['pending_dn'],
        $_SESSION['pending_mail'],
        $_SESSION['pending_expiry'],
        $_SESSION['pending_attempts'],
        $_SESSION['verification_hash']
    );

    header('Location: index.php');
    exit;
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Requête invalide.');
    }

    $_SESSION['pending_attempts']++;

    if ($_SESSION['pending_attempts'] > 5) {
        unset(
            $_SESSION['pending_uid'],
            $_SESSION['pending_dn'],
            $_SESSION['pending_mail'],
            $_SESSION['pending_expiry'],
            $_SESSION['pending_attempts'],
            $_SESSION['verification_hash']
        );

        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }

    $code = trim((string)($_POST['code'] ?? ''));

    if (
        preg_match('/^[0-9]{6}$/D', $code) &&
        password_verify($code, $_SESSION['verification_hash'])
    ) {
        session_regenerate_id(true);

        $_SESSION['authenticated'] = true;
        $_SESSION['uid'] = $_SESSION['pending_uid'];
        $_SESSION['dn'] = $_SESSION['pending_dn'];
        $_SESSION['mail'] = $_SESSION['pending_mail'];

        unset(
            $_SESSION['pending_uid'],
            $_SESSION['pending_dn'],
            $_SESSION['pending_mail'],
            $_SESSION['pending_expiry'],
            $_SESSION['pending_attempts'],
            $_SESSION['verification_hash'],
            $_SESSION['csrf']
        );

        header('Location: compte.php');
        exit;
    }

    $error = 'Code incorrect.';
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification — Partitions</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Vérification</h1>
    <p class="muted">Saisissez le code envoyé à votre adresse e-mail.</p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf"
               value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="code">Code à 6 chiffres</label>
        <input id="code" name="code" inputmode="numeric"
               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>

        <button type="submit">Vérifier le code</button>
    </form>
</main>
</body>
</html>

