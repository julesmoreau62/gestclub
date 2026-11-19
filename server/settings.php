<?php
declare(strict_types=1);

function getSetting(PDO $database, string $key, mixed $default = null): mixed
{
    $query = $database->prepare('SELECT value FROM gestclub_settings WHERE key = ?');
    $query->execute([$key]);
    $value = $query->fetchColumn();
    return $value === false ? $default : jsonDecode($value);
}

function setSetting(PDO $database, string $key, mixed $value): void
{
    $query = $database->prepare(
        'INSERT INTO gestclub_settings (key, value) VALUES (?, CAST(? AS jsonb))
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()'
    );
    $query->execute([$key, jsonEncode($value)]);
}

/** Club identity printed on documents (attestations, convocations, receipts). */
function association(): array
{
    $info = config()['association'] ?? [];
    $info = is_array($info) ? $info : [];
    return [
        'name' => (string) config()['club_name'],
        'address' => is_string($info['address'] ?? null) ? $info['address'] : '',
        'rna' => is_string($info['rna'] ?? null) ? $info['rna'] : '',
        'siren' => is_string($info['siren'] ?? null) ? $info['siren'] : '',
        'object' => is_string($info['object'] ?? null) ? $info['object'] : '',
        'signatory' => is_string($info['signatory'] ?? null) ? $info['signatory'] : '',
        'fiscal_receipts' => ($info['fiscal_receipts'] ?? false) === true,
    ];
}
