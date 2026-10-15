<?php
$output = [];
$returnCode = 0;

exec(
    'sudo /usr/local/sbin/deploy-NOM_DU_SITE 2>&1',
    $output,
    $returnCode
);

echo implode("\n", $output);

if ($returnCode !== 0) {
    error_log('Échec du déploiement Git : ' . implode("\n", $output));
}
