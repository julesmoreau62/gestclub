<?php
declare(strict_types=1);

// Rapprochement des adhésions payées sur HelloAsso (API v5) avec le registre du club.

function helloAssoSettings(): ?array
{
    $settings = config()['helloasso'] ?? null;
    if (!is_array($settings) || !is_string($settings['client_id'] ?? null) || !is_string($settings['client_secret'] ?? null) ||
        $settings['client_id'] === '' || $settings['client_secret'] === '') return null;
    return ['id' => $settings['client_id'], 'secret' => $settings['client_secret'],
        'api' => rtrim(is_string($settings['api'] ?? null) ? $settings['api'] : 'https://api.helloasso.com', '/')];
}

/** Organisation and form slugs from the public campaign link stored for the season. */
function helloAssoSlugs(string $url): ?array
{
    if (!preg_match('~^https://(?:www\.)?helloasso\.com/associations/([a-z0-9_-]+)/adhesions/([a-z0-9_-]+)/?$~iD', $url, $parts)) return null;
    return [strtolower($parts[1]), strtolower($parts[2])];
}

function helloAssoRequest(string $method, string $url, array $headers, ?string $body = null): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'timeout' => 20, 'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) $status = (int) $match[1];
    }
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("HelloAsso a répondu $status.");
    }
    $data = json_decode($response, true);
    if (!is_array($data)) throw new RuntimeException('Réponse HelloAsso illisible.');
    return $data;
}

/**
 * Access token, cached in gestclub_settings. HelloAsso asks clients to reuse their token and refresh it
 * instead of asking for a new one on every call.
 */
function helloAssoToken(PDO $database, array $settings): string
{
    $cached = getSetting($database, 'helloasso_token', []);
    if (($cached['expires'] ?? 0) > time() + 60 && is_string($cached['access'] ?? null)) return $cached['access'];
    $form = ['Content-Type: application/x-www-form-urlencoded'];
    $data = null;
    if (is_string($cached['refresh'] ?? null) && ($cached['refreshExpires'] ?? 0) > time() + 60) {
        try {
            $data = helloAssoRequest('POST', $settings['api'] . '/oauth2/token', $form, http_build_query(
                ['grant_type' => 'refresh_token', 'refresh_token' => $cached['refresh'], 'client_id' => $settings['id']]));
        } catch (RuntimeException) {
            $data = null;
        }
    }
    $data ??= helloAssoRequest('POST', $settings['api'] . '/oauth2/token', $form, http_build_query(
        ['grant_type' => 'client_credentials', 'client_id' => $settings['id'], 'client_secret' => $settings['secret']]));
    if (!is_string($data['access_token'] ?? null)) throw new RuntimeException('Jeton HelloAsso absent.');
    setSetting($database, 'helloasso_token', [
        'access' => $data['access_token'], 'expires' => time() + (int) ($data['expires_in'] ?? 1700),
        'refresh' => $data['refresh_token'] ?? null, 'refreshExpires' => time() + 29 * 86400,
    ]);
    return $data['access_token'];
}

/** Paid membership items of a form: one line per member, with the payment order. */
function helloAssoPaidItems(PDO $database, array $settings, string $organization, string $formSlug): array
{
    $token = helloAssoToken($database, $settings);
    $base = $settings['api'] . '/v5/organizations/' . rawurlencode($organization) . '/forms/Membership/' . rawurlencode($formSlug) . '/orders';
    $items = [];
    $continuation = null;
    for ($page = 0; $page < 100; $page++) {
        $query = ['pageSize' => 100] + ($continuation ? ['continuationToken' => $continuation] : []);
        $data = helloAssoRequest('GET', $base . '?' . http_build_query($query), ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $orders = $data['data'] ?? [];
        if (!is_array($orders) || !$orders) break;
        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if (($item['state'] ?? '') !== 'Processed' || !is_int($item['amount'] ?? null) || $item['amount'] < 1) continue;
                if (isset($item['type']) && $item['type'] !== 'Membership') continue;
                $person = is_array($item['user'] ?? null) ? $item['user'] : ($order['payer'] ?? []);
                $items[] = [
                    'id' => (string) $item['id'], 'order' => (string) ($order['id'] ?? ''), 'amount' => $item['amount'],
                    'first' => (string) ($person['firstName'] ?? ''), 'last' => (string) ($person['lastName'] ?? ''),
                    'email' => strtolower((string) ($order['payer']['email'] ?? '')),
                    'date' => (new DateTimeImmutable((string) ($order['date'] ?? 'now')))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d'),
                ];
            }
        }
        $continuation = $data['pagination']['continuationToken'] ?? null;
        if (!is_string($continuation) || $continuation === '') break;
    }
    return $items;
}

function personKey(string $first, string $last): string
{
    $text = mb_strtolower(trim($first) . ' ' . trim($last), 'UTF-8');
    if (class_exists(Normalizer::class)) $text = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($text, Normalizer::FORM_D));
    return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
}

/**
 * Adds a cotisation entry for each paid HelloAsso membership that matches exactly one unpaid member.
 * Pure function: takes the state and the HelloAsso lines, returns the new state and a report.
 */
function applyHelloAssoItems(array $state, int $year, array $items, array $imported): array
{
    // Lignes déjà importées une fois : une écriture modifiée ou supprimée par le trésorier ne revient pas.
    $known = array_fill_keys($imported, true);
    $members = $state['records'][$year];
    $paidIds = array_column(array_filter($state['bureau'][$year]['entries'], fn(array $entry): bool => isset($entry['memberId'])), 'memberId');
    $added = [];
    $unmatched = [];
    foreach ($items as $item) {
        if (isset($known[$item['id']])) continue;
        $matches = array_values(array_filter($members, fn(array $member): bool =>
            personKey($member['first'], $member['last']) === personKey($item['first'], $item['last'])));
        if (count($matches) !== 1) {
            $unmatched[] = ['name' => trim($item['first'] . ' ' . $item['last']), 'amount' => $item['amount'], 'date' => $item['date'],
                'reason' => $matches ? 'Plusieurs adhérents portent ce nom' : 'Aucun adhérent à ce nom dans le registre'];
            continue;
        }
        $member = $matches[0];
        if (!empty($member['paid']) || in_array($member['id'], $paidIds, true)) {
            $unmatched[] = ['name' => $member['first'] . ' ' . $member['last'], 'amount' => $item['amount'], 'date' => $item['date'],
                'reason' => 'Cotisation déjà enregistrée : vérifie s’il s’agit d’un doublon'];
            continue;
        }
        $state['bureau'][$year]['entries'][] = [
            'id' => 'helloasso-' . preg_replace('/[^A-Za-z0-9_-]/', '', $item['id']), 'date' => $item['date'],
            'label' => mb_substr('Cotisation · ' . $member['first'] . ' ' . $member['last'] . ' (HelloAsso)', 0, 145),
            'kind' => 'income', 'account' => 'bank', 'category' => 'Cotisations', 'amount' => $item['amount'],
            'reference' => mb_substr('HelloAsso commande ' . $item['order'], 0, 100), 'cleared' => false,
            'memberId' => $member['id'], 'analysis' => ['team' => '', 'event' => ''], 'helloAssoItem' => $item['id'],
        ];
        $paidIds[] = $member['id'];
        $known[$item['id']] = true;
        $added[] = $member['first'] . ' ' . $member['last'];
    }
    return [$state, $added, $unmatched, array_keys($known)];
}

/** Synchronises every open season that has a HelloAsso campaign. Returns the report saved for the treasurer. */
function syncHelloAsso(PDO $database): array
{
    $settings = helloAssoSettings();
    if (!$settings) throw new InvalidArgumentException('HelloAsso n’est pas configuré dans config.php.');
    $state = fetchState($database);
    $fetched = [];
    foreach ($state['bureau'] as $year => $period) {
        $slugs = helloAssoSlugs($period['helloAssoUrl'] ?? '');
        if (!$slugs || financeLocked($state, (int) $year)) continue;
        $fetched[$year] = helloAssoPaidItems($database, $settings, ...$slugs);
    }
    // Appels réseau terminés : on verrouille l’état le temps d’écrire.
    $database->beginTransaction();
    $state = fetchState($database, true);
    $before = $state;
    $report = ['at' => date('c'), 'seasons' => []];
    $imported = getSetting($database, 'helloasso_imported', []);
    foreach ($fetched as $year => $items) {
        if (financeLocked($state, (int) $year)) continue;
        [$state, $added, $unmatched, $imported] = applyHelloAssoItems($state, (int) $year, $items, $imported);
        validateFinance($state['bureau'][$year]);
        $report['seasons'][] = ['year' => (int) $year, 'received' => count($items), 'added' => $added, 'unmatched' => $unmatched];
    }
    setSetting($database, 'helloasso_imported', $imported);
    if ($state['bureau'] != $before['bureau']) {
        $database->prepare('UPDATE gestclub_state SET bureau = CAST(? AS jsonb), revision = revision + 1, staff_revision = revision + 1,
            updated_at = now() WHERE id = true')->execute([jsonEncode($state['bureau'])]);
        recordAudit($database, 'HelloAsso', 'system', changeSummary($before, $state));
    }
    setSetting($database, 'helloasso_report', $report);
    $database->commit();
    return $report;
}
