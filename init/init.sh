#!/usr/bin/env bash
set -Eeuo pipefail

# Usage :
# sudo ./init.sh site-exemple.domaine.fr

if [[ $EUID -ne 0 ]]; then
    echo "Erreur : lance ce script avec sudo."
    exit 1
fi

if [[ $# -ne 1 ]]; then
    echo "Usage : sudo $0 domaine.fr"
    exit 1
fi

DOMAIN="$1"
SITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INIT_DIR="$SITE_DIR/init"
APACHE_CONF="${DOMAIN}-le-ssl.conf"
SOURCE_CONF="$INIT_DIR/$APACHE_CONF"
DEST_CONF="/etc/apache2/sites-available/$APACHE_CONF"

SITE_NAME="$(basename "$SITE_DIR")"
DEPLOY_USER="deploy-${SITE_NAME}"
SITE_GROUP="$SITE_NAME"
DEPLOY_HELPER="/usr/local/sbin/deploy-${SITE_NAME}"
SUDOERS_FILE="/etc/sudoers.d/deploy-${SITE_NAME}"
DEPLOY_PAGE="$SITE_DIR/public/deploy.php"
DEPLOY_SOCKET="/run/deploy-${SITE_NAME}.sock"
DEPLOY_WORKER="/usr/local/sbin/deploy-${SITE_NAME}-worker"
SOCKET_UNIT="deploy-${SITE_NAME}.socket"
SERVICE_UNIT="deploy-${SITE_NAME}@.service"

echo "== Initialisation du site : $SITE_NAME =="
echo "Domaine : $DOMAIN"
echo "Répertoire : $SITE_DIR"

# Vérifications préalables
command -v apache2ctl >/dev/null || {
    echo "Erreur : Apache2 n'est pas installé."
    exit 1
}

command -v git >/dev/null || {
    echo "Erreur : Git n'est pas installé."
    exit 1
}

for required_command in systemctl timeout flock; do
    command -v "$required_command" >/dev/null || {
        echo "Erreur : commande absente : $required_command"
        exit 1
    }
done

[[ -d "$SITE_DIR/.git" ]] || {
    echo "Erreur : aucun dépôt Git trouvé dans $SITE_DIR"
    exit 1
}

[[ -f "$SOURCE_CONF" ]] || {
    echo "Erreur : fichier Apache absent : $SOURCE_CONF"
    exit 1
}

# Création du groupe et de l'utilisateur de déploiement
getent group "$SITE_GROUP" >/dev/null ||
    groupadd "$SITE_GROUP"

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    useradd \
        --system \
        --gid "$SITE_GROUP" \
        --home-dir "/var/lib/$DEPLOY_USER" \
        --create-home \
        --shell /usr/sbin/nologin \
        "$DEPLOY_USER"
fi

DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)"
if [[ -z "$DEPLOY_HOME" || ! -d "$DEPLOY_HOME" ]]; then
    echo "Erreur : répertoire personnel absent pour $DEPLOY_USER."
    exit 1
fi

# Apache doit pouvoir lire les fichiers du site
usermod -aG "$SITE_GROUP" www-data

# Le code et le dépôt Git appartiennent à l'utilisateur de déploiement
chown -R "$DEPLOY_USER:$SITE_GROUP" "$SITE_DIR"

# Répertoires : accès propriétaire et groupe, setgid conservé
find "$SITE_DIR" -type d -exec chmod 2750 {} +

# Fichiers : lecture propriétaire et groupe
find "$SITE_DIR" -type f -exec chmod 0640 {} +

# Les scripts du dépôt qui doivent être exécutables :
# rétablir leurs permissions après l'initialisation si nécessaire.
chmod 0750 "$INIT_DIR/init.sh"

# Copie de la configuration Apache avec sauvegarde éventuelle
if [[ -f "$DEST_CONF" ]]; then
    cp -a "$DEST_CONF" "${DEST_CONF}.bak.$(date +%Y%m%d%H%M%S)"
fi

install -o root -g root -m 0644 "$SOURCE_CONF" "$DEST_CONF"

# Activation des modules et du site
a2enmod ssl rewrite headers
a2ensite "$APACHE_CONF"

# Création d'une commande de déploiement fixe, sans argument utilisateur
cat > "$DEPLOY_HELPER" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail

# Autoriser ce dépôt pour Git
/usr/bin/git config --global --add safe.directory '$SITE_DIR'

# Mettre à jour le dépôt
exec /usr/bin/git -C '$SITE_DIR' pull --ff-only
EOF

chown root:root "$DEPLOY_HELPER"
chmod 0755 "$DEPLOY_HELPER"

# Le protocole renvoie le code de sortie, puis la sortie du déploiement.
cat > "$DEPLOY_WORKER" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail

status=0
output=\$(/usr/bin/timeout 90 /usr/bin/flock -n -E 75 "\$HOME/.deploy.lock" '$DEPLOY_HELPER' </dev/null 2>&1) || status=\$?
if [[ \$status -eq 75 ]]; then
    output="Un deploiement est deja en cours."
elif [[ \$status -eq 124 ]]; then
    output="Delai de deploiement depasse (90 secondes). \$output"
fi
printf '%s\n%s\n' "\$status" "\$output"
EOF

chown root:root "$DEPLOY_WORKER"
chmod 0755 "$DEPLOY_WORKER"

cat > "/etc/systemd/system/$SOCKET_UNIT" <<EOF
[Unit]
Description=Socket de deploiement $SITE_NAME

[Socket]
ListenStream=$DEPLOY_SOCKET
SocketUser=$DEPLOY_USER
SocketGroup=www-data
SocketMode=0660
Accept=yes
RemoveOnStop=yes

[Install]
WantedBy=sockets.target
EOF

cat > "/etc/systemd/system/$SERVICE_UNIT" <<EOF
[Unit]
Description=Deploiement $SITE_NAME

[Service]
User=$DEPLOY_USER
Group=$SITE_GROUP
Environment="HOME=$DEPLOY_HOME"
Environment=GIT_TERMINAL_PROMPT=0
ExecStart=$DEPLOY_WORKER
StandardInput=socket
StandardOutput=inherit
StandardError=journal
RuntimeMaxSec=95
NoNewPrivileges=yes
EOF

chown root:root "/etc/systemd/system/$SOCKET_UNIT" "/etc/systemd/system/$SERVICE_UNIT"
chmod 0644 "/etc/systemd/system/$SOCKET_UNIT" "/etc/systemd/system/$SERVICE_UNIT"
systemctl daemon-reload
systemctl enable "$SOCKET_UNIT"
systemctl restart "$SOCKET_UNIT"
systemctl is-active --quiet "$SOCKET_UNIT"

# Supprimer l'ancienne autorisation : PHP n'utilise plus sudo.
rm -f "$SUDOERS_FILE"

# Génération de la page avec l'utilisateur propre à ce site
cat > "$DEPLOY_PAGE" <<EOF
<?php
\$socketPath = '$DEPLOY_SOCKET';
EOF
cat >> "$DEPLOY_PAGE" <<'EOF'

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
EOF

chown "$DEPLOY_USER:$SITE_GROUP" "$DEPLOY_PAGE"
chmod 0640 "$DEPLOY_PAGE"

# Vérification Apache avant le rechargement
if ! apache2ctl configtest; then
    echo "Erreur de configuration Apache."
    echo "Le serveur n'a pas été rechargé."
    exit 1
fi

# Redémarrage pour appliquer le nouveau groupe de www-data
systemctl restart apache2

echo
echo "Initialisation terminée."
echo "Site Apache : $APACHE_CONF"
echo "Dépôt Git : $SITE_DIR"
echo "Utilisateur de déploiement : $DEPLOY_USER"
echo
echo "Socket de déploiement : $DEPLOY_SOCKET"
echo "État du service : systemctl status $SOCKET_UNIT"
