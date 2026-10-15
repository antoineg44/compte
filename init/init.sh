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

# Autorisation sudo limitée à cette seule commande
cat > "$SUDOERS_FILE" <<EOF
www-data ALL=($DEPLOY_USER) NOPASSWD: $DEPLOY_HELPER
EOF

chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"

if ! visudo -cf "$SUDOERS_FILE"; then
    echo "Erreur dans la configuration sudoers."
    rm -f "$SUDOERS_FILE"
    exit 1
fi

# Génération de la page avec l'utilisateur propre à ce site
cat > "$DEPLOY_PAGE" <<EOF
<?php
\$output = [];
\$returnCode = 0;

exec(
    'sudo -n -H -u $DEPLOY_USER $DEPLOY_HELPER 2>&1',
    \$output,
    \$returnCode
);

echo implode("\n", \$output);

if (\$returnCode !== 0) {
    error_log('Échec du déploiement Git : ' . implode("\n", \$output));
}
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
echo "Commande de déploiement autorisée :"
echo "sudo -n -H -u $DEPLOY_USER $DEPLOY_HELPER"
