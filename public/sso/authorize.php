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

const SSO_CLIENT_ID = 'video';const SSO_REDIRECT_URI = 'https://video.partitions.ovh/sso/callba
ck.php';

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
    isset($_GET['client_id'], $_GET['redirect_uri'],          $_GET['code_challenge'], $_GET['code_challenge_method']
,
          $_GET['state'])
) {
    $parametres = [
        'client_id' => (string) $_GET['client_id'],
        'redirect_uri' => (string) $_GET['redirect_uri'],
        'code_challenge' => (string) $_GET['code_challenge'],        'code_challenge_method' => (string) $_GET['code_challenge
_method'],
        'state' => (string) $_GET['state'],
    ];} elseif (isset($_SESSION['sso_pending']) && is_array($_SESSION['
sso_pending'])) {    // Reprendre la demande initiale après la connexion au compte
.
    $parametres = $_SESSION['sso_pending'];
}

if (!is_array($parametres)) {
    ssoErreur(400, 'Demande SSO absente ou invalide.');
}

if (
    $parametres['client_id'] !== SSO_CLIENT_ID
    || $parametres['redirect_uri'] !== SSO_REDIRECT_URI
    || $parametres['code_challenge_method'] !== 'S256'    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $parametres['code_c
hallenge'])    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $parametres['state'
])
) {
    ssoErreur(400, 'Paramètres SSO invalides.');
}
// Si nécessaire, mémoriser la demande avant d'afficher la connex
ion.
if (!isset($_SESSION['utilisateur_id'])) {
    $_SESSION['sso_pending'] = $parametres;

    header('Location: /connexion.php');
    exit;
}

try {
    // Vérifier que le compte existe toujours.
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
    // Un compte connecté n'obtient pas automatiquement accès à l
a vidéo.
    if (
        !utilisateurAAccesService(
            $pdo,
            (int) $_SESSION['utilisateur_id'],
            'video'
        )
    ) {
        unset($_SESSION['sso_pending']);        ssoErreur(403, 'Accès refusé : ce compte ne dispose pas d
es droits vidéo.');
    }
    // Code aléatoire de 256 bits, transmis uniquement au client
vidéo.
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
        'client_id' => SSO_CLIENT_ID,
        'code_challenge' => $parametres['code_challenge'],
    ]);

    unset($_SESSION['sso_pending']);

    // L'URL de retour est fixe et a été vérifiée ci-dessus.
    $urlRetour = SSO_REDIRECT_URI
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
    ssoErreur(500, 'Une erreur technique est survenue pendant laconnexion.');
}
