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
        'meetings' => [], 'tasks' => [], 'decisions' => [], 'volunteers' => []];
}

try {
    $command = $argv[1] ?? 'help';
    switch ($command) {
        case 'install':
            $database = db();
            $database->beginTransaction();
            $database->exec('CREATE TABLE IF NOT EXISTS gestclub_schema_migrations (
                version text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
            $database->exec('LOCK TABLE gestclub_schema_migrations IN EXCLUSIVE MODE');
            $applied = $database->query('SELECT version FROM gestclub_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $record = $database->prepare('INSERT INTO gestclub_schema_migrations (version) VALUES (?)');
            $files = glob(dirname(__DIR__) . '/sql/*.sql');
            sort($files);
            foreach ($files as $file) {
                // 001 est rejouable : une installation antérieure au suivi des versions le repasse sans effet.
                $version = basename($file, '.sql');
                if (in_array($version, $applied, true)) continue;
                $database->exec(file_get_contents($file));
                $record->execute([$version]);
                echo "Schéma $version appliqué.\n";
            }
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
                throw new InvalidArgumentException('Usage : account adresse@email.fr director,treasurer,secretary');
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
                     revision = revision + 1, updated_at = now(), updated_by = NULL WHERE id = true'
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
            foreach ($state['bureau'] as &$period) {
                foreach (['entries', 'invoices', 'expenses'] as $field) {
                    if (!isset($period[$field])) continue;
                    foreach ($period[$field] as &$item) {
                        if (!isset($item['receipt']['id'])) continue;
                        $query->execute([$item['receipt']['id']]);
                        $file = $query->fetch();
                        if (!$file) throw new RuntimeException('Pièce manquante.');
                        $item['receipt'] = ['name' => $file['name'], 'type' => $file['mime'], 'data' => $file['data']];
                    }
                    unset($item);
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
                throw new InvalidArgumentException('Format de sauvegarde Gest’club invalide.');
            }
            $database = db();
            $database->beginTransaction();
            $existing = fetchState($database, true);
            if ($existing['revision'] !== 0) throw new InvalidArgumentException('L’import doit viser une installation vide.');
            foreach ($payload['bureau'] as $year => &$period) {
                if (!preg_match('/^\d{4}$/D', (string) $year) || !is_array($period)) throw new InvalidArgumentException('Année invalide.');
                $period = array_replace(emptySeason(), $period);
                validateFinance($period);
                foreach (OFFICE_FIELDS as $field) recordList($period[$field]);
                $members = recordList($payload['records'][$year]);
                $payload['records'][$year] = mergeMemberRecords($members, $members);
                foreach ($period['expenses'] as &$expense) unset($expense['submittedBy']);
                unset($expense);
            }
            unset($period);
            $payload = persistAttachments($database, ['bureau' => []], $payload);
            $query = $database->prepare(
                'UPDATE gestclub_state SET records = CAST(? AS jsonb), bureau = CAST(? AS jsonb),
                 revision = revision + 1, updated_at = now() WHERE id = true'
            );
            $query->execute([jsonEncode($payload['records']), jsonEncode($payload['bureau'])]);
            $database->commit();
            echo "Données et justificatifs importés.\n";
            break;
        case 'history':
            $rows = db()->query(
                "SELECT h.revision, to_char(h.saved_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD HH24:MI') AS saved,
                        coalesce(u.email, 'administration') AS author
                 FROM (SELECT revision, updated_at AS saved_at, updated_by AS saved_by FROM gestclub_state
                       UNION ALL SELECT revision, saved_at, saved_by FROM gestclub_state_history) h
                 LEFT JOIN gestclub_users u ON u.id = h.saved_by ORDER BY h.revision DESC LIMIT 60"
            )->fetchAll();
            echo "Révision  Enregistrée le    Par\n";
            foreach ($rows as $index => $row) {
                printf("%8d  %s  %s%s\n", $row['revision'], $row['saved'], $row['author'], $index ? '' : '  (état actuel)');
            }
            break;
        case 'restore':
            $revision = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false) throw new InvalidArgumentException('Indique un numéro de révision affiché par la commande history.');
            $database = db();
            $database->beginTransaction();
            fetchState($database, true);
            // L'état remplacé rejoint lui-même l'historique : une restauration peut être annulée.
            $query = $database->prepare(
                'UPDATE gestclub_state s SET records = h.records, bureau = h.bureau, revision = s.revision + 1,
                 updated_at = now(), updated_by = NULL FROM gestclub_state_history h WHERE h.revision = ? RETURNING s.revision'
            );
            $query->execute([$revision]);
            $current = $query->fetchColumn();
            if ($current === false) throw new InvalidArgumentException('Révision absente de l’historique.');
            $database->commit();
            echo "Révision $revision restaurée. L’état actuel porte le numéro $current.\n";
            break;
        case 'prune':
            $database = db();
            $database->beginTransaction();
            fetchState($database, true);
            $database->exec('SELECT gestclub_prune()');
            $database->commit();
            echo "Historique et justificatifs nettoyés.\n";
            break;
        default:
            echo "Utilisation : php bin/gestclub.php install | account EMAIL RÔLES | disable EMAIL | season ANNÉE | import FICHIER | export | history | restore RÉVISION | prune\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, ($failure instanceof InvalidArgumentException ? $failure->getMessage() :
        'Vérifie config.php, le pilote pdo_pgsql et les accès à la base.') . "\n");
    exit(1);
}
