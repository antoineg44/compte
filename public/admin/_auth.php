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

require_once dirname(__DIR__, 2) . '/config/database.php';

// Vérifier que l'utilisateur est connecté.
if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

try {
    // Vérifier les droits directement dans MySQL.
    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute([
        'id' => (int) $_SESSION['utilisateur_id'],
    ]);

    $utilisateurConnecte = $requete->fetch();

} catch (PDOException $e) {
    error_log(
        'Erreur de vérification du rôle : '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Une erreur technique est survenue.');
}

// Vérifier que le compte existe toujours.
if (!$utilisateurConnecte) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();

    header('Location: ../connexion.php');
    exit;
}

// Actualiser les informations du compte.
$_SESSION['utilisateur_identifiant'] =
    $utilisateurConnecte['identifiant'];

$_SESSION['utilisateur_email'] =
    $utilisateurConnecte['email'];

$_SESSION['utilisateur_role'] =
    $utilisateurConnecte['role'];

$_SESSION['utilisateur_admin'] =
    ($utilisateurConnecte['role'] === 'admin');

// Pour le moment, toutes les pages de ce dossier
// sont réservées aux administrateurs.
if ($utilisateurConnecte['role'] !== 'admin') {
    http_response_code(403);
    exit('Accès interdit : droits administrateur requis.');
}

// Créer un jeton CSRF.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Échapper les données avant leur affichage HTML.
function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Vérifier un jeton CSRF.
function verifierCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
