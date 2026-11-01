<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Envoie un email via le SMTP OVH.
 *
 * @param string $destinataire Adresse email du destinataire
 * @param string $sujet        Sujet du message
 * @param string $message      Contenu HTML du message
 * @param string|null $nom     Nom du destinataire
 *
 * @return bool
 */
function envoyerMail(
    string $destinataire,
    string $sujet,
    string $message,
    ?string $nom = null
): bool {

    $mail = new PHPMailer(true);

    try {

        // Configuration SMTP OVH
        $mail->isSMTP();
        $mail->Host       = 'ssl0.ovh.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contact@partitions.ovh';
        $mail->Password   = getenv('OVH_MAIL_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Encodage
        $mail->CharSet = 'UTF-8';

        // Expéditeur
        $mail->setFrom('contact@partitions.ovh', 'Partitions');

        // Destinataire
        $mail->addAddress($destinataire, $nom ?? '');

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $sujet;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);

        // Envoi
        return $mail->send();

    } catch (Exception $e) {

        error_log('Erreur envoi email : ' . $mail->ErrorInfo);

        return false;
    }
}
