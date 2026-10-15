<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 2) . '/config/activity.php';

// =====================================================
// STATISTIQUES
// =====================================================

try {
    // Nombre total de comptes
    $totalUtilisateurs = (int) $pdo
        ->query('SELECT COUNT(*) FROM utilisateurs')
        ->fetchColumn();

    // Nombre d'administrateurs
    $totalAdministrateurs = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM utilisateurs
             WHERE role = 'admin'"
        )
        ->fetchColumn();

    // Nombre de modérateurs
    $totalModerateurs = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM utilisateurs
             WHERE role = 'moderator'"
        )
        ->fetchColumn();

    // Nombre d'utilisateurs classiques
    $totalClassiques = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM utilisateurs
             WHERE role = 'user'"
        )
        ->fetchColumn();

    // Nombre de comptes créés aujourd'hui
    $comptesAujourdhui = (int) $pdo
        ->query(
            'SELECT COUNT(*)
             FROM utilisateurs
             WHERE date_creation >= CURRENT_DATE()'
        )
        ->fetchColumn();

    // Cinq derniers utilisateurs inscrits
    $requete = $pdo->query(
        'SELECT
            id,
            identifiant,
            email,
            role,
            date_creation
         FROM utilisateurs
         ORDER BY id DESC
         LIMIT 5'
    );

    $derniersUtilisateurs = $requete->fetchAll();

    // Dernières activités, si la table existe.
    $dernieresActivites = [];

    $requete = $pdo->query(
        'SELECT
            id,
            utilisateur_id,
            action,
            details,
            date_creation
         FROM journal_activite
         ORDER BY id DESC
         LIMIT 8'
    );

    $dernieresActivites = $requete->fetchAll();

} catch (PDOException $e) {
    error_log(
        'Erreur de chargement du tableau de bord : '
        . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Impossible de charger le tableau de bord. '.'Consulte les journaux du serveur pour plus de détails.'
    );
}

// =====================================================
// FORMATAGE
// =====================================================

function formaterDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Date inconnue';
    }

    try {
        $dateObj = new DateTimeImmutable($date);

        return $dateObj->format('d/m/Y à H:i');

    } catch (Throwable $e) {
        return $date;
    }
}

function libelleRole(string $role): string
{
    return match ($role) {
        'admin' => 'Administrateur',
        'moderator' => 'Modérateur',
        default => 'Utilisateur',
    };
}

function classeRole(string $role): string
{
    return match ($role) {
        'admin' => 'badge-admin',
        'moderator' => 'badge-moderator',
        default => 'badge-utilisateur',
    };
}

function libelleActivite(string $action): string
{
    return match ($action) {
        'connexion_reussie' => 'Connexion réussie',
        'connexion_echouee' => 'Connexion échouée',
        'utilisateur_modifie' => 'Utilisateur modifié',
        'utilisateur_supprime' => 'Utilisateur supprimé',
        'role_modifie' => 'Rôle modifié',
        default => $action,
    };
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Administration | Tableau de bord</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --fond: #f3f5f9;
            --blanc: #ffffff;
            --texte: #172033;
            --texte-secondaire: #6b7280;
            --bordure: #e5e7eb;
            --primaire: #315bea;
            --primaire-hover: #2448c5;
            --navigation: #172033;
        }

        body {
            margin: 0;
            background: var(--fond);
            color: var(--texte);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            color: inherit;
        }

        /* Navigation */

        .entete {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 20px 5%;
            background: var(--navigation);
            color: white;
        }

        .logo {
            font-size: 20px;
            font-weight: 700;
            text-decoration: none;
        }

        .navigation {
            display: flex;
            align-items: center;
            gap: 22px;
            flex-wrap: wrap;
        }

        .navigation a {
            color: #e5e7eb;
            font-size: 14px;
            text-decoration: none;
        }

        .navigation a:hover {
            color: white;
            text-decoration: underline;
        }

        /* Contenu principal */

        main {
            width: min(1300px, 94%);
            margin: 35px auto 60px;
        }

        .titre-page {
            margin-bottom: 8px;
            font-size: 30px;
        }

        .description {
            margin-top: 0;
            margin-bottom: 30px;
            color: var(--texte-secondaire);
            line-height: 1.6;
        }

        /* Statistiques */

        .grille-statistiques {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .carte-statistique {
            padding: 24px;
            background: var(--blanc);
            border: 1px solid var(--bordure);
            border-radius: 14px;
            box-shadow: 0 3px 10px rgba(20, 35, 65, 0.03);
        }

        .statistique-libelle {
            margin-bottom: 12px;
            color: var(--texte-secondaire);
            font-size: 14px;
        }

        .statistique-nombre {
            margin-bottom: 8px;
            font-size: 32px;
            font-weight: 700;
        }

        .statistique-description {
            color: var(--texte-secondaire);
            font-size: 12px;
        }

        /* Sections */

        .section {
            margin-bottom: 28px;
            padding: 25px;
            background: var(--blanc);
            border: 1px solid var(--bordure);
            border-radius: 14px;
        }

        .section-entete {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
        }

        .section-titre {
            margin: 0;
            font-size: 20px;
        }

        .section-description {
            margin-top: 7px;
            color: var(--texte-secondaire);
            font-size: 13px;
        }

        /* Boutons */

        .bouton {
            display: inline-block;
            padding: 11px 15px;
            border: 0;
            border-radius: 8px;
            background: var(--primaire);
            color: white;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }

        .bouton:hover {
            background: var(--primaire-hover);
        }

        .bouton-secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .bouton-secondaire:hover {
            background: #d1d5db;
        }

        /* Tableaux */

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th,
        td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--bordure);
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        th {
            background: #f8fafc;
            color: var(--texte-secondaire);
            font-weight: 600;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        /* Badges de rôle */

        .badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-admin {
            background: #ede9fe;
            color: #6d28d9;
        }

        .badge-moderator {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-utilisateur {
            background: #e5e7eb;
            color: #374151;
        }

        /* Activités */

        .liste-activites {
            display: flex;
            flex-direction: column;
        }

        .activite {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding: 15px 0;
            border-bottom: 1px solid var(--bordure);
        }

        .activite:last-child {
            border-bottom: none;
        }

        .activite-nom {
            margin-bottom: 5px;
            font-size: 14px;
            font-weight: 600;
        }

        .activite-details {
            color: var(--texte-secondaire);
            font-size: 12px;
            line-height: 1.5;
        }

        .activite-date {
            flex-shrink: 0;
            color: var(--texte-secondaire);
            font-size: 12px;
        }

        .vide {
            padding: 20px 0;
            color: var(--texte-secondaire);
            font-size: 14px;
        }

        /* Raccourcis */

        .grille-raccourcis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .raccourci {
            display: block;
            padding: 20px;
            border: 1px solid var(--bordure);
            border-radius: 10px;
            text-decoration: none;
            transition: border-color 0.15s;
        }

        .raccourci:hover {
            border-color: var(--primaire);
        }

        .raccourci-titre {
            margin-bottom: 8px;
            font-weight: 700;
        }

        .raccourci-description {
            color: var(--texte-secondaire);
            font-size: 13px;
            line-height: 1.5;
        }

        /* Responsive */

        @media (max-width: 1000px) {
            .grille-statistiques {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .grille-raccourcis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .entete {
                align-items: flex-start;
                flex-direction: column;
            }

            .navigation {
                gap: 14px;
            }

            main {
                margin-top: 25px;
            }

            .titre-page {
                font-size: 25px;
            }

            .grille-statistiques {
                gap: 12px;
            }

            .carte-statistique {
                padding: 17px;
            }

            .statistique-nombre {
                font-size: 27px;
            }

            .section {
                padding: 17px;
            }

            .section-entete {
                align-items: flex-start;
                flex-direction: column;
            }

            .grille-raccourcis {
                grid-template-columns: 1fr;
            }

            .activite {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>

<body>

<header class="entete">

    <a class="logo" href="index.php">
        Administration
    </a>

    <nav class="navigation" aria-label="Navigation principale">

        <a href="index.php">
            Tableau de bord
        </a>

        <a href="utilisateurs.php">
            Utilisateurs
        </a>

        <a href="../index.php">
            Mon espace
        </a>
<a href="services.php">Gestion des services</a>

<a href="acces-services.php">Gestion des accès</a>

    </nav>

</header>

<main>

    <h1 class="titre-page">
        Tableau de bord
    </h1>

    <p class="description">
        Bienvenue dans l'espace d'administration,
        <?= e((string) $_SESSION['utilisateur_identifiant']) ?>.
        Voici un aperçu de ton application.
    </p>

    <!-- ========================================= -->
    <!-- STATISTIQUES -->
    <!-- ========================================= -->

    <section
        class="grille-statistiques"
        aria-label="Statistiques des utilisateurs"
    >

        <article class="carte-statistique">

            <div class="statistique-libelle">
                Total des utilisateurs
            </div>

            <div class="statistique-nombre">
                <?= $totalUtilisateurs ?>
            </div>

            <div class="statistique-description">
                Tous les comptes enregistrés
            </div>

        </article>

        <article class="carte-statistique">

            <div class="statistique-libelle">
                Administrateurs
            </div>

            <div class="statistique-nombre">
                <?= $totalAdministrateurs ?>
            </div>

            <div class="statistique-description">
                Comptes ayant tous les droits administratifs
            </div>

        </article>

        <article class="carte-statistique">

            <div class="statistique-libelle">
                Modérateurs
            </div>

            <div class="statistique-nombre">
                <?= $totalModerateurs ?>
            </div>

            <div class="statistique-description">
                Comptes ayant le rôle modérateur
            </div>

        </article>

        <article class="carte-statistique">

            <div class="statistique-libelle">
                Utilisateurs classiques
            </div>

            <div class="statistique-nombre">
                <?= $totalClassiques ?>
            </div>

            <div class="statistique-description">
                Comptes sans privilèges administratifs
            </div>

        </article>

    </section>

    <!-- ========================================= -->
    <!-- INSCRIPTIONS -->
    <!-- ========================================= -->

    <section class="section">

        <div class="section-entete">

            <div>
                <h2 class="section-titre">
                    Inscriptions
                </h2>

                <p class="section-description">
                    Nouveaux comptes créés aujourd'hui.
                </p>
            </div>

            <a
                class="bouton bouton-secondaire"
                href="utilisateurs.php"
            >
                Voir tous les utilisateurs
            </a>

        </div>

        <div class="statistique-nombre">
            <?= $comptesAujourdhui ?>
        </div>

        <div class="statistique-description">            Inscription(s) depuis minuit, selon l'heure du serveur MySQL.
        </div>

    </section>

    <!-- ========================================= -->
    <!-- DERNIERS UTILISATEURS -->
    <!-- ========================================= -->

    <section class="section">

        <div class="section-entete">

            <div>
                <h2 class="section-titre">
                    Derniers utilisateurs inscrits
                </h2>

                <p class="section-description">
                    Les cinq comptes les plus récemment créés.
                </p>
            </div>

            <a
                class="bouton"
                href="utilisateurs.php"
            >
                Gérer les comptes
            </a>

        </div>

        <?php if ($derniersUtilisateurs === []): ?>

            <p class="vide">
                Aucun utilisateur enregistré pour le moment.
            </p>

        <?php else: ?>

            <div class="table-responsive">

                <table>

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Identifiant</th>
                            <th>E-mail</th>
                            <th>Rôle</th>
                            <th>Date d'inscription</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($derniersUtilisateurs as $utilisateur): ?>

                        <tr>

                            <td>
                                <?= (int) $utilisateur['id'] ?>
                            </td>

                            <td>                                <?= e((string) $utilisateur['identifiant']) ?>
                            </td>

                            <td>                                <?= e((string) $utilisateur['email']) ?>
                            </td>

                            <td>

                                <span class="badge <?= e(                                  classeRole((string) $utilisateur['role'])
                                ) ?>">
                                    <?= e(                                        libelleRole((string) $utilisateur['role'])
                                    ) ?>
                                </span>

                            </td>

                            <td>
                                <?= e(
                                    formaterDate(                                        (string) $utilisateur['date_creation']
                                    )
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

    <!-- ========================================= -->
    <!-- JOURNAL D'ACTIVITÉ -->
    <!-- ========================================= -->

    <section class="section">

        <div class="section-entete">

            <div>
                <h2 class="section-titre">
                    Journal d'activité
                </h2>

                <p class="section-description">
                    Les huit dernières opérations enregistrées.
                </p>
            </div>

        </div>

        <?php if ($dernieresActivites === []): ?>

            <p class="vide">
                Aucune activité enregistrée.
            </p>

        <?php else: ?>

            <div class="liste-activites">
                <?php foreach ($dernieresActivites as $activite):
 ?>

                    <article class="activite">

                        <div>

                            <div class="activite-nom">
                                <?= e(
                                    libelleActivite(                                        (string) $activite['action']
                                    )
                                ) ?>
                            </div>

                            <div class="activite-details">

                                <?php if (                                    $activite['utilisateur_id'] !== null
                                ): ?>

                                    Utilisateur associé :                                    #<?= (int) $activite['utilisateur_id'] ?>

                                <?php else: ?>

                                    Aucun utilisateur associé.

                                <?php endif; ?>

                                <?php                                $details = $activite['details'] ?? null;
                                if (is_string($details) && $details !== ''):
                                ?>

                                    <br>

                                    <?php
                                    $detailsJson = json_decode(
                                        $details,
                                        true
                                    );
                                    ?>
                                    <?php if (is_array($detailsJson)): ?>

                                        <?= e(
                                            json_encode(
                                                $detailsJson,                                                JSON_UNESCAPED_UNICODE                                                | JSON_UNESCAPED_
SLASHES                                                | JSON_INVALID_UTF8_SUBSTITUTE
                                            ) ?: ''
                                        ) ?>

                                    <?php else: ?>

                                        <?= e($details) ?>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                        <time class="activite-date">
                            <?= e(
                                formaterDate(                                    (string) $activite['date_creation']
                                )
                            ) ?>
                        </time>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    <!-- ========================================= -->
    <!-- RACCOURCIS -->
    <!-- ========================================= -->

    <section class="section">

        <div class="section-entete">

            <div>
                <h2 class="section-titre">
                    Administration
                </h2>

                <p class="section-description">
                    Accède rapidement aux principales fonctions.
                </p>
            </div>

        </div>

        <div class="grille-raccourcis">

            <a
                class="raccourci"
                href="utilisateurs.php"
            >

                <div class="raccourci-titre">
                    Gestion des utilisateurs
                </div>

                <div class="raccourci-description">                    Rechercher, modifier, promouvoir ou supprimer un compte.
                </div>

            </a>

            <a
                class="raccourci"
                href="../index.php"
            >

                <div class="raccourci-titre">
                    Espace personnel
                </div>

                <div class="raccourci-description">
                    Revenir à ton espace utilisateur.
                </div>

            </a>

            <a
                class="raccourci"
                href="utilisateurs.php"
            >

                <div class="raccourci-titre">
                    Gestion des rôles
                </div>

                <div class="raccourci-description">
                    Administrer les droits des comptes utilisateurs.
                </div>

            </a>

        </div>

    </section>

</main>

</body>
</html>
