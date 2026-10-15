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
require_once dirname(__DIR__, 2) . '/config/services.php';

const SSO_CLIENTS = [
    'video' => [
        'redirect_uri' => 'https://video.partitions.ovh/sso/callback.php',
        'service' => 'video',
        'label' => 'vidéo',
    ],
    'music' => [
        'redirect_uri' => 'https://musiques.partitions.ovh/php/sso/callback.php',
        'service' => 'music',
        'label' => 'musique',
    ],
];

function ssoErreur(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function ssoBase64Url(string $valeur): string
{
    return rtrim(strtr(base64_encode($valeur), '+/', '-_'), '=');
}

$parametres = null;

if (
    isset(
        $_GET['client_id'],
        $_GET['redirect_uri'],
        $_GET['code_challenge'],
        $_GET['code_challenge_method'],
        $_GET['state']
    )
) {
    $parametres = [
        'client_id' => (string) $_GET['client_id'],
        'redirect_uri' => (string) $_GET['redirect_uri'],
        'code_challenge' => (string) $_GET['code_challenge'],
        'code_challenge_method' => (string) $_GET['code_challenge_method'],
        'state' => (string) $_GET['state'],
    ];
} elseif (isset($_SESSION['sso_pending']) && is_array($_SESSION['sso_pending'])) {
    $parametres = $_SESSION['sso_pending'];
}

if (!is_array($parametres)) {
    ssoErreur(400, 'Demande SSO absente ou invalide.');
}

$client = SSO_CLIENTS[$parametres['client_id']] ?? null;

if (
    $client === null
    || $parametres['redirect_uri'] !== $client['redirect_uri']
    || $parametres['code_challenge_method'] !== 'S256'
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $parametres['code_challenge'])
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $parametres['state'])
) {
    ssoErreur(400, 'Paramètres SSO invalides.');
}

if (!isset($_SESSION['utilisateur_id'])) {
    $_SESSION['sso_pending'] = $parametres;

    header('Location: /connexion.php');
    exit;
}

try {
    $requete = $pdo->prepare(
        'SELECT id
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute([
        'id' => (int) $_SESSION['utilisateur_id'],
    ]);

    if (!$requete->fetch()) {
        unset($_SESSION['utilisateur_id']);
        header('Location: /connexion.php');
        exit;
    }

    if (
        !utilisateurAAccesService(
            $pdo,
            (int) $_SESSION['utilisateur_id'],
            $client['service']
        )
    ) {
        unset($_SESSION['sso_pending']);
        ssoErreur(403, 'Accès refusé : ce compte ne dispose pas des droits ' . $client['label'] . '.');
    }

    $code = ssoBase64Url(random_bytes(32));
    $codeHash = hash('sha256', $code);

    $requete = $pdo->prepare(
        'INSERT INTO sso_codes
            (code_hash, utilisateur_id, client_id,
             code_challenge, date_expiration)
         VALUES
            (:code_hash, :utilisateur_id, :client_id,
             :code_challenge, DATE_ADD(NOW(), INTERVAL 2 MINUTE))'
    );

    $requete->execute([
        'code_hash' => $codeHash,
        'utilisateur_id' => (int) $_SESSION['utilisateur_id'],
        'client_id' => $parametres['client_id'],
        'code_challenge' => $parametres['code_challenge'],
    ]);

    unset($_SESSION['sso_pending']);

    $urlRetour = $client['redirect_uri']
        . '?'
        . http_build_query([
            'code' => $code,
            'state' => $parametres['state'],
        ], '', '&', PHP_QUERY_RFC3986);

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $urlRetour);
    exit;

} catch (Throwable $e) {
    error_log('Erreur SSO authorize : ' . $e->getMessage());
    ssoErreur(500, 'Une erreur technique est survenue pendant la connexion.');
}
