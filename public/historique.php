<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

try {
    $user = pageUser();
    $database = db();
    $rows = $database->query('SELECT at, actor, role, summary FROM gestclub_audit ORDER BY at DESC, id DESC LIMIT 1000')->fetchAll();
    $lines = '';
    $shown = 0;
    foreach ($rows as $row) {
        $changes = array_filter(jsonDecode($row['summary']), fn(array $change): bool => auditVisible($change['field'], $user['roles']));
        if (!$changes || $shown >= 300) continue;
        $shown++;
        $when = (new DateTimeImmutable($row['at']))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
        $who = $row['role'] === 'public' ? $row['actor'] : $row['actor'] . ' · ' . (ROLE_LABELS[$row['role']] ?? $row['role']);
        $items = '';
        foreach ($changes as $change) {
            $season = $change['year'] ? ' <small>(saison ' . $change['year'] . '–' . ($change['year'] + 1) . ')</small>' : '';
            $items .= '<li>' . textEscape(auditLine($change)) . $season . '</li>';
        }
        $lines .= '<tr><td>' . $when . '</td><td>' . textEscape($who) . '</td><td><ul>' . $items . '</ul></td></tr>';
    }
    $body = '<h1>Historique des modifications</h1>' .
        '<p>Qui a modifié quoi et quand. Tu vois les rubriques de ton rôle ; les dirigeants voient tout. Les entrées de plus de 5 ans sont supprimées.</p>' .
        ($lines ? '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Par</th><th>Modifications</th></tr></thead><tbody>' . $lines . '</tbody></table></div>'
            : '<p class="notice">Aucune modification enregistrée pour le moment.</p>');
} catch (Throwable $failure) {
    error_log('Fair Gestion historique: ' . $failure::class);
    http_response_code(503);
    renderPage('Historique', '<p class="error">Historique indisponible.</p>', null);
    exit;
}
renderPage('Historique', $body, $user);
