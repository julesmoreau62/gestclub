<?php
declare(strict_types=1);

// Point d’entrée de la tâche planifiée quotidienne d’OVHcloud, qui lance un script sans argument.
// Équivaut à « php bin/gestclub.php daily » : purge, rappel au secrétariat, synchronisation HelloAsso.
$argv = [__FILE__, 'daily'];
require __DIR__ . '/gestclub.php';
