<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

// Documents imprimables (ou à enregistrer en PDF depuis le navigateur) : attestation d’adhésion,
// convocation à une réunion et reçu au titre des dons.
try {
    $user = pageUser();
    denyUnless(hasRole($user, 'director', 'secretary', 'treasurer'));
    $database = db();
    $state = fetchState($database);
    $association = association();
    $years = array_map('intval', array_keys($state['records']));
    $year = filter_var($_GET['saison'] ?? max($years), FILTER_VALIDATE_INT);
    if (!in_array($year, $years, true)) $year = max($years);
    $season = $year . '–' . ($year + 1);
    $type = $_GET['type'] ?? '';
    $finance = hasRole($user, 'treasurer');
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    $header = '<p class="muted">' . textEscape($association['name']) .
        ($association['address'] ? '<br>' . multiline($association['address']) : '') .
        ($association['rna'] ? '<br>RNA ' . textEscape($association['rna']) : '') .
        ($association['siren'] ? ' · SIREN ' . textEscape($association['siren']) : '') . '</p>';
    $signature = '<div class="signature"><p>Fait le ' . frenchDate($today) . '</p><p>' .
        ($association['signatory'] !== '' ? textEscape($association['signatory']) : 'Nom et qualité du signataire') .
        '</p><p class="muted">Signature</p></div>';
    $printButton = '<p class="no-print"><button type="button" onclick="print()">Imprimer ou enregistrer en PDF</button> <a href="documents.php?saison=' . $year . '">Autre document</a></p>';

    if ($type === 'attestation') {
        $member = array_column($state['records'][$year], null, 'id')[$_GET['membre'] ?? ''] ?? null;
        if (!$member) throw new InvalidArgumentException('Adhérent introuvable pour cette saison.');
        $entries = array_values(array_filter($state['bureau'][$year]['entries'], fn(array $entry): bool => ($entry['memberId'] ?? null) === $member['id']));
        $paid = !empty($member['paid']) || $entries;
        $payment = '';
        if ($paid) {
            $payment = ' et qu’il ou elle a réglé sa cotisation';
            if ($finance && $entries) {
                $total = array_sum(array_column($entries, 'amount'));
                $payment .= ' d’un montant de ' . euros($total) . ', encaissée le ' . frenchDate(max(array_column($entries, 'date')));
            }
        }
        $body = $printButton . '<article class="document">' . $header . '<h1>Attestation d’adhésion</h1>' .
            '<p>Le club ' . textEscape($association['name']) . ' atteste que <strong>' . textEscape($member['first'] . ' ' . $member['last']) .
            '</strong> est adhérent(e) du club pour la saison ' . $season . ' (équipe ' . textEscape($member['team']) . ')' . $payment . '.</p>' .
            '<p>Cette attestation est délivrée pour servir et valoir ce que de droit.</p>' . $signature . '</article>';
        renderPage('Attestation d’adhésion', $body, $user, true);
        exit;
    }

    if ($type === 'convocation') {
        denyUnless(hasRole($user, 'director', 'secretary'));
        $meeting = array_column($state['bureau'][$year]['meetings'] ?? [], null, 'id')[$_GET['reunion'] ?? ''] ?? null;
        if (!$meeting) throw new InvalidArgumentException('Réunion introuvable pour cette saison.');
        $assembly = ($meeting['type'] ?? '') === 'Assemblée générale';
        $body = $printButton . '<article class="document">' . $header . '<h1>Convocation</h1>' .
            '<p>Madame, Monsieur,</p><p>Vous êtes convoqué(e) à la réunion « <strong>' . textEscape($meeting['title']) . '</strong> » (' .
            textEscape($meeting['type'] ?? 'Réunion') . ') qui se tiendra le <strong>' . frenchDate($meeting['date']) . '</strong>' .
            (($meeting['place'] ?? '') !== '' ? ' — ' . textEscape($meeting['place']) : '') . '.</p>' .
            (($meeting['agenda'] ?? '') !== '' ? '<h2>Ordre du jour</h2><p>' . multiline($meeting['agenda']) . '</p>' : '') .
            ($assembly ? '<p>Si vous ne pouvez pas être présent(e), vous pouvez vous faire représenter par un autre membre au moyen du pouvoir ci-dessous, ' .
                'dans les conditions prévues par les statuts.</p>' : '') . $signature .
            ($assembly ? '<hr><h2>Pouvoir</h2><p>Je soussigné(e) ……………………………………, membre de l’association, donne pouvoir à ……………………………………' .
                ' pour me représenter à l’assemblée générale du ' . frenchDate($meeting['date']) . ' et prendre part aux votes en mon nom.</p>' .
                '<p>Fait à ………………………, le ………………………  Signature précédée de « Bon pour pouvoir »</p>' : '') . '</article>';
        renderPage('Convocation', $body, $user, true);
        exit;
    }

    if ($type === 'recu') {
        denyUnless($finance);
        if (!$association['fiscal_receipts']) throw new InvalidArgumentException('Les reçus fiscaux ne sont pas activés dans config.php.');
        $entry = array_column($state['bureau'][$year]['entries'], null, 'id')[$_GET['operation'] ?? ''] ?? null;
        if (!$entry || $entry['kind'] !== 'income' || !in_array($entry['category'] ?? '', ['Dons', 'Partenariats'], true)) {
            throw new InvalidArgumentException('Choisis un encaissement de la catégorie Dons ou Partenariats.');
        }
        $receipts = getSetting($database, 'fiscal_receipts', []);
        $existing = null;
        foreach ($receipts as $receipt) if ($receipt['entryId'] === $entry['id']) $existing = $receipt;
        if (!$existing) {
            if (!postedCsrfValid()) {
                // Premier passage : saisie du donateur.
                $body = '<h1>Reçu au titre des dons</h1><p>Encaissement du ' . frenchDate($entry['date']) . ' : ' . textEscape($entry['label']) . ' · ' . euros($entry['amount']) . '</p>' .
                    '<form method="post" class="stack">' . csrfField() .
                    '<label>Nom du donateur (personne ou entreprise) <input name="donor" maxlength="160" required></label>' .
                    '<label>Adresse du donateur <textarea name="address" maxlength="300" required></textarea></label>' .
                    '<label>Mode de versement <select name="mode"><option>Virement, prélèvement, carte bancaire</option><option>Chèque</option><option>Espèces</option></select></label>' .
                    '<label>Donateur <select name="kind"><option value="200">Particulier (article 200 du CGI)</option><option value="238">Entreprise (article 238 bis du CGI)</option></select></label>' .
                    '<p class="notice">Une fois créé, le reçu reçoit un numéro définitif et ne peut plus être modifié. Vérifie que le versement est bien un don, sans contrepartie pour le donateur.</p>' .
                    '<button type="submit">Créer le reçu</button></form>';
                renderPage('Reçu au titre des dons', $body, $user);
                exit;
            }
            $donor = registrationText($_POST['donor'] ?? '', 160, true);
            $address = registrationText($_POST['address'] ?? '', 300, true);
            $mode = in_array($_POST['mode'] ?? '', ['Virement, prélèvement, carte bancaire', 'Chèque', 'Espèces'], true) ? $_POST['mode'] : 'Chèque';
            $kind = ($_POST['kind'] ?? '') === '238' ? '238' : '200';
            $database->beginTransaction();
            fetchState($database, true); // sérialise la numérotation
            $receipts = getSetting($database, 'fiscal_receipts', []);
            $number = substr($entry['date'], 0, 4) . '-' . str_pad((string) (count(array_filter($receipts,
                fn(array $receipt): bool => str_starts_with($receipt['number'], substr($entry['date'], 0, 4)))) + 1), 4, '0', STR_PAD_LEFT);
            $existing = ['number' => $number, 'issuedAt' => $today, 'entryId' => $entry['id'], 'year' => $year, 'donor' => $donor,
                'address' => $address, 'mode' => $mode, 'kind' => $kind, 'amount' => $entry['amount'], 'date' => $entry['date']];
            $receipts[] = $existing;
            setSetting($database, 'fiscal_receipts', $receipts);
            recordAudit($database, $user['email'], 'treasurer', [['year' => $year, 'field' => 'entries', 'changed' => 1]]);
            $database->commit();
        }
        $article = $existing['kind'] === '238' ? '238 bis' : '200';
        $body = $printButton . '<article class="document">' . '<h1>Reçu au titre des dons à certains organismes d’intérêt général</h1>' .
            '<p class="muted">Articles 200, 238 bis et 978 du code général des impôts · modèle de référence : cerfa n° 11580</p>' .
            '<p><strong>Numéro d’ordre du reçu : ' . textEscape($existing['number']) . '</strong></p>' .
            '<h2>Bénéficiaire des versements</h2><p>' . textEscape($association['name']) . '<br>' . multiline($association['address']) .
            ($association['rna'] ? '<br>RNA ' . textEscape($association['rna']) : '') . ($association['siren'] ? ' · SIREN ' . textEscape($association['siren']) : '') .
            '<br>Objet : ' . textEscape($association['object']) . '<br>Œuvre ou organisme d’intérêt général</p>' .
            '<h2>Donateur</h2><p>' . textEscape($existing['donor']) . '<br>' . multiline($existing['address']) . '</p>' .
            '<p>Le bénéficiaire reconnaît avoir reçu au titre des dons et versements ouvrant droit à réduction d’impôt la somme de <strong>' .
            euros($existing['amount']) . '</strong>, soit <strong>' . amountInWords($existing['amount']) . '</strong>.</p>' .
            '<p>Date du versement : ' . frenchDate($existing['date']) . '<br>Le bénéficiaire certifie sur l’honneur que les dons et versements qu’il reçoit ouvrent droit ' .
            'à la réduction d’impôt prévue à l’article ' . $article . ' du CGI.<br>Forme du don : don manuel · Nature du don : numéraire · Mode de versement : ' .
            textEscape($existing['mode']) . '</p>' .
            '<div class="signature"><p>Fait le ' . frenchDate($existing['issuedAt']) . '</p><p>' . textEscape($association['signatory'] ?: 'Nom et qualité du signataire') .
            '</p><p class="muted">Signature</p></div></article>';
        renderPage('Reçu ' . $existing['number'], $body, $user, true);
        exit;
    }

    // Accueil des documents.
    $seasonOptions = '';
    foreach (array_reverse($years) as $option) {
        $seasonOptions .= '<option value="' . $option . '"' . ($option === $year ? ' selected' : '') . '>' . $option . '–' . ($option + 1) . '</option>';
    }
    $body = '<h1>Documents</h1><p>Chaque document s’ouvre dans une page prête à imprimer ou à enregistrer en PDF depuis le navigateur.</p>' .
        '<form method="get" class="inline"><label>Saison <select name="saison">' . $seasonOptions . '</select></label><button class="secondary">Afficher</button></form>';
    if ($association['address'] === '' || $association['signatory'] === '') {
        $body .= '<p class="notice">Complète <code>association</code> dans <code>config.php</code> (adresse, signataire, RNA ou SIREN) pour que les documents portent ces mentions.</p>';
    }
    $members = $state['records'][$year];
    usort($members, fn(array $a, array $b): int => strcoll($a['last'] . $a['first'], $b['last'] . $b['first']));
    $memberOptions = implode('', array_map(fn(array $member): string => '<option value="' . textEscape($member['id']) . '">' .
        textEscape($member['last'] . ' ' . $member['first'] . ' · ' . $member['team']) . '</option>', $members));
    $body .= '<h2>Attestation d’adhésion</h2><p>Pour un comité d’entreprise, une mutuelle, le Pass’Sport…' .
        ($finance ? ' Le montant et la date d’encaissement figurent sur l’attestation.' : ' Le trésorier peut y faire figurer le montant payé.') . '</p>' .
        ($members ? '<form method="get" class="inline"><input type="hidden" name="type" value="attestation"><input type="hidden" name="saison" value="' . $year . '">' .
            '<select name="membre" required>' . $memberOptions . '</select><button>Ouvrir</button></form>' : '<p class="muted">Aucun adhérent cette saison.</p>');

    if (hasRole($user, 'director', 'secretary')) {
        $meetings = $state['bureau'][$year]['meetings'] ?? [];
        $body .= '<h2>Convocation</h2><p>Reprend la date, le lieu et l’ordre du jour de la réunion. Pour une AG, un pouvoir est ajouté en bas de page.</p>' .
            ($meetings ? '<form method="get" class="inline"><input type="hidden" name="type" value="convocation"><input type="hidden" name="saison" value="' . $year . '"><select name="reunion" required>' .
                implode('', array_map(fn(array $meeting): string => '<option value="' . textEscape($meeting['id']) . '">' . (new DateTimeImmutable($meeting['date']))->format('d/m/Y') .
                    ' · ' . textEscape($meeting['title']) . '</option>', $meetings)) . '</select><button>Ouvrir</button></form>' : '<p class="muted">Aucune réunion cette saison.</p>');
    }

    if ($finance) {
        $body .= '<h2>Reçus au titre des dons</h2>';
        if (!$association['fiscal_receipts']) {
            $body .= '<p class="notice">Seule une association d’intérêt général (ou reconnue d’utilité publique) peut délivrer ces reçus. Si c’est le cas du club, ' .
                'renseigne <code>association</code> et <code>fiscal_receipts =&gt; true</code> dans <code>config.php</code>. En cas de doute, demande un rescrit fiscal à l’administration.</p>';
        } else {
            $gifts = array_filter($state['bureau'][$year]['entries'], fn(array $entry): bool =>
                $entry['kind'] === 'income' && in_array($entry['category'] ?? '', ['Dons', 'Partenariats'], true));
            $issued = array_column(getSetting($database, 'fiscal_receipts', []), 'number', 'entryId');
            $body .= '<p>Encaissements des catégories Dons et Partenariats. Un partenariat avec contreparties (logo, visibilité) est du parrainage : il n’ouvre pas droit à un reçu.</p>';
            if (!$gifts) $body .= '<p class="muted">Aucun encaissement de ce type cette saison.</p>';
            else {
                $body .= '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Libellé</th><th>Montant</th><th>Reçu</th></tr></thead><tbody>';
                foreach ($gifts as $gift) {
                    $link = 'documents.php?type=recu&amp;saison=' . $year . '&amp;operation=' . rawurlencode($gift['id']);
                    $body .= '<tr><td>' . (new DateTimeImmutable($gift['date']))->format('d/m/Y') . '</td><td>' . textEscape($gift['label']) . '</td><td>' .
                        euros($gift['amount']) . '</td><td><a href="' . $link . '">' . (isset($issued[$gift['id']]) ? 'N° ' . textEscape($issued[$gift['id']]) : 'Créer le reçu') . '</a></td></tr>';
                }
                $body .= '</tbody></table></div>';
            }
        }
    }
} catch (InvalidArgumentException $failure) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    renderPage('Documents', flash($failure->getMessage(), true) . '<p><a href="documents.php">Revenir aux documents</a></p>', $user ?? null);
    exit;
} catch (Throwable $failure) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    error_log('Fair Gestion documents: ' . $failure::class . ' ' . $failure->getMessage());
    http_response_code(503);
    renderPage('Documents', '<p class="error">Service indisponible. Réessaie dans quelques instants.</p>', null);
    exit;
}
renderPage('Documents', $body, $user);
