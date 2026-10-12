<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['authenticated']) || empty($_SESSION['dn'])) {
    header('Location: index.php');
    exit;
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mon compte — Partitions</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Mon compte</h1>

    <p>Bienvenue,
       <strong><?= htmlspecialchars($_SESSION['uid'], ENT_QUOTES, 'UTF-8') ?></strong>.
    </p>

    <p>Adresse e-mail :
       <?= htmlspecialchars($_SESSION['mail'], ENT_QUOTES, 'UTF-8') ?>
    </p>

    <nav>
        <a href="modifier.php">Modifier mes informations</a>
        <a href="mot-de-passe.php">Changer mon mot de passe</a>
    </nav>

    <form method="post" action="deconnexion.php">
        <input type="hidden" name="csrf"
               value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="secondary">Se déconnecter</button>
    </form>
</main>
</body>
</html>

