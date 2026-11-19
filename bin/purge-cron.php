<?php
declare(strict_types=1);

// Point d’entrée pour une tâche planifiée OVHcloud, qui lance un script sans argument.
// Équivaut à « php bin/gestclub.php purge » avec la durée de conservation de config.php.
$argv = [__FILE__, 'purge'];
require __DIR__ . '/gestclub.php';
