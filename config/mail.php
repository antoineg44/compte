<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Stockage du dernier diagnostic
$GLOBALS['dernierDiagnosticMail'] = [];

// Récupération du diagnostic
function obtenirDiagnosticMail(): array
{
    return $GLOBALS['dernierDiagnosticMail'];
}

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

    $diagnostic = [
    'succes' => false,
    'erreur' => null,
    'serveur' => 'ssl0.ovh.net',
    'port' => 587,
    'securite' => 'STARTTLS',
    'mot_de_passe_configure' => false,
];


    try {

        // Configuration SMTP OVH
        $mail->isSMTP();
        $mail->Host       = 'ssl0.ovh.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contact@partitions.ovh';
        $mail->Password   = getenv('OVH_MAIL_PASSWORD');
        if (empty($mail->Password)) {
    throw new RuntimeException(
        'La variable OVH_MAIL_PASSWORD est absente ou vide.'
    );
}

$diagnostic['mot_de_passe_configure'] = true;

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
