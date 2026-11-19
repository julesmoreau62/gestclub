<?php
declare(strict_types=1);

return [
    'club_name' => 'Nom du club',
    'origin' => 'https://gestion.exemple.fr',
    // Adresse affichée aux familles et aux bénévoles pour exercer leurs droits sur leurs données.
    'privacy_contact' => 'secretariat@exemple.fr',
    // Jours de conservation d’un dossier d’inscription après sa validation ou son refus.
    // La suppression se fait chaque nuit par bin/cron.php (voir le guide d’installation).
    'retention_days' => 90,

    // Expéditeur des e-mails (accusés de réception, invitations, mots de passe, relances).
    // Utilise une adresse du domaine de l’hébergement OVHcloud, par exemple ne-pas-repondre@exemple.fr.
    // Laisse vide pour désactiver l’envoi : les liens d’invitation s’affichent alors à l’écran.
    'mail_from' => 'Nom du club <ne-pas-repondre@exemple.fr>',

    // Rôles qui doivent activer la double authentification avant d’accéder au tableau de bord.
    // Recommandé : ['director', 'treasurer']. Laisse [] pour la rendre facultative.
    'require_2fa' => [],

    // Identité du club imprimée sur les attestations, convocations et reçus.
    'association' => [
        'address' => "1 rue du Stade\n75000 Paris",
        'rna' => 'W000000000',
        'siren' => '',
        'object' => 'Pratique et promotion du football',
        'signatory' => 'Prénom Nom, président',
        // true uniquement si le club peut délivrer des reçus fiscaux (organisme d’intérêt général).
        'fiscal_receipts' => false,
    ],

    // Clé API HelloAsso (rubrique API) pour enregistrer les adhésions payées en ligne.
    // Laisse vide pour ne pas utiliser la synchronisation.
    'helloasso' => [
        'client_id' => '',
        'client_secret' => '',
    ],

    'postgres' => [
        'dsn' => 'pgsql:host=nom-hote-ovh;port=5432;dbname=nom-base;sslmode=require',
        'user' => 'utilisateur-ovh',
        'password' => 'mot-de-passe-ovh',
    ],
];
