<?php

declare(strict_types=1);

/**
 * Enregistre une activité dans le journal.
 *
 * Les détails doivent être des données non sensibles.
 * Ne jamais enregistrer de mot de passe, de secret 2FA
 * ou de jeton de session.
 */
function enregistrerActivite(
    PDO $pdo,
    string $action,
    ?int $utilisateurId = null,
    array $details = []
): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $requete = $pdo->prepare(
            'INSERT INTO journal_activite
                (utilisateur_id, action, details, adresse_ip)
             VALUES
                (:utilisateur_id, :action, :details, :adresse_ip)'
        );

        $requete->execute([
            'utilisateur_id' => $utilisateurId,
            'action' => $action,
            'details' => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'adresse_ip' => $ip,
        ]);
    } catch (Throwable $e) {
        // Le journal ne doit pas exposer de détails internes
        // ni afficher de données sensibles au visiteur.
        error_log(
            'Impossible d’enregistrer une activité : '
            . $e->getMessage()
        );
    }
}
