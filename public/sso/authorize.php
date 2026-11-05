<?php

declare(strict_types=1);
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',]);
session_start();
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/services.php';
const SSO_CLIENTS = ['video' => ['redirect_uri' => 'https://video.partitions.ovh/sso/callback.php', 'service' => 'video', 'label' => 'vidéo',], 'music' => ['redirect_uri' => 'https://musiques.partitions.ovh/php/sso/callback.php', 'service' => 'music', 'label' => 'musique',], 'partitions' => ['redirect_uri' => 'https://app.partitions.ovh/sso/callback.php', 'service' => 'partitions', 'label' => 'partitions',], 'photo' => ['redirect_uri' => 'https://photo.partitions.ovh/sso/callback.php', 'service' => 'photo', 'label' => 'photo',],];
function ssoErreur(int $code, string $message): never
{
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  exit($message);
}
function ssoBase64Url(string $valeur): string
{
  return rtrim(strtr(base64_encode($valeur), '+/', '-_'), '=');
} // ===================================================== // RÉCUPÉRATION DES PARAMÈTRES SSO // ===================================================== 
$parametres = null;
if (isset($_GET['client_id'], $_GET['redirect_uri'], $_GET['code_challenge'], $_GET['code_challenge_method'], $_GET['state'])) {
  $parametres = ['client_id' => (string) $_GET['client_id'], 'redirect_uri' => (string) $_GET['redirect_uri'], 'code_challenge' => (string) $_GET['code_challenge'], 'code_challenge_method' => (string) $_GET['code_challenge_method'], 'state' => (string) $_GET['state'],];
} elseif (isset($_SESSION['sso_pending']) && is_array($_SESSION['sso_pending'])) {
  $parametres = $_SESSION['sso_pending'];
}
if (!is_array($parametres)) {
  ssoErreur(400, 'Demande SSO absente ou invalide.');
} // ===================================================== // VALIDATION DU CLIENT // ===================================================== 
$clientId = $parametres['client_id'] ?? '';
$client = SSO_CLIENTS[$clientId] ?? null;
if ($client === null || ($parametres['redirect_uri'] ?? '') !== $client['redirect_uri'] || ($parametres['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', (string) ($parametres['code_challenge'] ?? '')) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', (string) ($parametres['state'] ?? ''))) {
  ssoErreur(400, 'Paramètres SSO invalides.');
} // ===================================================== // IDENTIFICATION DE L'UTILISATEUR // ===================================================== 
$utilisateurId = null;
$accesParLien = isset($_GET['access_key']); // --------------------------------------------- // CONNEXION PAR LIEN SECRET // --------------------------------------------- 
if ($accesParLien) {
  $cle = (string) $_GET['access_key'];
  if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $cle)) {
    ssoErreur(403, 'Lien d’accès invalide.');
  }
  try {
    $requete = $pdo->prepare('SELECT utilisateur_id FROM sso_liens WHERE cle_hash = :cle_hash AND service = :service AND actif = 1 AND ( date_expiration IS NULL OR date_expiration > NOW() ) LIMIT 1');
    $requete->execute(['cle_hash' => hash('sha256', $cle), 'service' => $client['service'],]);
    $lien = $requete->fetch();
    if (!$lien) {
      ssoErreur(403, 'Lien invalide, désactivé ou expiré.');
    }
    $utilisateurId = (int) $lien['utilisateur_id'];
  } catch (Throwable $e) {
    error_log('Erreur SSO lien : ' . $e->getMessage());
    ssoErreur(500, 'Une erreur technique est survenue.');
  } // --------------------------------------------- // CONNEXION CLASSIQUE PAR SESSION // --------------------------------------------- 
} else {
  if (!isset($_SESSION['utilisateur_id'])) {
    $_SESSION['sso_pending'] = $parametres;
    header('Cache-Control: no-store');
    header('Location: /connexion.php');
    exit;
  }
  $utilisateurId = (int) $_SESSION['utilisateur_id'];
} // ===================================================== // VÉRIFICATION DU COMPTE ET DES AUTORISATIONS // ===================================================== 
try { // Vérifier que le compte existe toujours. 
  $requete = $pdo->prepare('SELECT id FROM utilisateurs WHERE id = :id LIMIT 1');
  $requete->execute(['id' => $utilisateurId,]);
  if (!$requete->fetch()) {
    unset($_SESSION['utilisateur_id']);
    if ($accesParLien) {
      ssoErreur(403, 'Le compte utilisateur associé à ce lien n’existe plus.');
    }
    header('Location: /connexion.php');
    exit;
  } // Vérifier les droits d'accès au service. 
  if (!utilisateurAAccesService($pdo, $utilisateurId, $client['service'])) {
    unset($_SESSION['sso_pending']);
    ssoErreur(403, 'Accès refusé : ce compte ne dispose pas des droits ' . $client['label'] . '.');
  } // ================================================= // GÉNÉRATION DU CODE SSO // ================================================= 
  $code = ssoBase64Url(random_bytes(32));
  $codeHash = hash('sha256', $code);
  $requete = $pdo->prepare('INSERT INTO sso_codes ( code_hash, utilisateur_id, client_id, code_challenge, date_expiration ) VALUES ( :code_hash, :utilisateur_id, :client_id, :code_challenge, DATE_ADD(NOW(), INTERVAL 2 MINUTE) )');
  $requete->execute(['code_hash' => $codeHash, 'utilisateur_id' => $utilisateurId, 'client_id' => $clientId, 'code_challenge' => $parametres['code_challenge'],]); // Supprimer la demande SSO en attente. 
  unset($_SESSION['sso_pending']); // ================================================= // REDIRECTION VERS LE SERVICE // ================================================= 
  $urlRetour = $client['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $parametres['state'],], '', '&', PHP_QUERY_RFC3986);
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  header('Location: ' . $urlRetour);
  exit;
} catch (Throwable $e) {
  error_log('Erreur SSO authorize : ' . $e->getMessage());
  ssoErreur(500, 'Une erreur technique est survenue pendant la connexion.');
}
