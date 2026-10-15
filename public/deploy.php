<?php
$socketPath = '/run/deploy-compte.sock';

header('Content-Type: text/plain; charset=utf-8');

$socket = @stream_socket_client('unix://' . $socketPath, $errorCode, $errorMessage, 5);
if ($socket === false) {
    http_response_code(503);
    error_log('Connexion au service de deploiement impossible : ' . $errorMessage);
    exit("Service de deploiement indisponible.\n");
}

if (!stream_set_timeout($socket, 100)) {
    fclose($socket);
    http_response_code(500);
    error_log('Impossible de configurer le delai du socket de deploiement.');
    exit("Erreur de configuration du deploiement.\n");
}

$response = stream_get_contents($socket);
$metadata = stream_get_meta_data($socket);
fclose($socket);

if ($response === false || $metadata['timed_out'] || !$metadata['eof']) {
    http_response_code(504);
    error_log('Reponse du service de deploiement incomplete ou hors delai.');
    exit("Reponse du service de deploiement incomplete ou hors delai.\n");
}

$parts = explode("\n", $response, 2);
if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] > 255) {
    http_response_code(502);
    error_log('Reponse du service de deploiement invalide.');
    exit("Reponse du service de deploiement invalide.\n");
}

$returnCode = (int) $parts[0];
$output = $parts[1];
if ($returnCode !== 0) {
    http_response_code($returnCode === 75 ? 409 : ($returnCode === 124 ? 504 : 500));
    error_log('Echec du deploiement Git : ' . $output);
}

echo $output;
