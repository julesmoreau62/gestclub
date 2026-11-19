<?php
declare(strict_types=1);

const GESTCLUB_ROLES = ['director', 'treasurer', 'secretary', 'equipment_manager'];
const GESTCLUB_BODY_LIMIT = 24 * 1024 * 1024;

function db(): PDO
{
    static $connection;
    if ($connection === null) {
        $settings = config()['postgres'];
        $connection = new PDO($settings['dsn'], $settings['user'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $connection;
}

function config(): array
{
    static $configuration;
    return $configuration ??= require dirname(__DIR__) . '/config.php';
}

function jsonEncode(mixed $value, int $flags = 0): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | $flags);
}

function jsonDecode(string $value): mixed
{
    return json_decode($value, true, 128, JSON_THROW_ON_ERROR);
}

function textEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function beginWebRequest(string $sessionName = 'gestclub_session'): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    $origin = rtrim(config()['origin'], '/');
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (str_starts_with($origin, 'https://') && !$isHttps) {
        header('Location: ' . $origin . '/', true, 302);
        exit;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($sessionName);
    session_set_cookie_params(['path' => parse_url($origin, PHP_URL_PATH) ?: '/', 'secure' => $isHttps,
        'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'], $_SESSION['version'], $_SESSION['signed_in']) ||
        time() - $_SESSION['signed_in'] > 12 * 3600) {
        return null;
    }
    $query = db()->prepare('SELECT id, email, roles FROM gestclub_users WHERE id = ? AND session_version = ? AND active');
    $query->execute([$_SESSION['user_id'], $_SESSION['version']]);
    $user = $query->fetch();
    if (!$user) return null;
    $user['roles'] = jsonDecode($user['roles']);
    return $user;
}

function csrfValid(mixed $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Counts one attempt for the caller in the given scope and tells whether the limit is exceeded.
 * Only the direct peer address is used. A caller cannot choose its own key using forwarded headers.
 */
function rateLimitExceeded(string $scope, int $max, int $minutes): bool
{
    $key = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $database = db();
    $database->exec('DELETE FROM gestclub_login_limits WHERE expires_at < now()');
    $limit = $database->prepare(
        'INSERT INTO gestclub_login_limits (key, attempts, expires_at) VALUES (?, 1, now() + make_interval(mins => ?))
         ON CONFLICT (key) DO UPDATE SET attempts = gestclub_login_limits.attempts + 1 RETURNING attempts'
    );
    $limit->execute([$key, $minutes]);
    return (int) $limit->fetchColumn() > $max;
}

function login(string $email, string $password): bool
{
    if (rateLimitExceeded('ip', 15, 15)) return false;
    $database = db();
    $query = $database->prepare('SELECT id, password_hash, session_version FROM gestclub_users WHERE email = ? AND active');
    $query->execute([strtolower(trim($email))]);
    $user = $query->fetch();
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    if (strlen($password) > 72 || !password_verify($password, $user['password_hash'] ?? $dummyHash) || !$user) return false;
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $user['id'], 'version' => $user['session_version'],
        'signed_in' => time(), 'csrf' => bin2hex(random_bytes(32))];
    return true;
}

function fetchState(PDO $connection, bool $lock = false): array
{
    $row = $connection->query('SELECT revision, staff_revision, records, bureau FROM gestclub_state WHERE id = true' .
        ($lock ? ' FOR UPDATE' : ''))->fetch();
    if (!$row) throw new RuntimeException('Base à initialiser avec bin/gestclub.php.');
    return ['revision' => (int) $row['revision'], 'records' => jsonDecode($row['records']), 'bureau' => jsonDecode($row['bureau']),
        'staffRevision' => (int) $row['staff_revision']];
}

/**
 * Saves a change made from a public link (registration form, volunteer sign-up).
 * It moves the revision forward without touching staff_revision, so the bureau can keep saving
 * from a page loaded before that change: those links only write data the bureau cannot send back.
 */
function savePublicBureau(PDO $connection, array $bureau): void
{
    $update = $connection->prepare(
        'UPDATE gestclub_state SET bureau = CAST(? AS jsonb), revision = revision + 1, updated_at = now() WHERE id = true'
    );
    $update->execute([jsonEncode($bureau)]);
}

/** Global privacy settings shown on the public pages. */
function privacyContact(): string
{
    $contact = config()['privacy_contact'] ?? '';
    return is_string($contact) ? trim($contact) : '';
}

function retentionDays(): int
{
    $days = config()['retention_days'] ?? 90;
    return is_int($days) && $days >= 1 ? $days : 90;
}
