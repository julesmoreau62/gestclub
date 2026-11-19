<?php
declare(strict_types=1);

return [
    'club_name' => 'Nom du club',
    'origin' => 'https://gestion.exemple.fr',
    // Adresse affichée aux familles et aux bénévoles pour exercer leurs droits sur leurs données.
    'privacy_contact' => 'secretariat@exemple.fr',
    // Jours de conservation d’un dossier d’inscription après sa validation ou son refus.
    // La suppression se fait avec « php bin/gestclub.php purge », à planifier chaque nuit.
    'retention_days' => 90,
    'postgres' => [
        'dsn' => 'pgsql:host=nom-hote-ovh;port=5432;dbname=nom-base;sslmode=require',
        'user' => 'utilisateur-ovh',
        'password' => 'mot-de-passe-ovh',
    ],
];
