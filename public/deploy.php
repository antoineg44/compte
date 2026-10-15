<?php
$output = [];
$returnCode = 0;

exec(
    '/usr/local/sbin/deploy-compte.partitions.ovh 2>&1',
    $output,
    $returnCode
);

echo implode("\n", $output);

if ($returnCode !== 0) {
    error_log('Échec du déploiement Git : ' . implode("\n", $output));
}
