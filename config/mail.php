<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Stockage du dernier diagnostic
$GLOBALS['dernierDiagnosticMail'] = [];

/**
 * Récupère le dernier diagnostic d'envoi.
 */
function obtenirDiagnosticMail(): array
{
    return $GLOBALS['dernierDiagnosticMail'];
}

/**
 * Envoie un email via Postfix local.
 * Postfix transmet le message à OpenDKIM pour signature.
 */
function envoyerMail(
    string $destinataire,
    string $sujet,
    string $message,
    ?string $nom = null
): bool {

    $mail = new PHPMailer(true);

    $diagnostic = [
        'succes' => false,
        'erreur' => null,
        'serveur' => '127.0.0.1',
        'port' => 25,
        'securite' => 'Connexion locale',
        'mot_de_passe_configure' => false,
        'methode' => 'Postfix + OpenDKIM'
    ];

    try {

        // Connexion à Postfix sur le VPS
        $mail->isSMTP();
        $mail->Host = '127.0.0.1';
        $mail->Port = 25;

        // Pas d'authentification sur la connexion locale
        $mail->SMTPAuth = false;

        // Pas de TLS nécessaire sur le loopback
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;

        $mail->Timeout = 15;

        // Encodage
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // Expéditeur
        $mail->setFrom(
            'contact@partitions.ovh',
            'Partitions'
        );

        // Destinataire
        $mail->addAddress(
            $destinataire,
            $nom ?? ''
        );

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $sujet;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);

        // Envoi via Postfix
        $mail->send();

        $diagnostic['succes'] = true;

        $GLOBALS['dernierDiagnosticMail'] = $diagnostic;

        return true;

    } catch (Throwable $e) {

        $diagnostic['erreur'] = (
            $mail->ErrorInfo !== ''
                ? $mail->ErrorInfo
                : $e->getMessage()
        );

        $GLOBALS['dernierDiagnosticMail'] = $diagnostic;

        error_log(
            'Erreur envoi email : ' . $diagnostic['erreur']
        );

        return false;
    }
}
