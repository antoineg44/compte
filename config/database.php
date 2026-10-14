<?php

declare(strict_types=1);

$host = 'localhost';
$dbname = 'compte';
$username = 'compte_app';
$password = '3]?_o>W&ArRbw0q]xt-GIl*Osly(+"jrH[Lo6?Z_';

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        $options
    );
} catch (PDOException $e) {
    error_log('Erreur de connexion à la base de données : ' . $e-
>getMessage());

    http_response_code(500);
    exit('Une erreur interne est survenue.');
}
