<?php

declare(strict_types=1);

/**
 * Vérifie si un utilisateur a accès à un service.
 *
 * L'accès est accordé uniquement si :
 * - le service existe ;
 * - le service est actif ;
 * - l'utilisateur possède une autorisation explicite.
 * * Les administrateurs doivent également disposer d'une autorisat
ion * pour accéder aux services métier. Leur rôle leur permet de gér
er * les droits, mais ne contourne pas les règles d'accès aux servi
ces.
 */
function utilisateurAAccesService(
    PDO $pdo,
    int $utilisateurId,
    string $slug
): bool {
    $requete = $pdo->prepare(
        'SELECT 1
         FROM services s
         INNER JOIN acces_utilisateur_services a
             ON a.service_id = s.id
         WHERE s.slug = :slug
           AND s.actif = 1
           AND a.utilisateur_id = :utilisateur_id
           AND a.autorise = 1
         LIMIT 1'
    );

    $requete->execute([
        'slug' => $slug,
        'utilisateur_id' => $utilisateurId,
    ]);

    return $requete->fetchColumn() !== false;
}

/**
 * Empêche l'accès à une page protégée si l'utilisateur
 * n'a pas l'autorisation nécessaire.
 *
 * À appeler avant tout affichage HTML.
 */
function exigerAccesService(
    PDO $pdo,
    string $slug
): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException(            'La session doit être démarrée avant la vérification
des accès.'
        );
    }

    if (!isset($_SESSION['utilisateur_id'])) {
        header('Location: connexion.php');
        exit;
    }

    try {
        $autorise = utilisateurAAccesService(
            $pdo,
            (int) $_SESSION['utilisateur_id'],
            $slug
        );
    } catch (PDOException $e) {
        error_log(
            'Erreur de vérification des accès aux services : '
            . $e->getMessage()
        );

        http_response_code(500);
        exit('Impossible de vérifier les autorisations.');
    }

    if (!$autorise) {
        http_response_code(403);

        exit(
            'Accès refusé : tu ne disposes pas des autorisations'
            . 'nécessaires pour accéder à ce service.'
        );
    }
}
