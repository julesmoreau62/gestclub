<?php
declare(strict_types=1);

const ROLE_LABELS = ['director' => 'Dirigeant', 'treasurer' => 'Trésorier', 'secretary' => 'Secrétaire',
    'equipment_manager' => 'Responsable du matériel'];

function passwordProblem(string $password): ?string
{
    if (mb_strlen($password) < 12) return 'Choisis un mot de passe d’au moins 12 caractères.';
    if (strlen($password) > 72) return 'Le mot de passe est trop long (72 octets maximum).';
    return null;
}

/** Replaces the password and closes every open session of the account. */
function setPassword(PDO $database, int $userId, string $password): void
{
    $query = $database->prepare(
        'UPDATE gestclub_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?'
    );
    $query->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    $database->prepare('DELETE FROM gestclub_password_tokens WHERE user_id = ?')->execute([$userId]);
}

function createPasswordToken(PDO $database, int $userId, int $hours): string
{
    $database->exec('DELETE FROM gestclub_password_tokens WHERE expires_at < now()');
    $token = bin2hex(random_bytes(32));
    $query = $database->prepare(
        'INSERT INTO gestclub_password_tokens (token_hash, user_id, expires_at) VALUES (?, ?, now() + make_interval(hours => ?))'
    );
    $query->execute([hash('sha256', $token), $userId, $hours]);
    return $token;
}

function passwordTokenUser(PDO $database, mixed $token): ?array
{
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/D', $token)) return null;
    $query = $database->prepare(
        'SELECT u.id, u.email FROM gestclub_password_tokens t JOIN gestclub_users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > now() AND u.active'
    );
    $query->execute([hash('sha256', $token)]);
    return $query->fetch() ?: null;
}

function sendAccountLink(PDO $database, int $userId, string $email, bool $invitation): bool
{
    $token = createPasswordToken($database, $userId, $invitation ? 24 * 7 : 1);
    $club = (string) config()['club_name'];
    $link = appUrl('mot-de-passe.php?jeton=' . $token);
    if ($invitation) {
        return sendMail($email, "Ton accès à Fair Gestion · $club",
            "Bonjour,\n\nUn compte Fair Gestion a été ouvert pour toi au $club.\n" .
            "Choisis ton mot de passe depuis ce lien, valable 7 jours :\n$link\n\n" .
            "Tu te connecteras ensuite à l’adresse " . appUrl('') . " avec cette adresse e-mail.");
    }
    return sendMail($email, "Nouveau mot de passe · $club",
        "Bonjour,\n\nUne demande de nouveau mot de passe a été faite pour ton compte Fair Gestion.\n" .
        "Choisis-le depuis ce lien, valable une heure :\n$link\n\n" .
        "Si tu n’as rien demandé, ignore ce message : ton mot de passe actuel reste valable.");
}

/** Roles for which config.php requires two-factor authentication. */
function requiresTwoFactor(array $roles): bool
{
    $required = config()['require_2fa'] ?? [];
    return is_array($required) && array_intersect($roles, $required) !== [];
}

// --- TOTP (RFC 6238), compatible with the usual authenticator apps.

function base32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32Decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($text)) as $char) {
        $value = strpos($alphabet, $char);
        if ($value === false) throw new InvalidArgumentException('Clé invalide.');
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    return $out;
}

function totpCode(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) |
        (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Step of a valid code within one step of drift, or null. */
function totpStep(string $secret, string $code, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/D', $code)) return null;
    $current = intdiv($now ?? time(), 30);
    foreach ([$current, $current - 1, $current + 1] as $step) {
        if (hash_equals(totpCode($secret, $step), $code)) return $step;
    }
    return null;
}

/** Checks a code for an account and refuses a code already used. */
function verifyAccountTotp(PDO $database, int $userId, string $code): bool
{
    $query = $database->prepare('SELECT totp_secret, totp_last_step FROM gestclub_users WHERE id = ? FOR UPDATE');
    $query->execute([$userId]);
    $row = $query->fetch();
    if (!$row || !$row['totp_secret']) return false;
    $step = totpStep($row['totp_secret'], $code);
    if ($step === null || $step <= (int) $row['totp_last_step']) return false;
    $database->prepare('UPDATE gestclub_users SET totp_last_step = ? WHERE id = ?')->execute([$step, $userId]);
    return true;
}

function totpUri(string $email, string $secret): string
{
    $issuer = 'Fair Gestion · ' . config()['club_name'];
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret .
        '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
