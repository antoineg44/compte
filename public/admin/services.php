<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 2) . '/config/activity.php';

$message = '';
$erreur = '';

/**
 * Vérifie un slug de service.
 */
function slugServiceValide(string $slug): bool
{
    return (bool) preg_match(
        '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
        $slug
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!verifierCsrf($csrf)) {
        $erreur = 'Le formulaire a expiré. Veuillez réessayer.';
    } else {
        try {
            if ($action === 'creer') {
                $nom = trim((string) ($_POST['nom'] ?? ''));
                $slug = trim((string) ($_POST['slug'] ?? ''));
                $description = trim(
                    (string) ($_POST['description'] ?? '')
                );

                if (
                    $nom === ''
                    || mb_strlen($nom) > 100
                    || !slugServiceValide($slug)
                    || mb_strlen($slug) > 100
                    || mb_strlen($description) > 255
                ) {
                    throw new InvalidArgumentException(                        'Vérifie le nom, le slug et la descriptio
n.'
                    );
                }

                $requete = $pdo->prepare(
                    'INSERT INTO services
                        (nom, slug, description, actif)
                     VALUES
                        (:nom, :slug, :description, 1)'
                );

                $requete->execute([
                    'nom' => $nom,
                    'slug' => $slug,
                    'description' => $description !== ''
                        ? $description
                        : null,
                ]);

                enregistrerActivite(
                    $pdo,
                    'service_cree',
                    (int) $_SESSION['utilisateur_id'],
                    [                        'service_id' => (int) $pdo->lastInsertId(
),
                        'slug' => $slug,
                    ]
                );

                $message = 'Le service a été créé.';

            } elseif ($action === 'modifier') {
                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $nom = trim((string) ($_POST['nom'] ?? ''));
                $slug = trim((string) ($_POST['slug'] ?? ''));
                $description = trim(
                    (string) ($_POST['description'] ?? '')
                );

                if (
                    $id === false
                    || $id === null
                    || $id < 1
                    || $nom === ''
                    || mb_strlen($nom) > 100
                    || !slugServiceValide($slug)
                    || mb_strlen($slug) > 100
                    || mb_strlen($description) > 255
                ) {
                    throw new InvalidArgumentException(                        'Les informations du service sont invalid
es.'
                    );
                }

                $requete = $pdo->prepare(
                    'UPDATE services
                     SET nom = :nom,
                         slug = :slug,
                         description = :description
                     WHERE id = :id'
                );

                $requete->execute([
                    'nom' => $nom,
                    'slug' => $slug,
                    'description' => $description !== ''
                        ? $description
                        : null,
                    'id' => $id,
                ]);

                enregistrerActivite(
                    $pdo,
                    'service_modifie',
                    (int) $_SESSION['utilisateur_id'],
                    [
                        'service_id' => $id,
                        'slug' => $slug,
                    ]
                );

                $message = 'Le service a été modifié.';

            } elseif ($action === 'basculer') {
                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id === null || $id < 1) {
                    throw new InvalidArgumentException(
                        'Identifiant de service invalide.'
                    );
                }

                $requete = $pdo->prepare(
                    'UPDATE services
                     SET actif = CASE
                         WHEN actif = 1 THEN 0
                         ELSE 1
                     END
                     WHERE id = :id'
                );

                $requete->execute(['id' => $id]);

                if ($requete->rowCount() === 0) {
                    $verification = $pdo->prepare(
                        'SELECT id FROM services WHERE id = :id'
                    );

                    $verification->execute(['id' => $id]);

                    if (!$verification->fetchColumn()) {
                        throw new InvalidArgumentException(
                            'Ce service n’existe pas.'
                        );
                    }
                }

                enregistrerActivite(
                    $pdo,
                    'service_statut_modifie',
                    (int) $_SESSION['utilisateur_id'],
                    ['service_id' => $id]
                );

                $message = 'Le statut du service a été modifié.';

            } else {
                throw new InvalidArgumentException(
                    'Action inconnue.'
                );
            }
        } catch (InvalidArgumentException $e) {
            $erreur = $e->getMessage();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $erreur =                    'Ce nom ou cet identifiant technique est déjà
 utilisé.';
            } else {
                error_log(
                    'Erreur de gestion des services : '
                    . $e->getMessage()
                );

                $erreur =
                    'Une erreur technique est survenue.';
            }
        } catch (Throwable $e) {
            error_log(
                'Erreur de gestion des services : '
                . $e->getMessage()
            );

            $erreur = 'Une erreur technique est survenue.';
        }
    }
}

$requete = $pdo->query(
    'SELECT
        s.id,
        s.nom,
        s.slug,
        s.description,
        s.actif,
        s.date_creation,
        (
            SELECT COUNT(*)
            FROM acces_utilisateur_services a
            WHERE a.service_id = s.id
              AND a.autorise = 1
        ) AS nombre_autorisations
     FROM services s
     ORDER BY s.nom ASC'
);

$services = $requete->fetchAll();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">    <meta name="viewport" content="width=device-width, initial-sc
ale=1.0">

    <title>Gestion des services | Administration</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        main {
            width: min(1150px, 100%);
            margin: 0 auto;
        }

        h1 { margin-bottom: 8px; }

        .description {
            color: #6b7280;
            line-height: 1.5;
        }

        .section {
            margin-top: 25px;
            padding: 25px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .grille {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 600;
        }

        input, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        button, .lien {
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

        .secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .alerte {
            margin: 15px 0;
            padding: 12px;
            border-radius: 7px;
        }

        .succes {
            background: #dcfce7;
            color: #166534;
        }

        .danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-responsive { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            color: #6b7280;
        }

        .champ-table {
            min-width: 150px;
            margin-bottom: 8px;
        }

        .statut {
            font-weight: 700;
        }

        .actif { color: #15803d; }
        .inactif { color: #b91c1c; }

        .form-inline { margin-top: 8px; }

        .navigation { margin-bottom: 20px; }

        .navigation a {
            margin-right: 15px;
            color: #315bea;
            text-decoration: none;
        }

        @media (max-width: 700px) {
            body { padding: 12px; }
            .grille { grid-template-columns: 1fr; }
            .section { padding: 15px; }
        }
    </style>
</head>
<body>
<main>

    <nav class="navigation">
        <a href="index.php">Tableau de bord</a>
        <a href="utilisateurs.php">Utilisateurs</a>
        <a href="acces-services.php">Droits d'accès</a>
    </nav>

    <h1>Gestion des services</h1>

    <p class="description">
        Crée et configure les services disponibles sur ton site.        Un service désactivé est inaccessible à tous les utilisat
eurs.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alerte succes">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($erreur !== ''): ?>
        <div class="alerte danger">
            <?= e($erreur) ?>
        </div>
    <?php endif; ?>

    <section class="section">
        <h2>Ajouter un service</h2>

        <p class="description">            Le slug est l'identifiant technique utilisé dans le c
ode PHP.            Exemple : <code>documents</code> ou <code>statistique
s</code>.
        </p>

        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input type="hidden" name="action" value="creer">

            <div class="grille">
                <div>
                    <label for="nom">Nom du service</label>
                    <input
                        id="nom"
                        name="nom"
                        maxlength="100"
                        required
                    >
                </div>

                <div>
                    <label for="slug">Slug</label>
                    <input
                        id="slug"
                        name="slug"
                        maxlength="100"
                        pattern="[a-z0-9]+(-[a-z0-9]+)*"
                        placeholder="mon-service"
                        required
                    >
                </div>
            </div>

            <p>
                <label for="description">Description</label>
                <textarea
                    id="description"
                    name="description"
                    maxlength="255"
                ></textarea>
            </p>

            <button type="submit">Créer le service</button>
        </form>
    </section>

    <section class="section">
        <h2>Services existants</h2>

        <?php if ($services === []): ?>
            <p>Aucun service enregistré.</p>
        <?php else: ?>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Slug</th>
                            <th>Autorisations</th>
                            <th>Statut</th>
                            <th>Modifier</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td>                                <strong><?= e($service['nom']) ?>
</strong>

                                <p>
                                    <?= e(                                        (string) ($service['descr
iption'] ?? '')
                                    ) ?>
                                </p>
                            </td>

                            <td>                                <code><?= e($service['slug']) ?><
/code>
                            </td>

                            <td>                                <?= (int) $service['nombre_autori
sations'] ?>
                                accès autorisé(s)
                            </td>

                            <td>                                <span class="statut <?= (int) $se
rvice['actif'] === 1
                                    ? 'actif'
                                    : 'inactif' ?>">                                    <?= (int) $service['actif'] =
== 1
                                        ? 'Actif'
                                        : 'Désactivé' ?>
                                </span>
                                <form method="post" class="form-i
nline">
                                    <input
                                        type="hidden"
                                        name="csrf_token"                                        value="<?= e($_SESSION['c
srf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="basculer"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"                                        value="<?= (int) $service
['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="secondaire"
                                    >                                        <?= (int) $service['actif
'] === 1
                                            ? 'Désactiver'
                                            : 'Activer' ?>
                                    </button>
                                </form>
                            </td>

                            <td>
                                <form method="post">
                                    <input
                                        type="hidden"
                                        name="csrf_token"                                        value="<?= e($_SESSION['c
srf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="modifier"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"                                        value="<?= (int) $service
['id'] ?>"
                                    >

                                    <label>
                                        Nom
                                        <input
                                            class="champ-table"
                                            name="nom"
                                            maxlength="100"                                            value="<?= e($service
['nom']) ?>"
                                            required
                                        >
                                    </label>

                                    <label>
                                        Slug
                                        <input
                                            class="champ-table"
                                            name="slug"
                                            maxlength="100"                                            pattern="[a-z0-9]+(-[
a-z0-9]+)*"                                            value="<?= e($service
['slug']) ?>"
                                            required
                                        >
                                    </label>

                                    <label>
                                        Description
                                        <textarea
                                            class="champ-table"
                                            name="description"
                                            maxlength="255"
                                        ><?= e(
                                            (string) ($service['description'] ?? '')
                                        ) ?></textarea>
                                    </label>

                                    <button type="submit">Enregistrer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>
</body>
</html>
