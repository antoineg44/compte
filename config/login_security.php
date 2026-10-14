<?php

declare(strict_types=1);

/**
 * Protection contre les tentatives de connexion répétées.
 *
 * Utilise la table tentatives_connexion existante.
 *
 * Important :
 * - On utilise REMOTE_ADDR, pas un en-tête HTTP fourni
 *   par le visiteur comme X-Forwarded-For.
 * - Les tentatives réussies ne comptent pas comme des échecs.
 * - Aucune adresse IP ne doit être considérée comme fiable
 *   si elle provient directement d'un en-tête client.
 */

/**
 * Récupère l'adresse IP de la connexion.
 */
function obtenirAdresseIpConnexion(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (
        !is_string($ip)
        || filter_var($ip, FILTER_VALIDATE_IP) === false
    ) {
        return '0.0.0.0';
    }

    return $ip;
}

/**
 * Indique si de nouvelles tentatives doivent être ralenties.
 *
 * Limites :
 * - 5 échecs en 15 minutes pour le couple identifiant/IP ;
 * - 20 échecs en 15 minutes pour une IP.
 */
function connexionEstLimitee(
    PDO $pdo,
    string $identifiant,
    string $adresseIp
): bool {
    // Vérifier les échecs du couple identifiant/IP.
    $requete = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tentatives_connexion
         WHERE identifiant = :identifiant
           AND adresse_ip = :adresse_ip
           AND reussie = 0
           AND date_creation >= NOW() - INTERVAL 15 MINUTE'
    );

    $requete->execute([
        'identifiant' => $identifiant,
        'adresse_ip' => $adresseIp,
    ]);

    $echecsIdentifiantIp = (int) $requete->fetchColumn();

    if ($echecsIdentifiantIp >= 5) {
        return true;
    }

    // Vérifier le nombre total d'échecs depuis cette IP.
    $requete = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tentatives_connexion
         WHERE adresse_ip = :adresse_ip
           AND reussie = 0
           AND date_creation >= NOW() - INTERVAL 15 MINUTE'
    );

    $requete->execute([
        'adresse_ip' => $adresseIp,
    ]);

    $echecsIp = (int) $requete->fetchColumn();

    return $echecsIp >= 20;
}

/**
 * Enregistre une tentative de connexion.
 */
function enregistrerTentativeConnexion(
    PDO $pdo,
    string $identifiant,
    string $adresseIp,
    bool $reussie
): void {
    $requete = $pdo->prepare(
        'INSERT INTO tentatives_connexion
            (identifiant, adresse_ip, reussie)
         VALUES
            (:identifiant, :adresse_ip, :reussie)'
    );

    $requete->execute([
        'identifiant' => mb_substr($identifiant, 0, 50),
        'adresse_ip' => $adresseIp,
        'reussie' => $reussie ? 1 : 0,
    ]);
}
