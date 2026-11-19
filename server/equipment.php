<?php
declare(strict_types=1);

function equipmentYear(array $bureau): int
{
    foreach ($bureau as $year => $period) {
        if (array_key_exists('equipment', $period)) return (int) $year;
    }
    return (int) min(array_keys($bureau));
}

function mergeEquipment(array $bureau, array $incoming): array
{
    $inventoryYear = equipmentYear($bureau);
    foreach ($incoming as $year => $period) {
        if (!is_array($period)) throw new InvalidArgumentException('Saison invalide.');
        if (!isset($period['equipment'])) continue;
        if ((int) $year !== $inventoryYear) throw new InvalidArgumentException('Inventaire du club incohérent.');
        $bureau[$year]['equipment'] = validateEquipment($period['equipment'], $bureau[$year]['equipment'] ?? []);
    }
    return $bureau;
}

function equipmentQuantity(mixed $value): int
{
    if (!is_int($value) || $value < 1 || $value > 10000) {
        throw new InvalidArgumentException('La quantité doit être un entier entre 1 et 10 000.');
    }
    return $value;
}

function validateEquipment(mixed $data, ?array $previous = null): array
{
    if (!is_array($data)) throw new InvalidArgumentException('Inventaire invalide.');
    $items = [];
    foreach (recordList($data['items'] ?? null) as $item) {
        $condition = $item['condition'] ?? null;
        $archived = $item['archived'] ?? null;
        if (!in_array($condition, ['Bon état', 'À réparer', 'À remplacer'], true) || !is_bool($archived)) {
            throw new InvalidArgumentException('État du matériel invalide.');
        }
        $items[$item['id']] = [
            'id' => $item['id'], 'name' => eventText($item['name'] ?? null),
            'category' => eventText($item['category'] ?? '', false), 'location' => eventText($item['location'] ?? '', false),
            'quantity' => equipmentQuantity($item['quantity'] ?? null), 'condition' => $condition,
            'notes' => registrationText($item['notes'] ?? '', 600), 'archived' => $archived,
        ];
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    $loans = [];
    $outstanding = [];
    $previousLoans = $previous === null ? null : array_column($previous['loans'] ?? [], null, 'id');
    foreach (recordList($data['loans'] ?? null) as $loan) {
        $itemId = $loan['itemId'] ?? null;
        if (!is_string($itemId) || !isset($items[$itemId])) {
            throw new InvalidArgumentException('Le matériel prêté est introuvable.');
        }
        $item = $items[$itemId];
        if ($previousLoans !== null && !isset($previousLoans[$loan['id']]) &&
            ($item['archived'] || $item['condition'] !== 'Bon état')) {
            throw new InvalidArgumentException('Ce matériel est indisponible au prêt.');
        }
        $date = calendarDate($loan['date'] ?? '');
        $due = calendarDate($loan['due'] ?? '');
        if ($date === '' || $date > $today || ($due !== '' && $due < $date)) {
            throw new InvalidArgumentException('Vérifie la date du prêt et la date de retour prévue.');
        }
        $quantity = equipmentQuantity($loan['quantity'] ?? null);
        $entries = $loan['returns'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > $quantity) {
            throw new InvalidArgumentException('Historique des retours invalide.');
        }
        $returns = [];
        $returned = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Retour invalide.');
            $returnedDate = calendarDate($entry['date'] ?? '');
            if ($returnedDate === '' || $returnedDate < $date || $returnedDate > $today) {
                throw new InvalidArgumentException('Un retour doit dater du prêt ou d’un jour ultérieur.');
            }
            $units = equipmentQuantity($entry['quantity'] ?? null);
            $returned += $units;
            $returns[] = ['date' => $returnedDate, 'quantity' => $units];
        }
        if ($returned > $quantity) throw new InvalidArgumentException('Le retour dépasse la quantité prêtée.');
        $outstanding[$itemId] = ($outstanding[$itemId] ?? 0) + $quantity - $returned;
        $clean = ['id' => $loan['id'], 'itemId' => $itemId, 'borrower' => eventText($loan['borrower'] ?? null),
            'quantity' => $quantity, 'date' => $date, 'due' => $due, 'returns' => $returns];
        $existing = $previousLoans[$loan['id']] ?? null;
        if ($existing !== null) {
            $keys = ['itemId', 'borrower', 'quantity', 'date'];
            if (picked($clean, $keys) != picked($existing, $keys) ||
                array_slice($returns, 0, count($existing['returns'])) != $existing['returns']) {
                throw new InvalidArgumentException('Les données d’un prêt et ses retours enregistrés restent dans l’historique.');
            }
            unset($previousLoans[$loan['id']]);
        }
        $loans[] = $clean;
    }
    foreach ($previousLoans ?? [] as $loan) {
        if ($loan['returns']) throw new InvalidArgumentException('Ce prêt a déjà des retours enregistrés.');
    }
    foreach ($outstanding as $id => $quantity) {
        if ($quantity > $items[$id]['quantity'] || ($quantity > 0 && $items[$id]['archived'])) {
            throw new InvalidArgumentException('Des unités sont encore prêtées. Vérifie la quantité en stock avant de modifier ou d’archiver la fiche.');
        }
    }
    return ['items' => array_values($items), 'loans' => $loans];
}
