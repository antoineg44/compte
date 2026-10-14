<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 2) . '/config/activity.php';

$message = '';
$typeMessage = '';

// Récupérer un éventuel message après redirection.
if (isset($_SESSION['flash_admin'])) {    $message = (string) ($_SESSION['flash_admin']['message'] ?? '
');    $typeMessage = (string) ($_SESSION['flash_admin']['type'] ??
'');

    unset($_SESSION['flash_admin']);
}

/**
 * Enregistre un message puis redirige vers la liste.
 */function redirigerAvecMessage(string $message, string $type): nev
er
{
    $_SESSION['flash_admin'] = [
        'message' => $message,
        'type' => $type,
    ];

    header('Location: utilisateurs.php');
    exit;
}

// =====================================================
// TRAITEMENT DES ACTIONS POST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!verifierCsrf($csrfToken)) {
        http_response_code(403);
        exit('Requête invalide. Recharge la page.');
    }

    $action = (string) ($_POST['action'] ?? '');

    $idUtilisateur = filter_var(
        $_POST['id_utilisateur'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        $idUtilisateur === false
        || $idUtilisateur === null
    ) {
        redirigerAvecMessage(
            'Identifiant utilisateur invalide.',
            'erreur'
        );
    }

    $idUtilisateur = (int) $idUtilisateur;
    $idConnecte = (int) $_SESSION['utilisateur_id'];

    // Empêcher toute suppression de son propre compte.
    if (
        $action === 'supprimer'
        && $idUtilisateur === $idConnecte
    ) {
        redirigerAvecMessage(
            'Tu ne peux pas supprimer ton propre compte.',
            'erreur'
        );
    }

    // Empêcher le retrait de ses propres droits.
    if (
        $action === 'role'
        && $idUtilisateur === $idConnecte
        && (string) ($_POST['nouveau_role'] ?? '') !== 'admin'
    ) {
        redirigerAvecMessage(            'Tu ne peux pas retirer tes propres droits administra
teur.',
            'erreur'
        );
    }

    try {
        $pdo->beginTransaction();

        /*
         * Verrouiller les administrateurs existants.
         *
         * Cela évite que deux opérations concurrentes
         * suppriment ou rétrogradent simultanément
         * les derniers administrateurs.
         */
        $requete = $pdo->query(
            "SELECT id
             FROM utilisateurs
             WHERE role = 'admin'
             FOR UPDATE"
        );

        $administrateurs = $requete->fetchAll(PDO::FETCH_COLUMN);

        // Vérifier que le compte ciblé existe.
        $requete = $pdo->prepare(
            'SELECT id, identifiant, email, role
             FROM utilisateurs
             WHERE id = :id
             FOR UPDATE'
        );

        $requete->execute([
            'id' => $idUtilisateur,
        ]);

        $cible = $requete->fetch();

        if (!$cible) {
            throw new DomainException(
                'Cet utilisateur n’existe plus.'
            );
        }

        // ---------------------------------------------
        // CHANGER LE RÔLE
        // ---------------------------------------------

        if ($action === 'role') {
            $nouveauRole = (string) (
                $_POST['nouveau_role'] ?? ''
            );

            if (!in_array(
                $nouveauRole,
                ['user', 'admin'],
                true
            )) {
                throw new DomainException(
                    'Le rôle demandé est invalide.'
                );
            }

            // Empêcher la rétrogradation du dernier admin.
            if (
                $cible['role'] === 'admin'
                && $nouveauRole !== 'admin'
                && count($administrateurs) <= 1
            ) {
                throw new DomainException(                    'Impossible de retirer les droits du dernier
administrateur.'
                );
            }

            $requete = $pdo->prepare(
                'UPDATE utilisateurs
                 SET role = :role,
                     admin = :admin
                 WHERE id = :id'
            );

            $requete->execute([
                'role' => $nouveauRole,
                'admin' => $nouveauRole === 'admin' ? 1 : 0,
                'id' => $idUtilisateur,
            ]);

            enregistrerActivite(
                $pdo,
                'role_modifie',
                $idConnecte,
                [
                    'utilisateur_cible' => $idUtilisateur,
                    'ancien_role' => $cible['role'],
                    'nouveau_role' => $nouveauRole,
                ]
            );

            $pdo->commit();

            redirigerAvecMessage(
                'Le rôle de l’utilisateur a été modifié.',
                'succes'
            );
        }

        // ---------------------------------------------
        // SUPPRIMER UN COMPTE
        // ---------------------------------------------

        if ($action === 'supprimer') {
            // Ne jamais supprimer le dernier administrateur.
            if (
                $cible['role'] === 'admin'
                && count($administrateurs) <= 1
            ) {
                throw new DomainException(                    'Impossible de supprimer le dernier administr
ateur.'
                );
            }

            /*
             * Enregistrer l'action avant la suppression.
             *
             * La référence vers le compte supprimé sera
             * conservée dans les détails du journal.
             */
            enregistrerActivite(
                $pdo,
                'utilisateur_supprime',
                $idConnecte,
                [
                    'utilisateur_cible' => $idUtilisateur,
                    'identifiant' => $cible['identifiant'],
                    'email' => $cible['email'],
                    'role' => $cible['role'],
                ]
            );

            $requete = $pdo->prepare(
                'DELETE FROM utilisateurs
                 WHERE id = :id'
            );

            $requete->execute([
                'id' => $idUtilisateur,
            ]);

            $pdo->commit();

            redirigerAvecMessage(
                'Le compte utilisateur a été supprimé.',
                'succes'
            );
        }

        throw new DomainException(
            'Action administrative inconnue.'
        );

    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirigerAvecMessage(
            $e->getMessage(),
            'erreur'
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Erreur de gestion utilisateur : '
            . $e->getMessage()
        );

        redirigerAvecMessage(
            'Une erreur technique est survenue.',
            'erreur'
        );
    }
}

// =====================================================
// RECHERCHE ET PAGINATION
// =====================================================

$recherche = trim((string) ($_GET['q'] ?? ''));

if (strlen($recherche) > 255) {
    $recherche = substr($recherche, 0, 255);
}

$page = filter_var(
    $_GET['page'] ?? '1',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$page = $page === false ? 1 : $page;

$parPage = 25;
$decalage = ($page - 1) * $parPage;

try {
    // Compter les utilisateurs correspondant à la recherche.
    if ($recherche !== '') {
        $requete = $pdo->prepare(
            'SELECT COUNT(*)
             FROM utilisateurs
             WHERE identifiant LIKE :recherche
                OR email LIKE :recherche'
        );

        $requete->execute([
            'recherche' => '%' . $recherche . '%',
        ]);
    } else {
        $requete = $pdo->query(
            'SELECT COUNT(*) FROM utilisateurs'
        );
    }

    $total = (int) $requete->fetchColumn();

    $totalPages = max(
        1,
        (int) ceil($total / $parPage)
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $decalage = ($page - 1) * $parPage;

    // Charger la page demandée.
    if ($recherche !== '') {
        $requete = $pdo->prepare(
            'SELECT
                id,
                identifiant,
                email,
                role,
                date_creation
             FROM utilisateurs
             WHERE identifiant LIKE :recherche
                OR email LIKE :recherche
             ORDER BY id DESC
             LIMIT :limite OFFSET :decalage'
        );

        $requete->bindValue(
            ':recherche',
            '%' . $recherche . '%',
            PDO::PARAM_STR
        );

        $requete->bindValue(
            ':limite',
            $parPage,
            PDO::PARAM_INT
        );

        $requete->bindValue(
            ':decalage',
            $decalage,
            PDO::PARAM_INT
        );

        $requete->execute();

    } else {
        $requete = $pdo->prepare(
            'SELECT
                id,
                identifiant,
                email,
                role,
                date_creation
             FROM utilisateurs
             ORDER BY id DESC
             LIMIT :limite OFFSET :decalage'
        );

        $requete->bindValue(
            ':limite',
            $parPage,
            PDO::PARAM_INT
        );

        $requete->bindValue(
            ':decalage',
            $decalage,
            PDO::PARAM_INT
        );

        $requete->execute();
    }

    $utilisateurs = $requete->fetchAll();

} catch (PDOException $e) {
    error_log(
        'Erreur de chargement des utilisateurs : '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Impossible de charger la liste des utilisateurs.');
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-sc
ale=1.0">

    <title>Gestion des utilisateurs | Administration</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 20px 5%;
            background: #172033;
            color: white;
        }

        header strong {
            font-size: 20px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        .navigation {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        main {
            width: min(1300px, 94%);
            margin: 40px auto;
        }

        h1 {
            margin-top: 0;
        }

        .description {
            color: #6b7280;
            line-height: 1.5;
        }

        .section {
            padding: 25px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .message {
            margin-bottom: 20px;
            padding: 14px;
            border-radius: 8px;
        }

        .message-succes {
            background: #f0fdf4;
            color: #166534;
        }

        .message-erreur {
            background: #fef2f2;
            color: #b91c1c;
        }

        .recherche {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .recherche input {
            flex: 1;
            min-width: 0;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }

        .bouton {
            display: inline-block;
            padding: 10px 13px;
            border: 0;
            border-radius: 7px;
            background: #315bea;
            color: white;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .bouton:hover {
            filter: brightness(.92);
        }

        .secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .danger {
            background: #dc2626;
            color: white;
        }

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
            padding: 13px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            color: #6b7280;
        }

        td {
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
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

        .badge-user {
            background: #e5e7eb;
            color: #374151;
        }

        .actions {
            min-width: 230px;
        }

        .actions-ligne {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .actions form {
            margin: 0;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 14px;
        }

        .aucun {
            padding: 20px 0;
            color: #6b7280;
        }

        @media (max-width: 750px) {
            header {
                align-items: flex-start;
                flex-direction: column;
            }

            .section {
                padding: 16px;
            }

            .recherche {
                flex-wrap: wrap;
            }

            .recherche input {
                flex-basis: 100%;
            }
        }
    </style>
</head>

<body>

<header>
    <strong>Administration</strong>

    <nav class="navigation">
        <a href="index.php">Tableau de bord</a>
        <a href="utilisateurs.php">Utilisateurs</a>
        <a href="../espace.php">Mon espace</a>
    </nav>
</header>

<main>

    <h1>Gestion des utilisateurs</h1>

    <p class="description">        Modifie les comptes, gère les rôles et supprime les compt
es
        qui ne sont plus nécessaires.
    </p>

    <section class="section">

        <?php if ($message !== ''): ?>

            <div
                class="message <?= $typeMessage === 'succes'
                    ? 'message-succes'
                    : 'message-erreur' ?>"
                role="status"
            >
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <?php if (isset($_GET['modifie'])): ?>
            <div class="message message-succes">
                Les informations du compte ont été modifiées.
            </div>
        <?php endif; ?>
        <form method="GET" action="utilisateurs.php" class="reche
rche">

            <input
                type="search"
                name="q"                placeholder="Rechercher un identifiant ou un e-ma
il..."
                value="<?= e($recherche) ?>"
                maxlength="255"
            >

            <button class="bouton" type="submit">
                Rechercher
            </button>

            <?php if ($recherche !== ''): ?>                <a class="bouton secondaire" href="utilisateurs.p
hp">
                    Réinitialiser
                </a>
            <?php endif; ?>

        </form>

        <?php if ($utilisateurs === []): ?>

            <p class="aucun">Aucun utilisateur trouvé.</p>

        <?php else: ?>

            <div class="table-responsive">

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Identifiant</th>
                            <th>E-mail</th>
                            <th>Rôle</th>
                            <th>Inscription</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($utilisateurs as $utilisateur)
: ?>

                        <tr>                            <td><?= (int) $utilisateur['id'] ?></
td>

                            <td>                                <?= e($utilisateur['identifiant']
) ?>
                            </td>

                            <td>
                                <?= e($utilisateur['email']) ?>
                            </td>

                            <td>                                <?php if ($utilisateur['role'] ==
= 'admin'): ?>
                                    <span class="badge badge-admi
n">
                                        Administrateur
                                    </span>

                                <?php elseif (                                    $utilisateur['role'] === 'mod
erator'
                                ): ?>
                                    <span class="badge badge-mode
rator">
                                        Modérateur
                                    </span>

                                <?php else: ?>
                                    <span class="badge badge-user
">
                                        Utilisateur
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>                                <?= e((string) $utilisateur['date
_creation']) ?>
                            </td>

                            <td class="actions">

                                <div class="actions-ligne">

                                    <a
                                        class="bouton secondaire"                                        href="modifier-utilisateu
r.php?id=<?= (int) $utilisateur['id'] ?>"
                                    >
                                        Modifier
                                    </a>

                                    <?php if (
                                        (int) $utilisateur['id']                                        !== (int) $_SESSION['util
isateur_id']
                                    ): ?>

                                        <?php if (                                            $utilisateur['role']
=== 'admin'
                                        ): ?>

                                            <form method="POST">
                                                <input
                                                    type="hidden"                                                    name="csrf_to
ken"                                                    value="<?= e(
$_SESSION['csrf_token']) ?>"
                                                >

                                                <input
                                                    type="hidden"                                                    name="id_util
isateur"                                                    value="<?= (i
nt) $utilisateur['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="role"
                                                >

                                                <input
                                                    type="hidden"                                                    name="nouveau
_role"
                                                    value="user"
                                                >

                                                <button                                                    class="bouton
 secondaire"
                                                    type="submit"                                                    onclick="return confirm('Retirer les droits administrateur de cet utilisateur
?');"
                                                >
                                                    Retirer admin
                                                </button>
                                            </form>

                                        <?php elseif (                                            $utilisateur['role']
=== 'user'
                                        ): ?>

                                            <form method="POST">
                                                <input
                                                    type="hidden"                                                    name="csrf_to
ken"                                                    value="<?= e(
$_SESSION['csrf_token']) ?>"
                                                >

                                                <input
                                                    type="hidden"                                                    name="id_util
isateur"                                                    value="<?= (i
nt) $utilisateur['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="role"
                                                >

                                                <input
                                                    type="hidden"                                                    name="nouveau
_role"
                                                    value="admin"
                                                >

                                                <button                                                    class="bouton
"
                                                    type="submit"                                                    onclick="return confirm('Accorder les droits administrateur à cet utilisateur
?');"
                                                >
                                                    Rendre admin
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <form method="POST">
                                            <input
                                                type="hidden"
                                                name="csrf_token"                                                value="<?= e($_SE
SSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"                                                name="id_utilisat
eur"                                                value="<?= (int)
$utilisateur['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="supprimer"
                                            >

                                            <button                                                class="bouton dan
ger"
                                                type="submit"                                                onclick="return confirm('ATTENTION : cette suppression est définitive. Supprimer c
e compte ?');"
                                            >
                                                Supprimer
                                            </button>
                                        </form>

                                    <?php else: ?>
                                        <span class="badge badge-
admin">
                                            Votre compte
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

            <div class="pagination">

                <div class="pagination-info">
                    <?= $total ?> résultat(s) — page
                    <?= $page ?> sur <?= $totalPages ?>
                </div>

                <div class="actions-ligne">

                    <?php if ($page > 1): ?>

                        <a
                            class="bouton secondaire"
                            href="?<?= http_build_query([
                                'q' => $recherche,
                                'page' => $page - 1,
                            ]) ?>"
                        >
                            ← Précédent
                        </a>

                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>

                        <a
                            class="bouton secondaire"
                            href="?<?= http_build_query([
                                'q' => $recherche,
                                'page' => $page + 1,
                            ]) ?>"
                        >
                            Suivant →
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
