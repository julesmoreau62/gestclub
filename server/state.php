<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/registrations.php';
require_once __DIR__ . '/partners.php';
require_once __DIR__ . '/equipment.php';

const FINANCE_FIELDS = ['opening', 'entries', 'invoices', 'plan', 'expenses', 'bankLines', 'statement', 'closedAt', 'helloAssoUrl'];
const OFFICE_FIELDS = ['meetings', 'tasks', 'decisions', 'volunteers'];

function picked(array $source, array $keys): array
{
    return array_intersect_key($source, array_flip($keys));
}

function financeReport(array $state, int $year): array
{
    $current = $state['bureau'][$year];
    $start = $year . '-09-01';
    $end = ($year + 1) . '-08-31';
    $cash = ['income' => 0, 'expense' => 0];
    $result = $cash;
    $open = $cash;
    $settled = [];
    foreach ($state['bureau'] as $period) {
        foreach ($period['entries'] ?? [] as $entry) {
            if (isset($entry['invoiceId']) && $entry['date'] <= $end) {
                $id = $entry['invoiceId'];
                $settled[$id] = ($settled[$id] ?? 0) + $entry['amount'];
            }
        }
    }
    foreach ($current['entries'] as $entry) {
        if (isset($entry['transferId'])) continue;
        $cash[$entry['kind']] += $entry['amount'];
        if (!isset($entry['invoiceId'])) $result[$entry['kind']] += $entry['amount'];
    }
    foreach ($state['bureau'] as $period) {
        foreach ($period['invoices'] ?? [] as $invoice) {
            if ($invoice['date'] > $end) continue;
            if ($invoice['date'] >= $start) $result[$invoice['kind']] += $invoice['amount'];
            $open[$invoice['kind']] += $invoice['amount'] - ($settled[$invoice['id']] ?? 0);
        }
    }
    $balance = array_sum($current['opening']) + $cash['income'] - $cash['expense'];
    return ['start' => $start, 'end' => $end, 'balance' => $balance, 'lines' => [
        ['Encaissements de la saison', $cash['income']],
        ['Décaissements de la saison', $cash['expense']],
        [!empty($current['closedAt']) ? 'Trésorerie de fin de saison' : 'Trésorerie au journal', $balance],
        ['Produits enregistrés', $result['income']], ['Charges enregistrées', $result['expense']],
        ['Solde produits − charges', $result['income'] - $result['expense']],
        ['Sommes restant à encaisser', $open['income']], ['Sommes restant à payer', $open['expense']],
    ], 'budget' => [
        ['Encaissements', array_sum($current['plan']['income'] ?? []), $cash['income']],
        ['Décaissements', array_sum($current['plan']['expense'] ?? []), $cash['expense']],
    ]];
}

function scopedState(array $state, string $role, string $userId): array
{
    $projected = $state;
    $expenseInvoiceIds = [];
    if ($role === 'director') {
        foreach ($state['bureau'] as $period) {
            foreach ($period['invoices'] ?? [] as $invoice) {
                if (isset($invoice['expenseId'])) $expenseInvoiceIds[$invoice['id']] = true;
            }
        }
    }
    foreach ($state['bureau'] as $year => $data) {
        if ($role === 'equipment_manager') {
            $projected['bureau'][$year] = ['equipment' => $data['equipment'] ?? null];
            $projected['records'][$year] = [];
            continue;
        }
        if ($role === 'treasurer') {
            $projected['bureau'][$year] = picked($data, FINANCE_FIELDS);
            $projected['records'][$year] = array_map(
                fn(array $member): array => picked($member, ['id', 'first', 'last', 'team', 'paid']),
                $state['records'][$year]
            );
            continue;
        }
        $view = picked($data, [...OFFICE_FIELDS, 'closedAt']);
        if ($role === 'director') {
            if (isset($data['equipment'])) $view['equipment'] = $data['equipment'];
            $view['events'] = $data['events'] ?? [];
            $view['partners'] = $data['partners'] ?? [];
            $view['partnerCommitments'] = $data['partnerCommitments'] ?? [];
            $view['meetings'] = array_map(
                fn(array $meeting): array => picked($meeting, ['id', 'title', 'date', 'status']),
                $data['meetings'] ?? []
            );
        } else {
            unset($view['volunteers']);
            $view['registration'] = $data['registration'] ?? ['token' => '', 'instructions' => ''];
            $view['applications'] = $data['applications'] ?? [];
            $view['decisions'] = array_map(
                fn(array $decision): array => picked($decision, ['id', 'meetingId', 'voteId']),
                $data['decisions'] ?? []
            );
        }
        $view['report'] = financeReport($state, (int) $year);
        $view['opening'] = ['bank' => 0, 'cash' => 0];
        $view['plan'] = ['income' => [], 'expense' => []];
        $view['entries'] = [];
        if ($role === 'director') {
            $view['expenses'] = array_map(function (array $expense) use ($userId): array {
                if (($expense['submittedBy'] ?? '') === $userId) return $expense;
                return picked($expense, ['id', 'label', 'date', 'amount', 'status', 'invoiceId', 'analysis']);
            }, $data['expenses'] ?? []);
            $view['invoices'] = [];
            foreach ($data['invoices'] ?? [] as $invoice) {
                if (!isset($invoice['expenseId'])) continue;
                $view['invoices'][] = picked($invoice, ['id', 'expenseId', 'amount', 'kind', 'date']);
            }
        }
        foreach ($data['entries'] as $entry) {
            if (isset($entry['memberId'])) $view['entries'][] = picked($entry, ['memberId']);
            if (isset($expenseInvoiceIds[$entry['invoiceId'] ?? ''])) {
                $view['entries'][] = picked($entry, ['invoiceId', 'amount', 'date']);
            }
        }
        $projected['bureau'][$year] = $view;
    }
    return $projected;
}

function recordList(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items) || count($items) > 30000) {
        throw new InvalidArgumentException('Liste de données invalide.');
    }
    $ids = [];
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $item['id']) ||
            isset($ids[$item['id']])) throw new InvalidArgumentException('Identifiant absent ou en double.');
        $ids[$item['id']] = true;
    }
    return $items;
}

function financeLocked(array $state, int $year): bool
{
    foreach ($state['bureau'] as $otherYear => $data) {
        if ($otherYear >= $year && !empty($data['closedAt'])) return true;
    }
    return false;
}

function mergeMemberRecords(array $old, mixed $input): array
{
    $items = recordList($input);
    $previous = array_column($old, null, 'id');
    $merged = [];
    foreach ($items as $item) {
        foreach (['first', 'last', 'team'] as $key) {
            if (!is_string($item[$key] ?? null) || trim($item[$key]) === '' || strlen($item[$key]) > 240) {
                throw new InvalidArgumentException('Adhérent incomplet.');
            }
        }
        $merged[] = picked($item, ['id', 'first', 'last', 'team', 'fileStatus', 'fileNote', 'renewal']) +
            ['paid' => (bool) ($previous[$item['id']]['paid'] ?? false)];
        unset($previous[$item['id']]);
    }
    if ($previous) throw new InvalidArgumentException('Le registre contient des adhésions manquantes.');
    return $merged;
}

function mergeDirectorExpenses(array $current, mixed $input, string $userId): array
{
    $byId = array_column($current, null, 'id');
    foreach (recordList($input) as $expense) {
        $existing = $byId[$expense['id']] ?? null;
        if ($existing && (($existing['submittedBy'] ?? '') !== $userId || $existing['status'] !== 'Déposée')) continue;
        if (($expense['status'] ?? '') !== 'Déposée') continue;
        $byId[$expense['id']] = picked($expense, ['id', 'date', 'label', 'party', 'category', 'amount', 'analysis', 'receipt']) +
            ['submittedBy' => $userId, 'status' => 'Déposée'];
    }
    return array_values($byId);
}

function validateFinance(array $data): void
{
    $helloAssoUrl = $data['helloAssoUrl'] ?? '';
    if (!is_string($helloAssoUrl) || strlen($helloAssoUrl) > 500 ||
        ($helloAssoUrl !== '' && !preg_match('~^https://(?:www\.)?helloasso\.com/associations/[a-z0-9_-]+/adhesions/[a-z0-9_-]+/?$~iD', $helloAssoUrl))) {
        throw new InvalidArgumentException('Lien de campagne HelloAsso invalide.');
    }
    $accounts = ['bank', 'cash'];
    foreach ($accounts as $account) {
        if (!is_int($data['opening'][$account] ?? null)) throw new InvalidArgumentException('Solde d’ouverture invalide.');
    }
    foreach (['entries', 'invoices', 'expenses', 'bankLines'] as $key) recordList($data[$key] ?? []);
    foreach ([...($data['entries'] ?? []), ...($data['invoices'] ?? [])] as $item) {
        if (!is_int($item['amount'] ?? null) || $item['amount'] < 1 || $item['amount'] > 99999999900 ||
            !in_array($item['kind'] ?? '', ['income', 'expense'], true) ||
            !is_string($item['date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item['date'])) {
            throw new InvalidArgumentException('Opération ou facture invalide.');
        }
    }
    foreach (['income', 'expense'] as $kind) {
        if (!is_array($data['plan'][$kind] ?? null) || array_filter($data['plan'][$kind], fn($amount) => !is_int($amount))) {
            throw new InvalidArgumentException('Budget invalide.');
        }
    }
}

/**
 * Keeps the volunteer names taken from a public link after the director's page was loaded.
 * The director could not have seen them, so an empty slot in the incoming data is not a removal.
 */
function keepPublicClaims(array $existing, array $incoming): array
{
    $previous = [];
    foreach ($existing as $event) {
        foreach ($event['slots'] ?? [] as $slot) {
            if (($slot['owner'] ?? '') !== '') $previous[$event['id'] . "\n" . $slot['id']] = $slot['owner'];
        }
    }
    foreach ($incoming as &$event) {
        foreach ($event['slots'] as &$slot) {
            $key = $event['id'] . "\n" . $slot['id'];
            if ($slot['owner'] === '' && isset($previous[$key])) $slot['owner'] = $previous[$key];
        }
        unset($slot);
    }
    unset($event);
    return $incoming;
}

function mergeState(array $state, array $payload, string $role, string $userId, bool $behindPublicOnly = false): array
{
    $incomingBureau = $payload['bureau'] ?? null;
    $incomingRecords = $payload['records'] ?? null;
    if (!is_array($incomingBureau) || !is_array($incomingRecords) ||
        array_keys($state['bureau']) !== array_keys($incomingBureau) ||
        array_keys($state['records']) !== array_keys($incomingRecords)) {
        throw new InvalidArgumentException('Saisons incohérentes. Recharge la page.');
    }
    $next = $state;
    if ($role === 'equipment_manager') {
        $next['bureau'] = mergeEquipment($state['bureau'], $incomingBureau);
        return $next;
    }
    foreach ($state['bureau'] as $year => $existing) {
        $incoming = $incomingBureau[$year];
        if (!is_array($incoming)) throw new InvalidArgumentException('Saison invalide.');
        if ($role === 'treasurer') {
            $finance = picked($incoming, FINANCE_FIELDS);
            if (financeLocked($state, (int) $year) && $finance != picked($existing, FINANCE_FIELDS)) {
                throw new InvalidArgumentException('Cette saison est en lecture seule.');
            }
            if (!empty($finance['closedAt']) && empty($existing['closedAt'])) {
                $finance['closedAt'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
            }
            $merged = array_replace($existing, $finance);
            validateFinance($merged);
            $next['bureau'][$year] = $merged;
            continue;
        }

        $next['records'][$year] = mergeMemberRecords($state['records'][$year], $incomingRecords[$year]);
        $office = picked($incoming, $role === 'director' ? ['tasks', 'decisions', 'volunteers'] : ['meetings', 'tasks']);
        foreach ($office as $list) recordList($list);
        if ($role === 'director' && array_key_exists('events', $incoming)) {
            $office['events'] = validateClubEvents($incoming['events']);
            if ($behindPublicOnly) $office['events'] = keepPublicClaims($existing['events'] ?? [], $office['events']);
        }
        if ($role === 'director' && (array_key_exists('partners', $incoming) ||
            array_key_exists('partnerCommitments', $incoming))) {
            [$office['partners'], $office['partnerCommitments']] = validatePartners(
                array_key_exists('partners', $incoming) ? $incoming['partners'] : ($existing['partners'] ?? []),
                array_key_exists('partnerCommitments', $incoming) ? $incoming['partnerCommitments'] : ($existing['partnerCommitments'] ?? [])
            );
        }
        if (isset($office['meetings'])) {
            $oldMeetings = array_column($existing['meetings'] ?? [], null, 'id');
            foreach ($office['meetings'] as &$meeting) {
                if (isset($meeting['financialReport']) && $meeting['financialReport'] != ($oldMeetings[$meeting['id']]['financialReport'] ?? null)) {
                    $meeting['financialReport'] = financeReport($state, (int) $year) + [
                        'savedAt' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d'),
                        'closed' => !empty($existing['closedAt']),
                    ];
                }
            }
            unset($meeting);
        }
        $next['bureau'][$year] = array_replace($existing, $office);
        if ($role === 'secretary') {
            $decisions = $existing['decisions'] ?? [];
            $known = array_column($decisions, null, 'id');
            $meetings = array_column($next['bureau'][$year]['meetings'] ?? [], null, 'id');
            foreach (recordList($incoming['decisions'] ?? []) as $candidate) {
                if (isset($known[$candidate['id']])) continue;
                $meeting = $meetings[$candidate['meetingId'] ?? ''] ?? null;
                $votes = array_column($meeting['votes'] ?? [], null, 'id');
                $vote = $votes[$candidate['voteId'] ?? ''] ?? null;
                if (($meeting['type'] ?? '') !== 'Assemblée générale' || ($vote['outcome'] ?? '') !== 'Adoptée') {
                    throw new InvalidArgumentException('La décision doit provenir d’un vote adopté en AG.');
                }
                foreach ($decisions as $decision) {
                    if (($decision['meetingId'] ?? '') === $meeting['id'] && ($decision['voteId'] ?? '') === $vote['id']) {
                        continue 2;
                    }
                }
                $decisions[] = ['id' => $candidate['id'], 'meetingId' => $meeting['id'], 'voteId' => $vote['id'],
                    'title' => $vote['title'], 'status' => 'Adoptée', 'date' => $meeting['date']];
            }
            $next['bureau'][$year]['decisions'] = $decisions;
        }
        if ($role === 'director' && !financeLocked($state, (int) $year)) {
            $next['bureau'][$year]['expenses'] = mergeDirectorExpenses($existing['expenses'] ?? [], $incoming['expenses'] ?? [], $userId);
        }
    }
    if ($role === 'director') $next['bureau'] = mergeEquipment($next['bureau'], $incomingBureau);
    validateEventTokens($next['bureau']);
    return $next;
}

function eventText(mixed $value, bool $required = true): string
{
    if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException('Texte invalide.');
    }
    $value = trim($value);
    if (!preg_match('/^.{0,120}$/uD', $value) || ($required && $value === '')) {
        throw new InvalidArgumentException('Renseigne un texte de 120 caractères maximum.');
    }
    return $value;
}

function validEventToken(mixed $token): bool
{
    return is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token) === 1;
}

function validateClubEvents(mixed $items): array
{
    $events = [];
    foreach (recordList($items) as $item) {
        $date = $item['date'] ?? null;
        $token = $item['token'] ?? '';
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Date d’événement invalide.');
        }
        if ($token !== '' && !validEventToken($token)) {
            throw new InvalidArgumentException('Lien d’inscription invalide.');
        }
        $slots = [];
        foreach (recordList($item['slots'] ?? null) as $slot) {
            $start = $slot['start'] ?? null;
            $end = $slot['end'] ?? null;
            $clock = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';
            if (!is_string($start) || !is_string($end) || !preg_match($clock, $start) ||
                !preg_match($clock, $end) || $start >= $end) {
                throw new InvalidArgumentException('La fin du créneau doit être après le début.');
            }
            $slots[] = ['id' => $slot['id'], 'title' => eventText($slot['title'] ?? null),
                'start' => $start, 'end' => $end, 'owner' => eventText($slot['owner'] ?? '', false)];
        }
        $events[] = ['id' => $item['id'], 'title' => eventText($item['title'] ?? null), 'date' => $date,
            'place' => eventText($item['place'] ?? null), 'token' => $token, 'slots' => $slots];
    }
    return $events;
}

function validateEventTokens(array $bureau): void
{
    $seen = [];
    foreach ($bureau as $period) {
        foreach ($period['events'] ?? [] as $item) {
            $token = $item['token'];
            if ($token === '') continue;
            if (isset($seen[$token])) throw new InvalidArgumentException('Lien d’inscription en double.');
            $seen[$token] = true;
        }
    }
}

function findSharedEvent(array $bureau, string $token): ?array
{
    foreach ($bureau as $year => $period) {
        foreach ($period['events'] ?? [] as $index => $item) {
            if (hash_equals($item['token'], $token)) return [$year, $index];
        }
    }
    return null;
}

function attachmentInsert(PDO $database): PDOStatement
{
    return $database->prepare(
        "INSERT INTO gestclub_attachments (id, name, mime, content) VALUES (?, ?, ?, decode(?, 'base64'))"
    );
}

function storeAttachment(PDOStatement $insert, array $receipt): array
{
    $encoded = $receipt['data'] ?? null;
    $name = $receipt['name'] ?? null;
    $mime = $receipt['type'] ?? null;
    if (!is_string($encoded) || strlen($encoded) > 1398104 ||
        !is_string($name) || !trim($name) || strlen($name) > 255 ||
        !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new InvalidArgumentException('Justificatif invalide ou supérieur à 1 Mo.');
    }
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || !$bytes || strlen($bytes) > 1048576) {
        throw new InvalidArgumentException('Justificatif illisible ou supérieur à 1 Mo.');
    }
    $id = bin2hex(random_bytes(16));
    $name = str_replace(["\r", "\n", "\0"], '', $name);
    $insert->execute([$id, $name, $mime, base64_encode($bytes)]);
    return ['id' => $id, 'name' => $name, 'type' => $mime];
}

function persistAttachments(PDO $database, array $previous, array $next): array
{
    $insert = attachmentInsert($database);
    foreach ($next['bureau'] as $year => &$data) {
        foreach (['entries', 'invoices', 'expenses', 'partnerCommitments'] as $field) {
            if (!isset($data[$field])) continue;
            $oldItems = array_column($previous['bureau'][$year][$field] ?? [], null, 'id');
            foreach ($data[$field] as &$item) {
                if (!isset($item['receipt'])) continue;
                $receipt = $item['receipt'];
                if (!is_array($receipt)) throw new InvalidArgumentException('Justificatif invalide.');
                if (isset($receipt['id'])) {
                    $oldItem = $oldItems[$item['id']] ?? [];
                    $stored = $oldItem['receipt'] ?? [];
                    if ($field === 'partnerCommitments' && ($oldItem['partnerId'] ?? null) !== $item['partnerId']) {
                        throw new InvalidArgumentException('Cette photo appartient à un autre engagement.');
                    }
                    if (($stored['id'] ?? null) !== $receipt['id']) throw new InvalidArgumentException('Pièce jointe inconnue.');
                    $item['receipt'] = $stored;
                    continue;
                }
                $item['receipt'] = storeAttachment($insert, $receipt);
            }
            unset($item);
        }
    }
    unset($data);
    return $next;
}

function canReadAttachment(array $projected, string $id): bool
{
    foreach ($projected['bureau'] as $season) {
        foreach ($season['applications'] ?? [] as $item) {
            foreach ($item['files'] as $file) {
                if ($file['id'] === $id) return true;
            }
        }
        foreach (['entries', 'invoices', 'expenses', 'partnerCommitments'] as $field) {
            foreach ($season[$field] ?? [] as $item) {
                if (($item['receipt']['id'] ?? null) === $id) return true;
            }
        }
    }
    return false;
}
