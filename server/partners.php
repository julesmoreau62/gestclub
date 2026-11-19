<?php
declare(strict_types=1);

function calendarDate(mixed $value): string
{
    if ($value === '') return '';
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) ||
        !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        throw new InvalidArgumentException('Date invalide.');
    }
    return $value;
}

function validatePartners(mixed $partners, mixed $commitments): array
{
    $cleanPartners = [];
    foreach (recordList($partners) as $item) {
        $status = $item['status'] ?? null;
        if (!in_array($status, ['Prospect', 'Actif', 'Terminé'], true)) {
            throw new InvalidArgumentException('Statut de partenariat invalide.');
        }
        $email = registrationText($item['email'] ?? '', 254);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse e-mail du contact invalide.');
        }
        $cleanPartners[] = [
            'id' => $item['id'], 'name' => eventText($item['name'] ?? null), 'status' => $status,
            'contact' => eventText($item['contact'] ?? '', false), 'email' => $email,
            'phone' => registrationText($item['phone'] ?? '', 40), 'renewalDate' => calendarDate($item['renewalDate'] ?? ''),
            'notes' => registrationText($item['notes'] ?? '', 600),
        ];
    }
    $ids = array_fill_keys(array_column($cleanPartners, 'id'), true);
    $cleanCommitments = [];
    foreach (recordList($commitments) as $item) {
        $partnerId = $item['partnerId'] ?? null;
        $done = $item['done'] ?? null;
        if (!is_string($partnerId) || !isset($ids[$partnerId]) || !is_bool($done)) {
            throw new InvalidArgumentException('Engagement de partenariat invalide.');
        }
        $clean = [
            'id' => $item['id'], 'partnerId' => $partnerId,
            'title' => eventText($item['title'] ?? null), 'due' => calendarDate($item['due'] ?? ''), 'done' => $done,
        ];
        if (isset($item['receipt'])) $clean['receipt'] = validatePartnerPhoto($item['receipt']);
        $cleanCommitments[] = $clean;
    }
    return [$cleanPartners, $cleanCommitments];
}

function validatePartnerPhoto(mixed $receipt): array
{
    if (is_array($receipt)) {
        if (isset($receipt['id']) && validEventToken($receipt['id'])) return ['id' => $receipt['id']];
        $data = $receipt['data'] ?? null;
        $type = $receipt['type'] ?? null;
        if (is_string($data) && strlen($data) <= 1398104 &&
            in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $bytes = base64_decode($data, true);
            $image = $bytes !== false && $bytes !== '' && strlen($bytes) <= 1048576
                ? @getimagesizefromstring($bytes) : false;
            if ($image && $image['mime'] === $type) return picked($receipt, ['name', 'type', 'data']);
        }
    }
    throw new InvalidArgumentException('Choisis une photo JPEG, PNG ou WebP de 1 Mo maximum.');
}
