<?php
declare(strict_types=1);

const REGISTRATION_FILE_LIMIT = 3;

function registrationText(mixed $value, int $limit, bool $required = false): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Texte invalide.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (!preg_match('/^[^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]{0,' . $limit . '}$/uD', $value) ||
        ($required && $value === '')) {
        throw new InvalidArgumentException('Vérifie les champs du formulaire.');
    }
    return $value;
}

function registrationName(mixed $value): string
{
    $value = registrationText($value, 60, true);
    if (str_contains($value, "\n") || str_contains($value, "\t") || strlen($value) > 240) {
        throw new InvalidArgumentException('Prénom ou nom invalide.');
    }
    return $value;
}

function registrationEmail(mixed $value): string
{
    $email = is_string($value) ? trim($value) : '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Renseigne une adresse e-mail valide.');
    }
    return strtolower($email);
}

function registrationLocation(array $bureau, string $token, bool $tracking): ?array
{
    foreach ($bureau as $year => $period) {
        if (!$tracking) {
            if (hash_equals($period['registration']['token'] ?? '', $token)) return [$year, null];
            continue;
        }
        foreach ($period['applications'] ?? [] as $index => $item) {
            if (hash_equals($item['id'], $token)) return [$year, $index];
        }
    }
    return null;
}

function sameApplicant(array $left, array $right): bool
{
    foreach (['first', 'last'] as $key) {
        $pattern = '/^' . preg_quote($left[$key], '/') . '$/uiD';
        if (preg_match($pattern, $right[$key]) !== 1) return false;
    }
    return true;
}

function applyRegistrationCommand(array $state, mixed $command): array
{
    $year = is_array($command) ? ($command['year'] ?? null) : null;
    if (!is_int($year) || !isset($state['bureau'][$year])) {
        throw new InvalidArgumentException('Saison d’inscription invalide.');
    }
    $period = &$state['bureau'][$year];
    if (($command['action'] ?? null) === 'settings') {
        $open = $command['open'] ?? null;
        if (!is_bool($open)) throw new InvalidArgumentException('État du formulaire invalide.');
        $token = $period['registration']['token'] ?? '';
        $period['registration'] = [
            'token' => !$open ? '' : ($token ?: bin2hex(random_bytes(16))),
            'instructions' => registrationText($command['instructions'] ?? '', 600),
        ];
        return $state;
    }
    if (($command['action'] ?? null) !== 'review') {
        throw new InvalidArgumentException('Action d’inscription inconnue.');
    }
    $index = array_search($command['id'] ?? null, array_column($period['applications'] ?? [], 'id'), true);
    if ($index === false) throw new InvalidArgumentException('Dossier introuvable.');
    $item = &$period['applications'][$index];
    if (in_array($item['status'], ['Validé', 'Refusé'], true)) {
        throw new InvalidArgumentException('Ce dossier est déjà clôturé.');
    }
    $status = $command['status'] ?? null;
    if (!in_array($status, ['Incomplet', 'Validé', 'Refusé'], true)) {
        throw new InvalidArgumentException('Choisis une décision.');
    }
    $note = registrationText($command['note'] ?? '', 600, $status === 'Incomplet');
    if ($status === 'Validé') {
        $team = registrationText($command['team'] ?? '', 80, true);
        $memberId = $command['memberId'] ?? '';
        $members = &$state['records'][$year];
        $matching = array_filter($members, fn(array $member): bool => sameApplicant($item, $member));
        if ($memberId !== '' && $memberId !== '__new__') {
            $existing = array_search($memberId, array_column($members, 'id'), true);
            if ($existing === false || !isset($matching[$existing])) {
                throw new InvalidArgumentException('Adhésion existante introuvable pour ce nom.');
            }
            $members[$existing]['team'] = $team;
            $members[$existing]['fileStatus'] = 'Complet';
            $members[$existing]['fileNote'] = '';
        } else {
            if ($matching && $memberId !== '__new__') {
                throw new InvalidArgumentException('Une adhésion porte déjà ce nom. Actualise la liste et sélectionne-la.');
            }
            $members[] = ['id' => bin2hex(random_bytes(16)), 'first' => $item['first'], 'last' => $item['last'],
                'team' => $team, 'fileStatus' => 'Complet', 'fileNote' => '', 'paid' => false];
        }
    }
    $item['status'] = $status;
    $item['note'] = $note;
    return $state;
}

function registrationUploads(array $uploads, int $available): array
{
    $files = [];
    foreach ($uploads['error'] ?? [] as $key => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if (count($files) >= $available) throw new InvalidArgumentException('Trois pièces maximum par dossier.');
        $path = $uploads['tmp_name'][$key] ?? '';
        $size = $uploads['size'][$key] ?? 0;
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path) ||
            !is_int($size) || $size < 1 || $size > 1048576) {
            throw new InvalidArgumentException('Chaque pièce doit peser au maximum 1 Mo.');
        }
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $name = basename(str_replace('\\', '/', (string) ($uploads['name'][$key] ?? '')));
        if (!in_array($type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ||
            !preg_match('/^[^\x00-\x1f\x7f]+$/uD', $name) || strlen($name) > 255) {
            throw new InvalidArgumentException('Utilise des pièces au format PDF, JPEG, PNG ou WebP.');
        }
        $data = file_get_contents($path);
        if ($data === false) throw new InvalidArgumentException('Une pièce est illisible.');
        $files[] = ['name' => $name, 'type' => $type, 'data' => base64_encode($data)];
    }
    return $files;
}

function restoreRegistrationFiles(PDO $database, array &$period): void
{
    $settings = $period['registration'] ?? [];
    if (!is_array($settings)) throw new InvalidArgumentException('Paramètres d’inscription invalides.');
    $token = $settings['token'] ?? '';
    $period['registration'] = [
        'token' => validEventToken($token) ? $token : '',
        'instructions' => registrationText($settings['instructions'] ?? '', 600),
    ];
    $period['applications'] = recordList($period['applications'] ?? []);
    $insert = attachmentInsert($database);
    foreach ($period['applications'] as &$item) {
        $files = $item['files'] ?? null;
        $status = $item['status'] ?? null;
        $date = $item['date'] ?? null;
        if (!is_array($files) || !array_is_list($files) || count($files) > 6 ||
            !in_array($status, ['À vérifier', 'Incomplet', 'Validé', 'Refusé'], true) ||
            !is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Dossier d’inscription invalide.');
        }
        $item = [
            'id' => validEventToken($item['id']) ? $item['id'] : bin2hex(random_bytes(16)),
            'first' => registrationName($item['first'] ?? null), 'last' => registrationName($item['last'] ?? null),
            'email' => registrationEmail($item['email'] ?? null), 'date' => $date, 'status' => $status,
            'note' => registrationText($item['note'] ?? '', 600), 'files' => [],
        ];
        foreach ($files as $file) {
            if (!is_array($file)) throw new InvalidArgumentException('Pièce d’inscription invalide.');
            $item['files'][] = storeAttachment($insert, $file);
        }
    }
    unset($item);
}
