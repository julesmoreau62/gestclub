<?php
declare(strict_types=1);

const AUDIT_LABELS = [
    'records' => 'Adhérents', 'entries' => 'Opérations de trésorerie', 'invoices' => 'Factures', 'plan' => 'Budget',
    'opening' => 'Soldes d’ouverture', 'expenses' => 'Notes de frais', 'bankLines' => 'Relevés bancaires',
    'statement' => 'Rapprochement bancaire', 'closedAt' => 'Clôture de la saison', 'helloAssoUrl' => 'Lien HelloAsso',
    'meetings' => 'Réunions et PV', 'tasks' => 'Plan d’action', 'decisions' => 'Décisions', 'volunteers' => 'Postes bénévoles',
    'events' => 'Matchs et événements', 'partners' => 'Partenaires', 'partnerCommitments' => 'Engagements partenaires',
    'equipment' => 'Matériel', 'registration' => 'Formulaire d’inscription', 'applications' => 'Dossiers d’inscription',
];
const AUDIT_FINANCE = ['entries', 'invoices', 'plan', 'opening', 'expenses', 'bankLines', 'statement', 'closedAt', 'helloAssoUrl'];
const AUDIT_OFFICE = ['records', 'meetings', 'tasks', 'decisions', 'registration', 'applications'];

function isIdList(mixed $value): bool
{
    if (!is_array($value) || !array_is_list($value)) return false;
    foreach ($value as $item) {
        if (!is_array($item) || !isset($item['id'])) return false;
    }
    return true;
}

/** Counts of added, changed and removed items for one field. */
function listDifference(mixed $before, mixed $after): ?array
{
    if ($before == $after) return null;
    if ((isIdList($before) || $before === null) && isIdList($after)) {
        $old = array_column($before ?? [], null, 'id');
        $new = array_column($after, null, 'id');
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0];
        foreach ($new as $id => $item) {
            if (!isset($old[$id])) $counts['added']++;
            elseif ($old[$id] != $item) $counts['changed']++;
        }
        foreach ($old as $id => $item) if (!isset($new[$id])) $counts['removed']++;
        return array_filter($counts);
    }
    return ['changed' => 1];
}

/** Readable summary of what a save changed, without copying personal data into the log. */
function changeSummary(array $before, array $after, mixed $command = null): array
{
    $summary = [];
    foreach ($after['records'] as $year => $members) {
        $diff = listDifference($before['records'][$year] ?? [], $members);
        if ($diff) $summary[] = ['year' => (int) $year, 'field' => 'records'] + $diff;
    }
    foreach ($after['bureau'] as $year => $period) {
        $fields = array_unique([...array_keys($period), ...array_keys($before['bureau'][$year] ?? [])]);
        foreach ($fields as $field) {
            $diff = listDifference($before['bureau'][$year][$field] ?? null, $period[$field] ?? null);
            if ($diff) $summary[] = ['year' => (int) $year, 'field' => (string) $field] + $diff;
        }
    }
    if (is_array($command) && ($command['action'] ?? null) === 'review' && is_string($command['status'] ?? null)) {
        $summary[] = ['year' => (int) ($command['year'] ?? 0), 'field' => 'applications', 'decision' => $command['status']];
    }
    return $summary;
}

function recordAudit(PDO $database, string $actor, string $role, array $summary): void
{
    if (!$summary) return;
    $query = $database->prepare('INSERT INTO gestclub_audit (actor, role, summary) VALUES (?, ?, CAST(? AS jsonb))');
    $query->execute([$actor, $role, jsonEncode($summary)]);
}

/** Fields of the history a role may read. */
function auditVisible(string $field, array $roles): bool
{
    if (in_array('director', $roles, true)) return true;
    if (in_array('treasurer', $roles, true) && in_array($field, AUDIT_FINANCE, true)) return true;
    if (in_array('secretary', $roles, true) && in_array($field, AUDIT_OFFICE, true)) return true;
    return in_array('equipment_manager', $roles, true) && $field === 'equipment';
}

function auditLine(array $change): string
{
    $label = AUDIT_LABELS[$change['field']] ?? $change['field'];
    if (isset($change['decision'])) return "$label : dossier « {$change['decision']} »";
    $parts = [];
    foreach (['added' => 'ajout', 'changed' => 'modification', 'removed' => 'suppression'] as $key => $word) {
        $count = $change[$key] ?? 0;
        if ($count) $parts[] = $count . ' ' . $word . ($count > 1 ? 's' : '');
    }
    return $label . ($parts ? ' : ' . implode(', ', $parts) : ' : modifié');
}
