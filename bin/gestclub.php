<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/server/state.php';

function emptySeason(): array
{
    return ['opening' => ['bank' => 0, 'cash' => 0], 'entries' => [], 'invoices' => [],
        'plan' => ['income' => [], 'expense' => []], 'expenses' => [], 'bankLines' => [],
        'meetings' => [], 'tasks' => [], 'decisions' => [], 'volunteers' => [], 'events' => [], 'partners' => [], 'partnerCommitments' => []];
}

try {
    $command = $argv[1] ?? 'help';
    switch ($command) {
        case 'install':
            $database = db();
            $database->beginTransaction();
            $database->exec(file_get_contents(dirname(__DIR__) . '/sql/001-install.sql'));
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
            $year = (int) $now->format('Y') - ((int) $now->format('n') < 9 ? 1 : 0);
            $query = $database->prepare(
                'INSERT INTO gestclub_state (records, bureau) VALUES (CAST(? AS jsonb), CAST(? AS jsonb)) ON CONFLICT (id) DO NOTHING'
            );
            $query->execute([jsonEncode([$year - 1 => [], $year => []]),
                jsonEncode([$year - 1 => emptySeason(), $year => emptySeason()])]);
            $database->commit();
            echo "Base prête.\n";
            break;
        case 'account':
            $email = strtolower(trim($argv[2] ?? ''));
            $roles = array_values(array_unique(explode(',', $argv[3] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || array_diff($roles, GESTCLUB_ROLES)) {
                throw new InvalidArgumentException('Usage : account adresse@email.fr director,treasurer,secretary,equipment_manager');
            }
            $password = bin2hex(random_bytes(16));
            $query = db()->prepare(
                'INSERT INTO gestclub_users (email, password_hash, roles) VALUES (?, ?, CAST(? AS jsonb))
                 ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, roles = EXCLUDED.roles,
                 active = true, session_version = gestclub_users.session_version + 1'
            );
            $query->execute([$email, password_hash($password, PASSWORD_DEFAULT), jsonEncode($roles)]);
            echo "Mot de passe généré pour $email : $password\n";
            break;
        case 'disable':
            $query = db()->prepare('UPDATE gestclub_users SET active = false, session_version = session_version + 1 WHERE email = ?');
            $query->execute([strtolower(trim($argv[2] ?? ''))]);
            echo $query->rowCount() ? "Compte désactivé.\n" : "Compte introuvable.\n";
            break;
        case 'season':
            $year = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 9998]]);
            if ($year === false) throw new InvalidArgumentException('Indique l’année de début de saison, par exemple 2027.');
            $database = db();
            $database->beginTransaction();
            $state = fetchState($database, true);
            if (!isset($state['records'][$year])) {
                $state['records'][$year] = [];
                $state['bureau'][$year] = emptySeason();
                $query = $database->prepare(
                    'UPDATE gestclub_state SET records = CAST(? AS jsonb), bureau = CAST(? AS jsonb),
                     revision = revision + 1, staff_revision = revision + 1, updated_at = now() WHERE id = true'
                );
                $query->execute([jsonEncode($state['records']), jsonEncode($state['bureau'])]);
            }
            $database->commit();
            echo "Saison disponible.\n";
            break;
        case 'export':
            $database = db();
            $database->beginTransaction();
            $database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $state = fetchState($database);
            $query = $database->prepare("SELECT name, mime, encode(content, 'base64') AS data FROM gestclub_attachments WHERE id = ?");
            $exportFile = function (string $id) use ($query): array {
                $query->execute([$id]);
                $file = $query->fetch();
                if (!$file) throw new RuntimeException('Pièce manquante.');
                return ['name' => $file['name'], 'type' => $file['mime'], 'data' => $file['data']];
            };
            foreach ($state['bureau'] as &$period) {
                foreach (['entries', 'invoices', 'expenses', 'partnerCommitments'] as $field) {
                    if (!isset($period[$field])) continue;
                    foreach ($period[$field] as &$item) {
                        if (!isset($item['receipt']['id'])) continue;
                        $item['receipt'] = $exportFile($item['receipt']['id']);
                    }
                    unset($item);
                }
                if (isset($period['applications'])) {
                    foreach ($period['applications'] as &$application) {
                        $application['files'] = array_map(fn(array $file): array => $exportFile($file['id']), $application['files']);
                    }
                    unset($application);
                }
            }
            unset($period);
            $database->commit();
            echo jsonEncode(['format' => 'gestclub-state-v1', 'records' => $state['records'], 'bureau' => $state['bureau']], JSON_PRETTY_PRINT) . "\n";
            break;
        case 'import':
            $file = $argv[2] ?? '';
            if (!is_file($file) || !is_readable($file)) throw new InvalidArgumentException('Indique un fichier JSON lisible.');
            $payload = jsonDecode(file_get_contents($file));
            if (!is_array($payload) || ($payload['format'] ?? '') !== 'gestclub-state-v1' ||
                !is_array($payload['records'] ?? null) || !is_array($payload['bureau'] ?? null) || !$payload['records'] ||
                array_diff(array_keys($payload['records']), array_keys($payload['bureau'])) ||
                array_diff(array_keys($payload['bureau']), array_keys($payload['records']))) {
                throw new InvalidArgumentException('Format de sauvegarde Fair Gestion invalide.');
            }
            $database = db();
            $database->beginTransaction();
            $existing = fetchState($database, true);
            if ($existing['revision'] !== 0) throw new InvalidArgumentException('L’import doit viser une installation vide.');
            $hasInventory = false;
            foreach ($payload['bureau'] as $year => &$period) {
                if (!preg_match('/^\d{4}$/D', (string) $year) || !is_array($period)) throw new InvalidArgumentException('Année invalide.');
                $period = array_replace(emptySeason(), $period);
                validateFinance($period);
                foreach (OFFICE_FIELDS as $field) recordList($period[$field]);
                $period['events'] = validateClubEvents($period['events']);
                restoreRegistrationFiles($database, $period);
                if (array_key_exists('equipment', $period)) {
                    if ($hasInventory) throw new InvalidArgumentException('La sauvegarde contient plusieurs inventaires.');
                    $period['equipment'] = validateEquipment($period['equipment']);
                    $hasInventory = true;
                }
                [$period['partners'], $period['partnerCommitments']] = validatePartners(
                    $period['partners'], $period['partnerCommitments']
                );
                $members = recordList($payload['records'][$year]);
                $payload['records'][$year] = mergeMemberRecords($members, $members);
                foreach ($period['expenses'] as &$expense) unset($expense['submittedBy']);
                unset($expense);
            }
            unset($period);
            validateEventTokens($payload['bureau']);
            $payload = persistAttachments($database, ['bureau' => []], $payload);
            $query = $database->prepare(
                'UPDATE gestclub_state SET records = CAST(? AS jsonb), bureau = CAST(? AS jsonb),
                 revision = revision + 1, staff_revision = revision + 1, updated_at = now() WHERE id = true'
            );
            $query->execute([jsonEncode($payload['records']), jsonEncode($payload['bureau'])]);
            $database->commit();
            echo "Données et justificatifs importés.\n";
            break;
        case 'purge':
            $days = isset($argv[2])
                ? filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]])
                : retentionDays();
            if ($days === false) throw new InvalidArgumentException('Indique un nombre de jours, par exemple 90.');
            $database = db();
            $database->beginTransaction();
            $state = fetchState($database, true);
            [$bureau, $removed, $links] = purgeBureau($state['bureau'], $days, new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')));
            if ($removed || $links) {
                $query = $database->prepare(
                    'UPDATE gestclub_state SET bureau = CAST(? AS jsonb),
                     revision = revision + 1, staff_revision = revision + 1, updated_at = now() WHERE id = true'
                );
                $query->execute([jsonEncode($bureau)]);
            }
            // Pièces qui ne sont plus rattachées à rien : dossier purgé, justificatif remplacé ou ligne supprimée.
            // Le délai d’un jour laisse de côté un envoi en cours.
            $orphans = $database->prepare(
                "DELETE FROM gestclub_attachments WHERE created_at < now() - interval '1 day' AND NOT (id = ANY(CAST(? AS text[])))"
            );
            $orphans->execute(['{' . implode(',', referencedAttachments($bureau)) . '}']);
            $files = $orphans->rowCount();
            $database->commit();
            echo "Dossiers d’inscription clôturés depuis plus de $days jours supprimés : $removed.\n";
            echo "Liens bénévoles d’événements passés désactivés : $links.\n";
            echo "Pièces sans rattachement supprimées : $files.\n";
            break;
        default:
            echo "Utilisation : php bin/gestclub.php install | account EMAIL RÔLES | disable EMAIL | season ANNÉE | import FICHIER | export | purge [JOURS]\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, ($failure instanceof InvalidArgumentException ? $failure->getMessage() :
        'Vérifie config.php, le pilote pdo_pgsql et les accès à la base.') . "\n");
    exit(1);
}
