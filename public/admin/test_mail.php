<?php

declare(strict_types=1); // ============================================= // CONFIGURATION // ============================================= 
// Adresse qui recevra le mail de test. 
$destinataire = 'antoine.godet@outlook.fr'; // Protection : à modifier avec une clé personnelle. 
$cleAcces = 'CHANGE_MOI_AVEC_UNE_CLE_SECRETE_LONGUE';
// ============================================= // INITIALISATION // ============================================= 
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict',]);
session_start();
// ============================================= // FONCTIONS // ============================================= 
function e(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}
function diagnostic(string $titre, bool $succes, string $message): void
{
    global $diagnostics;
    $diagnostics[] = ['titre' => $titre, 'succes' => $succes, 'message' => $message,];
} // ============================================= // VARIABLES // ============================================= 
$diagnostics = [];
$resultat = false;
$erreurDetaillee = '';
$envoiEffectue = false; // ============================================= // VERIFICATION DE LA CLE D'ACCES // ============================================= 
if (!hash_equals($cleAcces, (string) ($_GET['cle'] ?? ''))) {
    http_response_code(403);
    exit('Accès refusé.');
} // ============================================= // TESTS AUTOMATIQUES // ============================================= 
// 1. Vérification PHP 
diagnostic('Version PHP', PHP_VERSION_ID >= 80200, 'Version détectée : ' . PHP_VERSION);
// 2. Vérification de la configuration mail 
$fichierMail = dirname(__DIR__) . '/../config/mail.php';
diagnostic('Fichier de configuration mail', file_exists($fichierMail), $fichierMail);
if (file_exists($fichierMail)) {
    try {
        require_once $fichierMail;
        diagnostic('Chargement de mail.php', true, 'Le fichier a été chargé correctement.');
    } catch (Throwable $e) {
        diagnostic('Chargement de mail.php', false, $e->getMessage());
    }
} else {
    diagnostic('Chargement de mail.php', false, 'Fichier introuvable.');
} // 3. Vérification de la fonction 
$fonctionExiste = function_exists('envoyerMail');
diagnostic('Fonction envoyerMail()', $fonctionExiste, $fonctionExiste ? 'La fonction est disponible.' : 'La fonction envoyerMail() est introuvable.'); // 4. Vérification de la configuration PHP 
diagnostic('Extensions OpenSSL', extension_loaded('openssl'), extension_loaded('openssl') ? 'OpenSSL est activé.' : 'OpenSSL est désactivé.');
diagnostic('Extension JSON', extension_loaded('json'), extension_loaded('json') ? 'JSON est activé.' : 'JSON est désactivé.'); // ============================================= // TEST D'ENVOI // ============================================= 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fonctionExiste && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    $adresse = filter_var($destinataire, FILTER_VALIDATE_EMAIL);
    if (!$adresse) {
        diagnostic('Adresse destinataire', false, 'Adresse e-mail invalide.');
    } else {
        $sujet = 'Test de fonctionnement du serveur mail';
        $date = date('d/m/Y à H:i:s');
        $contenu = ' <div style="font-family:Arial;padding:25px;background:#f3f5f9;"> <div style="background:white;padding:25px;border-radius:12px;"> <h2 style="color:#315bea;"> Test d\'envoi réussi ! </h2> <p> Ce message confirme que votre système d\'envoi d\'e-mails fonctionne. </p> <p> <strong>Date du test :</strong> ' . e($date) . ' </p> <p> <strong>Serveur :</strong> ' . e((string) ($_SERVER['HTTP_HOST'] ?? 'Inconnu')) . ' </p> <hr> <p style="color:#6b7280;font-size:12px;"> Message automatique de diagnostic. </p> </div> </div> ';
        try {
            $resultat = envoyerMail($adresse, $sujet, $contenu);
            $envoiEffectue = true;
            diagnostic('Envoi du mail', $resultat === true, $resultat === true ? 'La fonction envoyerMail() a confirmé l\'envoi.' : 'La fonction envoyerMail() a retourné FALSE.');
        } catch (Throwable $e) {
            $erreurDetaillee = $e->getMessage();
            diagnostic('Exception pendant l\'envoi', false, $erreurDetaillee);
            error_log('Diagnostic mail : ' . $e->getMessage());
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    diagnostic('Validation du formulaire', false, 'Jeton CSRF invalide ou fonction d\'envoi indisponible.');
} // ============================================= // TOKEN CSRF // ============================================= 
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
} ?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Diagnostic des e-mails</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            background: #f3f5f9;
            font-family: Arial, sans-serif;
            color: #172033;
        }

        .conteneur {
            max-width: 750px;
            margin: auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, .06);
        }

        h1 {
            margin-top: 0;
            color: #315bea;
        }

        .description {
            color: #64748b;
            line-height: 1.6;
        }

        .diagnostic {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 16px;
            margin: 12px 0;
            border-radius: 9px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .ok {
            border-left: 5px solid #16a34a;
        }

        .ko {
            border-left: 5px solid #dc2626;
        }

        .etat {
            font-weight: bold;
            white-space: nowrap;
        }

        .ok .etat {
            color: #16a34a;
        }

        .ko .etat {
            color: #dc2626;
        }

        .message {
            margin-top: 8px;
            font-size: 13px;
            color: #64748b;
            overflow-wrap: anywhere;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 15px;
            background: #315bea;
            border: 0;
            border-radius: 9px;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #2448c5;
        }

        .info {
            margin-top: 25px;
            padding: 15px;
            background: #eff6ff;
            border-radius: 9px;
            color: #1e40af;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>

</head>

<body>

    <main class="conteneur">

        <h1>Diagnostic de l'envoi des e-mails</h1>

        <p class="description"> Cette page vérifie la configuration PHP et teste l'envoi d'un véritable e-mail. </p>

        <h2>1. Vérifications techniques</h2>

        <?php foreach ($diagnostics as $test): ?>

            <div class="diagnostic <?= $test['succes'] ? 'ok' : 'ko' ?>">

                <div>

                    <strong><?= e($test['titre']) ?></strong>

                    <div class="message">
                        <?= e($test['message']) ?>
                    </div>

                </div>

                <div class="etat">
                    <?= $test['succes'] ? 'OK' : 'ERREUR' ?>
                </div>

            </div>


        <?php endforeach; ?>

        <h2>2. Tester l'envoi d'un e-mail</h2>

        <p class="description"> Destinataire : <strong><?= e($destinataire) ?></strong> </p>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>">

            <button type="submit">
                Envoyer un e-mail de test
            </button>


        </form>

        <?php if ($envoiEffectue): ?>

            <div class="info">

                <?php if ($resultat === true): ?>

                    <strong>Demande d'envoi acceptée !</strong>

                    <p>
                        La fonction envoyerMail() a retourné TRUE.
                        Vérifiez maintenant la réception du message,
                        ainsi que les courriers indésirables.
                    </p>

                <?php else: ?>

                    <strong>Échec de l'envoi !</strong>

                    <p>
                        La fonction envoyerMail() a retourné FALSE.
                        Consultez les journaux PHP et la configuration SMTP.
                    </p>

                <?php endif; ?>

            </div>


        <?php endif; ?>

        <div class="info">

            <strong>Important :</strong>

            <p>
                Un retour TRUE signifie que la bibliothèque a accepté
                la demande d'envoi. Cela ne garantit pas que le message
                est arrivé dans la boîte de réception.
            </p>

            <p>
                Supprimez cette page après le diagnostic.
            </p>


        </div>

    </main>

</body>

</html>
