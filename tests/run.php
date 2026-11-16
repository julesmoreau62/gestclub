<?php
declare(strict_types=1);

// Tests de bout en bout : schéma, commandes d'administration et API HTTP sur une base PostgreSQL de test.
//
//   GESTCLUB_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=gestclub_test' \
//   GESTCLUB_TEST_USER=... GESTCLUB_TEST_PASSWORD=... php tests/run.php
//
// La base doit être dédiée aux tests et vide : le script refuse de démarrer s'il y trouve une table gestclub_*.

if (PHP_SAPI !== 'cli') exit(1);

const ROOT = __DIR__ . '/..';
const TABLES = ['gestclub_state_history', 'gestclub_state', 'gestclub_attachments', 'gestclub_login_limits',
    'gestclub_users', 'gestclub_schema_migrations'];

$dsn = getenv('GESTCLUB_TEST_DSN');
if (!$dsn) {
    fwrite(STDERR, "Indique la base de test dans GESTCLUB_TEST_DSN (voir l’en-tête de tests/run.php).\n");
    exit(2);
}
$port = (int) (getenv('GESTCLUB_TEST_HTTP_PORT') ?: 8765);
$base = "http://127.0.0.1:$port";
$configFile = tempnam(sys_get_temp_dir(), 'gestclub-config-');
file_put_contents($configFile, '<?php return ' . var_export(['club_name' => 'Club de test', 'origin' => $base,
    'postgres' => ['dsn' => $dsn, 'user' => getenv('GESTCLUB_TEST_USER') ?: null,
        'password' => getenv('GESTCLUB_TEST_PASSWORD') ?: null]], true) . ';');
putenv("GESTCLUB_CONFIG=$configFile");

$pdo = new PDO($dsn, getenv('GESTCLUB_TEST_USER') ?: null, getenv('GESTCLUB_TEST_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($pdo->query("SELECT count(*) FROM pg_tables WHERE tablename LIKE 'gestclub\\_%'")->fetchColumn() > 0) {
    fwrite(STDERR, "La base de test contient déjà des tables gestclub_*. Utilise une base vide et dédiée.\n");
    exit(2);
}

$failures = 0;
$checks = 0;

function check(string $label, bool $condition, mixed $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) return;
    $failures++;
    echo "  ÉCHEC  $label" . ($detail === null ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
}

function section(string $title): void
{
    echo "• $title\n";
}

function scalar(string $sql): mixed
{
    global $pdo;
    return $pdo->query($sql)->fetchColumn();
}

function refused(string $sql): bool
{
    global $pdo;
    try {
        $pdo->exec($sql);
        return false;
    } catch (PDOException) {
        return true;
    }
}

function dropSchema(): void
{
    global $pdo;
    foreach (TABLES as $table) $pdo->exec("DROP TABLE IF EXISTS $table CASCADE");
    $pdo->exec('DROP FUNCTION IF EXISTS gestclub_keep_history() CASCADE');
    $pdo->exec('DROP FUNCTION IF EXISTS gestclub_prune() CASCADE');
}

/** @return array{0: int, 1: string} code de sortie et sortie de la commande */
function cli(string ...$arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/bin/gestclub.php');
    foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
    exec($command . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

function account(string $email, string $roles): string
{
    [, $output] = cli('account', $email, $roles);
    return substr($output, strrpos($output, ' ') + 1);
}

/** Navigateur minimal : garde le cookie de session et le jeton CSRF d'un compte. */
final class Browser
{
    private string $cookie = '';
    public string $csrf = '';
    public array $state = [];

    /** @return array{0: int, 1: string} */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        global $base;
        if ($this->cookie) $headers[] = "Cookie: $this->cookie";
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $content = file_get_contents($base . $path, false, $context);
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $match)) $status = (int) $match[1];
            if (preg_match('/^Set-Cookie: (gestclub_session=[^;]+)/i', $line, $match)) $this->cookie = $match[1];
        }
        return [$status, (string) $content];
    }

    public function login(string $email, string $password): bool
    {
        [, $page] = $this->request('GET', '/');
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $page, $match);
        [$status] = $this->request('POST', '/', http_build_query(['csrf' => $match[1] ?? '', 'email' => $email,
            'password' => $password]), ['Content-Type: application/x-www-form-urlencoded']);
        if ($status !== 303) return false;
        [, $page] = $this->request('GET', '/');
        if (!preg_match('/window\.gestclubRemote = (.+?);<\/script>/s', $page, $match)) return false;
        $remote = json_decode($match[1], true);
        $this->csrf = $remote['csrf'];
        $this->state = $remote['state'];
        return true;
    }

    /** @return array{0: int, 1: array} */
    public function load(string $role): array
    {
        [$status, $body] = $this->request('GET', "/api.php?role=$role");
        $data = json_decode($body, true) ?? [];
        if ($status === 200) $this->state = $data;
        return [$status, $data];
    }

    /** Envoie l'état local comme le fait index.html et adopte l'état renvoyé. */
    public function save(string $role, ?string $csrf = null): array
    {
        [$status, $body] = $this->request('POST', "/api.php?role=$role", json_encode(['revision' => $this->state['revision'],
            'records' => $this->state['records'], 'bureau' => $this->state['bureau']]),
            ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf ?? $this->csrf)]);
        $data = json_decode($body, true) ?? [];
        if ($status === 200) $this->state = $data;
        return [$status, $data];
    }
}

$server = null;
try {
    // ---------------------------------------------------------------------------------------------------------------
    section('Mise à jour d’une installation antérieure au suivi des versions');
    $pdo->exec(file_get_contents(ROOT . '/sql/001-install.sql'));
    $pdo->exec("INSERT INTO gestclub_users (email, password_hash, roles) VALUES ('ancien@club.fr', 'x', '[\"treasurer\"]')");
    $pdo->exec("INSERT INTO gestclub_state (revision, records, bureau) VALUES (7, '{\"2025\": []}', '{\"2025\": {}}')");
    [$code, $output] = cli('install');
    check('install réussit sur une base existante', $code === 0, $output);
    check('les trois fichiers SQL sont notés', scalar('SELECT count(*) FROM gestclub_schema_migrations') === 3);
    check('les données existantes sont conservées', scalar('SELECT revision FROM gestclub_state') === 7);
    check('les domaines reprennent la révision en cours',
        scalar('SELECT records_revision + finance_revision + office_revision FROM gestclub_state') === 21);
    [$code, $output] = cli('install');
    check('une seconde exécution n’applique rien', $code === 0 && trim($output) === 'Base prête.', $output);
    dropSchema();

    // ---------------------------------------------------------------------------------------------------------------
    section('Installation neuve et contraintes de la base');
    [$code, $output] = cli('install');
    check('install réussit sur une base vide', $code === 0, $output);
    $seasons = array_keys(json_decode(scalar('SELECT records FROM gestclub_state'), true));
    check('deux saisons sont créées', count($seasons) === 2, $seasons);
    $year = (string) max($seasons);
    check('e-mail en majuscules refusé', refused("INSERT INTO gestclub_users (email, password_hash, roles) VALUES ('A@b.fr', 'x', '[\"director\"]')"));
    check('rôle inconnu refusé', refused("INSERT INTO gestclub_users (email, password_hash, roles) VALUES ('a@b.fr', 'x', '[\"admin\"]')"));
    check('liste de rôles vide refusée', refused("INSERT INTO gestclub_users (email, password_hash, roles) VALUES ('a@b.fr', 'x', '[]')"));
    check('seconde ligne d’état refusée', refused("INSERT INTO gestclub_state (id, records, bureau) VALUES (false, '{\"1\": []}', '{\"1\": {}}')"));
    check('état sans saison refusé', refused("UPDATE gestclub_state SET records = '{}', bureau = '{}'"));
    check('justificatif de plus de 1 Mo refusé', refused("INSERT INTO gestclub_attachments (id, name, mime, content)
        VALUES (repeat('a', 32), 'f.pdf', 'application/pdf', decode(repeat('00', 1048577), 'hex'))"));
    [$code] = cli('account', 'x@club.fr', 'admin');
    check('la commande account refuse un rôle inconnu', $code !== 0);

    $passwords = [];
    foreach (['director' => 'direction@club.fr', 'treasurer' => 'tresorerie@club.fr', 'secretary' => 'secretariat@club.fr'] as $role => $email) {
        $passwords[$role] = [$email, account($email, $role)];
    }

    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', ROOT . '/public'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($try = 0; $try < 50 && !@fsockopen('127.0.0.1', $port); $try++) usleep(100000);

    // ---------------------------------------------------------------------------------------------------------------
    section('Connexion et droits');
    $anonymous = new Browser();
    [$status] = $anonymous->load('treasurer');
    check('API refusée sans session', $status === 401, $status);
    check('mauvais mot de passe refusé', !$anonymous->login($passwords['treasurer'][0], 'faux'));
    $director = new Browser();
    $treasurer = new Browser();
    $secretary = new Browser();
    check('le dirigeant se connecte', $director->login(...$passwords['director']));
    check('le trésorier se connecte', $treasurer->login(...$passwords['treasurer']));
    check('le secrétaire se connecte', $secretary->login(...$passwords['secretary']));
    check('la date de dernière connexion est notée', scalar('SELECT count(*) FROM gestclub_users WHERE last_login_at IS NOT NULL') === 3);
    check('les connexions réussies ne comptent pas', (int) scalar('SELECT coalesce(max(attempts), 0) FROM gestclub_login_limits') === 1);
    [$status] = $treasurer->load('secretary');
    check('rôle non attribué refusé', $status === 403, $status);
    [$status] = $treasurer->save('treasurer', 'jeton-invalide');
    check('écriture sans jeton CSRF refusée', $status === 403, $status);
    foreach (['/config.php', '/server/state.php', '/sql/001-install.sql', '/bin/gestclub.php'] as $path) {
        // Le serveur de développement de PHP répond 404 ou retombe sur la page de connexion selon sa version.
        [$status, $body] = $anonymous->request('GET', $path);
        check("$path n’est pas servi", $status === 404 || str_contains($body, '<title>Connexion · Gest’club</title>'), $status);
    }

    // ---------------------------------------------------------------------------------------------------------------
    section('Sauvegardes simultanées dans des domaines différents');
    $pdf = random_bytes(40000);
    $receipt = fn(string $name): array => ['name' => $name, 'type' => 'application/pdf', 'data' => base64_encode($pdf)];
    $secretary->state['records'][$year][] = ['id' => 'm1', 'first' => 'Léa', 'last' => 'Martin', 'team' => 'U13'];
    [$status, $data] = $secretary->save('secretary');
    check('le secrétaire ajoute une adhésion', $status === 200 && $data['revision'] === 1, $data);
    $treasurer->state['bureau'][$year]['entries'][] = ['id' => 'e1', 'amount' => 12000, 'kind' => 'income',
        'date' => "$year-10-01", 'memberId' => 'm1', 'receipt' => $receipt('recu.pdf')];
    [$status, $data] = $treasurer->save('treasurer');
    check('le trésorier, resté sur l’ancienne révision, enregistre sans conflit', $status === 200 && $data['revision'] === 2, $data);
    check('il reçoit l’adhésion ajoutée entre-temps', ($data['records'][$year][0]['id'] ?? null) === 'm1');
    $receiptId = $data['bureau'][$year]['entries'][0]['receipt']['id'] ?? '';
    check('le justificatif est remplacé par un identifiant', (bool) preg_match('/^[0-9a-f]{32}$/', $receiptId)
        && !isset($data['bureau'][$year]['entries'][0]['receipt']['data']));
    $secretary->state['records'][$year][] = ['id' => 'm2', 'first' => 'Noé', 'last' => 'Petit', 'team' => 'U15'];
    [$status, $data] = $secretary->save('secretary');
    check('le secrétaire, resté avant l’écriture du trésorier, enregistre sans conflit', $status === 200, $data);
    $treasurer->load('treasurer');
    check('l’écriture du trésorier est intacte', count($treasurer->state['bureau'][$year]['entries']) === 1);
    check('le registre contient les deux adhésions', count($treasurer->state['records'][$year]) === 2);

    // ---------------------------------------------------------------------------------------------------------------
    section('Conflits dans un même domaine');
    $director->state['bureau'][$year]['tasks'][] = ['id' => 't1', 'title' => 'Réserver le gymnase'];
    [$status, $data] = $director->save('director');
    check('le dirigeant, resté avant les adhésions, reçoit un conflit', $status === 409 && isset($data['state']), $status);
    check('le conflit renvoie le registre à jour', count($data['state']['records'][$year] ?? []) === 2);
    $director->load('director');
    $director->state['bureau'][$year]['tasks'][] = ['id' => 't1', 'title' => 'Réserver le gymnase'];
    [$status] = $director->save('director');
    check('après rechargement, le dirigeant enregistre', $status === 200, $status);
    $secretary->state['records'][$year][] = ['id' => 'm3', 'first' => 'Zoé', 'last' => 'Roux', 'team' => 'U11'];
    [$status] = $secretary->save('secretary');
    check('le secrétaire, resté avant la tâche du dirigeant, reçoit un conflit', $status === 409, $status);
    $secretary->load('secretary');
    array_pop($secretary->state['records'][$year]);
    [$status, $data] = $secretary->save('secretary');
    check('une adhésion retirée du registre est refusée', $status === 422, $data);
    $secretary->load('secretary');

    // ---------------------------------------------------------------------------------------------------------------
    section('Notes de frais entre dirigeant et trésorier');
    $treasurer->load('treasurer');
    $staleTreasurer = clone $treasurer;
    $treasurer->state['bureau'][$year]['entries'][] = ['id' => 'e2', 'amount' => 500, 'kind' => 'expense', 'date' => "$year-10-02"];
    $treasurer->save('treasurer');
    $director->state['bureau'][$year]['expenses'][] = ['id' => 'x1', 'status' => 'Déposée', 'label' => 'Ballons',
        'date' => "$year-10-03", 'amount' => 4500, 'receipt' => $receipt('ticket.pdf')];
    [$status, $data] = $director->save('director');
    check('le dirigeant dépose une note sans conflit avec le trésorier', $status === 200, $data);
    $treasurer->load('treasurer');
    check('les écritures du trésorier sont conservées', count($treasurer->state['bureau'][$year]['entries']) === 2);
    check('la note déposée arrive chez le trésorier', ($treasurer->state['bureau'][$year]['expenses'][0]['id'] ?? null) === 'x1');
    $staleTreasurer->state['bureau'][$year]['opening']['cash'] = 100;
    [$status] = $staleTreasurer->save('treasurer');
    check('un trésorier resté avant la note reçoit un conflit', $status === 409, $status);
    $ticketId = $treasurer->state['bureau'][$year]['expenses'][0]['receipt']['id'];

    // ---------------------------------------------------------------------------------------------------------------
    section('Justificatifs');
    [$status, $content] = $treasurer->request('GET', "/api.php?role=treasurer&attachment=$receiptId");
    check('le trésorier télécharge le justificatif à l’identique', $status === 200 && $content === $pdf, $status);
    [$status] = $secretary->request('GET', "/api.php?role=secretary&attachment=$receiptId");
    check('le secrétaire n’y a pas accès', $status === 404, $status);
    [$status] = $director->request('GET', "/api.php?role=director&attachment=$ticketId");
    check('le dirigeant télécharge le ticket de sa note', $status === 200, $status);
    $treasurer->state['bureau'][$year]['entries'][0]['receipt'] = ['id' => $ticketId];
    [$status] = $treasurer->save('treasurer');
    check('une pièce ne peut pas être rattachée à une autre écriture', $status === 422, $status);
    $treasurer->load('treasurer');
    $treasurer->state['bureau'][$year]['entries'][0]['receipt'] = $receipt('recu-corrige.pdf');
    $treasurer->save('treasurer');
    $replacedAt = $treasurer->state['revision'];
    check('la pièce remplacée reste citée par l’historique', (int) scalar('SELECT count(*) FROM gestclub_attachments') === 3);

    // ---------------------------------------------------------------------------------------------------------------
    section('Historique et restauration');
    $beforeWipe = $treasurer->state['revision'];
    $treasurer->state['bureau'][$year]['entries'] = [];
    [$status] = $treasurer->save('treasurer');
    check('le trésorier vide le journal', $status === 200 && $treasurer->state['bureau'][$year]['entries'] === []);
    check('chaque sauvegarde a laissé une révision', (int) scalar('SELECT count(*) FROM gestclub_state_history') === $treasurer->state['revision']);
    check('l’auteur est noté', scalar("SELECT u.email FROM gestclub_state_history h JOIN gestclub_users u ON u.id = h.saved_by
        WHERE h.revision = $beforeWipe") === $passwords['treasurer'][0]);
    [$code, $output] = cli('history');
    check('history liste les révisions', $code === 0 && str_contains($output, '(état actuel)') && str_contains($output, 'tresorerie@club.fr'), $output);
    [$code] = cli('restore', '9999');
    check('restore refuse une révision absente', $code !== 0);
    [$code, $output] = cli('restore', (string) $beforeWipe);
    check('restore réussit', $code === 0, $output);
    $staleDirector = clone $director;
    $treasurer->load('treasurer');
    check('le journal est revenu', count($treasurer->state['bureau'][$year]['entries']) === 2);
    $restoredId = $treasurer->state['bureau'][$year]['entries'][0]['receipt']['id'];
    [$status, $content] = $treasurer->request('GET', "/api.php?role=treasurer&attachment=$restoredId");
    check('le justificatif restauré se télécharge', $status === 200 && $content === $pdf, $status);
    $staleDirector->state['bureau'][$year]['tasks'] = [];
    [$status] = $staleDirector->save('director');
    check('après une restauration, tout écran ancien reçoit un conflit', $status === 409, $status);

    // ---------------------------------------------------------------------------------------------------------------
    section('Nettoyage de l’historique et des justificatifs');
    [$code] = cli('prune');
    check('prune garde les pièces encore citées', $code === 0 && (int) scalar('SELECT count(*) FROM gestclub_attachments') === 3);
    $pdo->exec("UPDATE gestclub_state_history SET saved_at = saved_at - interval '3 days'");
    $aged = (int) scalar('SELECT count(*) FROM gestclub_state_history');
    for ($index = 1; $index <= 60; $index++) {
        $pdo->exec("UPDATE gestclub_state SET revision = revision + 1, updated_at = now(),
            bureau = jsonb_set(bureau, '{{$year},opening,cash}', '$index')");
    }
    cli('prune');
    $current = (int) scalar('SELECT revision FROM gestclub_state');
    check('une seule révision est gardée pour un jour ancien',
        (int) scalar("SELECT count(*) FROM gestclub_state_history WHERE saved_at < now() - interval '1 day'") === 1, $aged);
    check('c’est la dernière de ce jour-là', (int) scalar('SELECT min(revision) FROM gestclub_state_history') === $aged - 1);
    check('les 50 dernières révisions sont gardées',
        (int) scalar('SELECT count(*) FROM gestclub_state_history WHERE revision > ' . ($current - 50)) === 49);
    check('la pièce remplacée est supprimée une fois sortie de l’historique',
        (int) scalar('SELECT count(*) FROM gestclub_attachments') === 2, $replacedAt);
    $pdo->exec("UPDATE gestclub_state_history SET saved_at = saved_at - interval '2 years'");
    for ($index = 1; $index <= 50; $index++) {
        $pdo->exec("UPDATE gestclub_state SET revision = revision + 1, updated_at = now(),
            bureau = jsonb_set(bureau, '{{$year},opening,bank}', '$index')");
    }
    cli('prune');
    check('au-delà d’un an, il ne reste que les 50 dernières révisions', (int) scalar('SELECT count(*) FROM gestclub_state_history') === 49);

    // ---------------------------------------------------------------------------------------------------------------
    section('Saisons, export et import');
    [$code] = cli('season', '2040');
    $treasurer->load('treasurer');
    check('season ajoute une saison', $code === 0 && isset($treasurer->state['records']['2040']));
    [$code, $export] = cli('export');
    $exported = json_decode($export, true);
    check('export produit le format attendu', $code === 0 && ($exported['format'] ?? '') === 'gestclub-state-v1');
    check('export embarque les justificatifs',
        base64_decode($exported['bureau'][$year]['entries'][0]['receipt']['data'] ?? '') === $pdf);
    $exportFile = tempnam(sys_get_temp_dir(), 'gestclub-export-');
    file_put_contents($exportFile, $export);
    [$code] = cli('import', $exportFile);
    check('import refuse une installation déjà utilisée', $code !== 0);
    dropSchema();
    cli('install');
    [$code, $output] = cli('import', $exportFile);
    unlink($exportFile);
    check('import réussit dans une installation vide', $code === 0, $output);
    check('les adhésions sont reprises', (int) scalar("SELECT jsonb_array_length(records->'$year') FROM gestclub_state") === 2);
    check('les justificatifs sont repris', (int) scalar('SELECT count(*) FROM gestclub_attachments') === 2);

    // ---------------------------------------------------------------------------------------------------------------
    section('Comptes désactivés et limite de tentatives');
    $password = account('tresorerie@club.fr', 'treasurer');
    $member = new Browser();
    check('connexion après import', $member->login('tresorerie@club.fr', $password));
    cli('disable', 'tresorerie@club.fr');
    [$status] = $member->load('treasurer');
    check('un compte désactivé perd sa session', $status === 401, $status);
    $password = account('tresorerie@club.fr', 'treasurer');
    for ($index = 0; $index < 16; $index++) $member->login('tresorerie@club.fr', 'faux');
    check('après 16 échecs, même le bon mot de passe est refusé', !$member->login('tresorerie@club.fr', $password));
    $pdo->exec('DELETE FROM gestclub_login_limits');
    check('la limite levée, la connexion reprend', $member->login('tresorerie@club.fr', $password));
} catch (Throwable $error) {
    $failures++;
    echo '  ERREUR  ' . $error::class . ' : ' . $error->getMessage() . ' (ligne ' . $error->getLine() . ")\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    dropSchema();
    unlink($configFile);
}

echo $failures ? "\n$failures échec(s) sur $checks vérifications.\n" : "\n$checks vérifications réussies.\n";
exit($failures ? 1 : 0);
