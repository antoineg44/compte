<?php declare(strict_types=1); require_once __DIR__ . '/_auth.php'; require_once dirname(__DIR__, 2) . '/config/services.php'; $message = ''; $typeMessage = ''; $nouveauLien = null; // Services disponibles et URL de connexion correspondante. 
$services = [ 'video' => [ 'label' => 'Vidéo', 'url' => 'https://video.partitions.ovh/sso/lien.php', ], 'music' => [ 'label' => 'Musique', 'url' => 'https://musiques.partitions.ovh/php/sso/lien.php', ], 'partitions' => [ 'label' => 'Partitions', 'url' => 'https://app.partitions.ovh/sso/lien.php', ], 'photo' => [ 'label' => 'Photo', 'url' => 'https://photo.partitions.ovh/sso/lien.php', ], ]; // Vérifier l'identifiant utilisateur. 
$idUtilisateur = filter_var( $_GET['id'] ?? $_POST['id_utilisateur'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]] ); if ($idUtilisateur === false || $idUtilisateur === null) { http_response_code(400); exit('Identifiant utilisateur invalide.'); } $idUtilisateur = (int) $idUtilisateur; // Récupérer l'utilisateur. 
$requete = $pdo->prepare( 'SELECT id, identifiant, email FROM utilisateurs WHERE id = :id LIMIT 1' ); $requete->execute([ 'id' => $idUtilisateur, ]); $utilisateur = $requete->fetch(); if (!$utilisateur) { http_response_code(404); exit('Utilisateur introuvable.'); } // ===================================================== // TRAITEMENT DES ACTIONS // ===================================================== 
   if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!verifierCsrf((string) ($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Requête invalide. Recharge la page.'); } $action = (string) ($_POST['action'] ?? ''); try { // --------------------------------------------- // CRÉATION D'UN LIEN // --------------------------------------------- 
                                                                                                                                                                                                                                      if ($action === 'creer') { $service = (string) ($_POST['service'] ?? ''); if (!isset($services[$service])) { throw new DomainException('Service invalide.'); } // Vérifier les droits du compte sur le service. 
                                                                                                                                                                                                                                         if (!utilisateurAAccesService( $pdo, $idUtilisateur, $service )) { throw new DomainException( 'Cet utilisateur ne possède pas les droits nécessaires pour accéder à ce service.' ); } // Générer une clé aléatoire de 256 bits. 
                                                                                                                                                                                                                                            $cle = rtrim( strtr( base64_encode(random_bytes(32)), '+/', '-_' ), '=' ); // Seule l'empreinte SHA-256 est stockée. 
                                                                                                                                                                                                                                                                $cleHash = hash('sha256', $cle); $requete = $pdo->prepare( 'INSERT INTO sso_liens (cle_hash, utilisateur_id, service) VALUES (:cle_hash, :utilisateur_id, :service)' ); $requete->execute([ 'cle_hash' => $cleHash, 'utilisateur_id' => $idUtilisateur, 'service' => $service, ]); // Construire l'URL complète. 
                                                                                                                                                                                                                                                                $nouveauLien = $services[$service]['url'] . '?cle=' . rawurlencode($cle); $message = 'Le lien a été créé avec succès.'; $typeMessage = 'succes'; } // --------------------------------------------- // ACTIVER / DÉSACTIVER UN LIEN // --------------------------------------------- 
                                                                                                                                                                                                                                      elseif ($action === 'statut') { $idLien = filter_var( $_POST['id_lien'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]] ); if ($idLien === false || $idLien === null) { throw new DomainException('Lien invalide.'); } $requete = $pdo->prepare( 'UPDATE sso_liens SET actif = IF(actif = 1, 0, 1) WHERE id = :id AND utilisateur_id = :utilisateur_id' ); $requete->execute([ 'id' => $idLien, 'utilisateur_id' => $idUtilisateur, ]); if ($requete->rowCount() !== 1) { throw new DomainException( 'Impossible de modifier ce lien.' ); } $message = 'Le statut du lien a été modifié.'; $typeMessage = 'succes'; } // --------------------------------------------- // SUPPRIMER UN LIEN // --------------------------------------------- 
                                                                                                                                                                                                                                      elseif ($action === 'supprimer') { $idLien = filter_var( $_POST['id_lien'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]] ); if ($idLien === false || $idLien === null) { throw new DomainException('Lien invalide.'); } $requete = $pdo->prepare( 'DELETE FROM sso_liens WHERE id = :id AND utilisateur_id = :utilisateur_id' ); $requete->execute([ 'id' => $idLien, 'utilisateur_id' => $idUtilisateur, ]); if ($requete->rowCount() !== 1) { throw new DomainException( 'Impossible de supprimer ce lien.' ); } $message = 'Le lien a été supprimé définitivement.'; $typeMessage = 'succes'; } else { throw new DomainException('Action inconnue.'); } } catch (DomainException $e) { $message = $e->getMessage(); $typeMessage = 'erreur'; } catch (Throwable $e) { error_log( 'Erreur gestion liens SSO : ' . $e->getMessage() ); $message = 'Une erreur technique est survenue.'; $typeMessage = 'erreur'; } } // ===================================================== // RÉCUPÉRER LES LIENS EXISTANTS // ===================================================== 
      $requete = $pdo->prepare( 'SELECT id, service, actif, date_creation, date_expiration FROM sso_liens WHERE utilisateur_id = :utilisateur_id ORDER BY id DESC' ); $requete->execute([ 'utilisateur_id' => $idUtilisateur, ]); $liens = $requete->fetchAll(); ?>

<!DOCTYPE html>

<html lang="fr">

<head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1">

<title>Liens SSO | Administration</title>

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

    nav {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    main {
        width: min(1100px, 94%);
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
        margin-bottom: 25px;
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

    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
    }

    select {
        width: 100%;
        max-width: 400px;
        padding: 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: white;
    }

    .bouton {
        display: inline-block;
        padding: 10px 14px;
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
    }

    .activer {
        background: #059669;
    }

    .desactiver {
        background: #d97706;
    }

    .nouveau-lien {
        margin: 20px 0;
        padding: 20px;
        border: 2px solid #16a34a;
        border-radius: 10px;
        background: #f0fdf4;
    }

    .nouveau-lien input {
        width: 100%;
        margin: 12px 0;
        padding: 12px;
        border: 1px solid #bbf7d0;
        border-radius: 7px;
        background: white;
    }

    .avertissement {
        color: #92400e;
        font-size: 13px;
        line-height: 1.5;
    }

    .table-responsive {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    th, td {
        padding: 13px 10px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 13px;
    }

    th {
        background: #f8fafc;
        color: #6b7280;
    }

    .badge {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    .badge-actif {
        background: #dcfce7;
        color: #166534;
    }

    .badge-inactif {
        background: #fee2e2;
        color: #991b1b;
    }

    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .aucun {
        color: #6b7280;
    }

    @media(max-width: 650px) {
        header {
            align-items: flex-start;
            flex-direction: column;
        }

        .section {
            padding: 16px;
        }
    }
</style>


</head>

<body>

<header> <strong>Administration</strong>

<nav>
    <a href="index.php">Tableau de bord</a>
    <a href="utilisateurs.php">Utilisateurs</a>
    <a href="../index.php">Mon espace</a>
</nav>


</header>

<main>

<h1>Gestion des liens SSO</h1>

<p class="description">
    Gestion des accès automatiques pour :
    <strong><?= e($utilisateur['identifiant']) ?></strong>
    (<?= e($utilisateur['email']) ?>)
</p>

<?php if ($message !== ''): ?>

    <div class="message <?= $typeMessage === 'succes'
        ? 'message-succes'
        : 'message-erreur' ?>">
        <?= e($message) ?>
    </div>

<?php endif; ?>

<?php if ($nouveauLien !== null): ?>

    <div class="nouveau-lien">

        <h2>🎉 Nouveau lien créé</h2>

        <p>
            Voici le lien secret. Copie-le et conserve-le
            immédiatement : il ne sera plus affiché.
        </p>

        <input
            type="text"
            id="nouveauLien"
            value="<?= e($nouveauLien) ?>"
            readonly
        >

        <button
            type="button"
            class="bouton"
            onclick="
                const champ = document.getElementById('nouveauLien');
                champ.select();
                navigator.clipboard.writeText(champ.value);
                this.textContent = 'Copié !';
            "
        >
            Copier le lien
        </button>

        <p class="avertissement">
            Toute personne possédant ce lien pourra se connecter
            avec ce compte et accéder au service concerné.
            Ne le partage qu'avec une personne de confiance.
        </p>

    </div>

<?php endif; ?>

<section class="section">

    <h2>Créer un nouveau lien</h2>

    <p class="description">
        Sélectionne le service auquel ce lien donnera accès.
    </p>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <input
            type="hidden"
            name="id_utilisateur"
            value="<?= (int) $utilisateur['id'] ?>"
        >

        <input
            type="hidden"
            name="action"
            value="creer"
        >

        <label for="service">Service</label>

        <select name="service" id="service" required>

            <?php foreach ($services as $cle => $service): ?>

                <option value="<?= e($cle) ?>">
                    <?= e($service['label']) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <br><br>

        <button
            type="submit"
            class="bouton"
            onclick="return confirm('Créer un nouveau lien secret pour cet utilisateur ?');"
        >
            + Générer un lien secret
        </button>

    </form>

</section>

<section class="section">

    <h2>Liens existants</h2>

    <?php if ($liens === []): ?>

        <p class="aucun">
            Aucun lien secret n'a encore été créé pour cet utilisateur.
        </p>

    <?php else: ?>

        <div class="table-responsive">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Création</th>
                        <th>Expiration</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($liens as $lien): ?>

                    <tr>

                        <td><?= (int) $lien['id'] ?></td>

                        <td>
                            <?= e(
                                $services[$lien['service']]['label']
                                ?? $lien['service']
                            ) ?>
                        </td>

                        <td>
                            <?= e((string) $lien['date_creation']) ?>
                        </td>

                        <td>
                            <?= $lien['date_expiration'] !== null
                                ? e((string) $lien['date_expiration'])
                                : 'Aucune' ?>
                        </td>

                        <td>

                            <?php if ((int) $lien['actif'] === 1): ?>

                                <span class="badge badge-actif">
                                    Actif
                                </span>

                            <?php else: ?>

                                <span class="badge badge-inactif">
                                    Désactivé
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <div class="actions">

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_utilisateur"
                                        value="<?= (int) $utilisateur['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_lien"
                                        value="<?= (int) $lien['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="statut"
                                    >

                                    <button
                                        type="submit"
                                        class="bouton <?= (int) $lien['actif'] === 1
                                            ? 'desactiver'
                                            : 'activer' ?>"
                                    >
                                        <?= (int) $lien['actif'] === 1
                                            ? 'Désactiver'
                                            : 'Réactiver' ?>
                                    </button>

                                </form>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Supprimer définitivement ce lien secret ?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_utilisateur"
                                        value="<?= (int) $utilisateur['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_lien"
                                        value="<?= (int) $lien['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="supprimer"
                                    >

                                    <button
                                        type="submit"
                                        class="bouton danger"
                                    >
                                        Supprimer
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</section>

<a href="utilisateurs.php" class="bouton secondaire">
    ← Retour aux utilisateurs
</a>


</main>

</body> </html>
