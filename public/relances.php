<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

const REMINDER_DELAY_DAYS = 7;
const REMINDER_BATCH = 300;

function unpaidMembers(array $state, int $year): array
{
    $paid = [];
    foreach ($state['bureau'][$year]['entries'] as $entry) if (isset($entry['memberId'])) $paid[$entry['memberId']] = true;
    return array_values(array_filter($state['records'][$year], fn(array $member): bool => empty($member['paid']) && !isset($paid[$member['id']])));
}

function canRemind(array $member, string $today): bool
{
    if (($member['email'] ?? '') === '') return false;
    $last = $member['remindedAt'] ?? '';
    return $last === '' || (new DateTimeImmutable($last))->modify('+' . REMINDER_DELAY_DAYS . ' days')->format('Y-m-d') <= $today;
}

try {
    $user = pageUser();
    denyUnless(hasRole($user, 'treasurer'));
    $database = db();
    $notice = '';
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    $state = fetchState($database);
    $years = array_map('intval', array_keys($state['records']));
    $year = filter_var($_POST['year'] ?? $_GET['saison'] ?? max($years), FILTER_VALIDATE_INT);
    if (!in_array($year, $years, true)) $year = max($years);
    $season = $year . '–' . ($year + 1);
    $defaultMessage = "La cotisation pour la saison $season n’est pas encore enregistrée par le club.\n" .
        "Merci de la régler dès que possible. Si tu l’as déjà fait, ne tiens pas compte de ce message ou réponds au trésorier.";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!postedCsrfValid()) {
            $notice = flash('Formulaire expiré. Recharge la page.', true);
        } elseif (($_POST['do'] ?? '') === 'sync') {
            try {
                $report = syncHelloAsso($database);
                $count = array_sum(array_map(fn(array $item): int => count($item['added']), $report['seasons']));
                $notice = flash("Synchronisation terminée : $count cotisation(s) ajoutée(s) au journal.");
            } catch (InvalidArgumentException | RuntimeException $failure) {
                if ($database->inTransaction()) $database->rollBack();
                error_log('Fair Gestion HelloAsso: ' . $failure->getMessage());
                $notice = flash('Synchronisation impossible : ' . $failure->getMessage(), true);
            }
        } elseif (($_POST['do'] ?? '') === 'remind') {
            if (!mailEnabled() && !is_string(config()['mail_log'] ?? null)) {
                $notice = flash('L’envoi d’e-mails n’est pas configuré (mail_from dans config.php).', true);
            } elseif (financeLocked($state, $year)) {
                $notice = flash('Cette saison est clôturée.', true);
            } else {
                $message = is_string($_POST['message'] ?? null) ? registrationText($_POST['message'], 1500, true) : $defaultMessage;
                $database->beginTransaction();
                $state = fetchState($database, true);
                $before = $state;
                $link = $state['bureau'][$year]['helloAssoUrl'] ?? '';
                $sent = 0;
                $byId = array_column($state['records'][$year], null, 'id');
                foreach (unpaidMembers($state, $year) as $member) {
                    if ($sent >= REMINDER_BATCH || !canRemind($member, $today)) continue;
                    $body = "Bonjour,\n\n$message\n\nAdhérent : {$member['first']} {$member['last']}" .
                        ($link ? "\n\nPayer en ligne sur HelloAsso :\n$link" : '');
                    if (sendMail($member['email'], 'Cotisation ' . $season . ' · ' . config()['club_name'], $body)) {
                        $byId[$member['id']]['remindedAt'] = $today;
                        $sent++;
                    }
                }
                $state['records'][$year] = array_values($byId);
                $database->prepare('UPDATE gestclub_state SET records = CAST(? AS jsonb), revision = revision + 1, staff_revision = revision + 1,
                    updated_at = now() WHERE id = true')->execute([jsonEncode($state['records'])]);
                recordAudit($database, $user['email'], 'treasurer', $sent ? [['year' => $year, 'field' => 'records', 'changed' => $sent]] : []);
                $database->commit();
                $notice = flash("$sent relance(s) envoyée(s).");
            }
        }
        $state = fetchState($database);
    }

    $unpaid = unpaidMembers($state, $year);
    $ready = count(array_filter($unpaid, fn(array $member): bool => canRemind($member, $today)));
    $noEmail = count(array_filter($unpaid, fn(array $member): bool => ($member['email'] ?? '') === ''));
    $seasonOptions = '';
    foreach (array_reverse($years) as $option) {
        $seasonOptions .= '<option value="' . $option . '"' . ($option === $year ? ' selected' : '') . '>' . $option . '–' . ($option + 1) . '</option>';
    }

    $body = '<h1>Cotisations et HelloAsso</h1>' . $notice .
        '<form method="get" class="inline"><label>Saison <select name="saison">' . $seasonOptions . '</select></label><button class="secondary">Afficher</button></form>';

    $body .= '<h2>Relancer les cotisations</h2>';
    $names = array_map(fn(array $member): string => textEscape($member['first'] . ' ' . $member['last']) .
        (($member['remindedAt'] ?? '') ? ' <small>(relancé le ' . (new DateTimeImmutable($member['remindedAt']))->format('d/m/Y') . ')</small>' : '') .
        (($member['email'] ?? '') === '' ? ' <small>(pas d’e-mail)</small>' : ''), $unpaid);
    $body .= '<p>' . count($unpaid) . ' cotisation(s) en attente. ' . $ready . ' peuvent être relancées maintenant' .
        ($noEmail ? ", $noEmail n’ont pas d’e-mail de contact (à compléter par le secrétaire)" : '') .
        '. Un même adhérent n’est relancé qu’une fois tous les ' . REMINDER_DELAY_DAYS . ' jours.</p>' .
        ($names ? '<details><summary>Voir la liste</summary><ul><li>' . implode('</li><li>', $names) . '</li></ul></details>' : '') .
        '<form method="post" class="stack">' . csrfField() . '<input type="hidden" name="do" value="remind"><input type="hidden" name="year" value="' . $year . '">' .
        '<label>Message <textarea name="message" maxlength="1500" required>' . textEscape($defaultMessage) . '</textarea></label>' .
        '<small>Chaque e-mail commence par « Bonjour, », rappelle le nom de l’adhérent et ajoute le lien HelloAsso de la saison s’il est configuré.</small>' .
        '<button type="submit"' . ($ready ? '' : ' disabled') . '>Envoyer ' . $ready . ' relance(s)</button></form>';

    $body .= '<h2>Paiements HelloAsso</h2>';
    if (!helloAssoSettings()) {
        $body .= '<p class="notice">Pour enregistrer automatiquement les adhésions payées en ligne, demande une clé API dans ton espace HelloAsso ' .
            '(rubrique API) puis renseigne <code>helloasso</code> dans <code>config.php</code>.</p>';
    } else {
        $body .= '<p>Chaque nuit, Fair Gestion lit les adhésions payées sur la campagne HelloAsso de chaque saison ouverte. ' .
            'Quand le nom correspond à un seul adhérent du registre, la cotisation est ajoutée au journal de trésorerie (compte Banque, à rapprocher).</p>' .
            '<form method="post">' . csrfField() . '<button name="do" value="sync">Synchroniser maintenant</button></form>';
        $report = getSetting($database, 'helloasso_report');
        if ($report) {
            $body .= '<p class="muted">Dernière synchronisation : ' . (new DateTimeImmutable($report['at']))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i') . '</p>';
            foreach ($report['seasons'] as $line) {
                $body .= '<p><strong>Saison ' . $line['year'] . '–' . ($line['year'] + 1) . '</strong> : ' . $line['received'] . ' adhésion(s) payée(s) sur HelloAsso, ' .
                    count($line['added']) . ' ajoutée(s) lors de cette synchronisation.</p>';
                if ($line['unmatched']) {
                    $body .= '<p>À traiter à la main :</p><div class="table-wrap"><table><thead><tr><th>Nom sur HelloAsso</th><th>Montant</th><th>Date</th><th>Raison</th></tr></thead><tbody>';
                    foreach ($line['unmatched'] as $item) {
                        $body .= '<tr><td>' . textEscape($item['name']) . '</td><td>' . number_format($item['amount'] / 100, 2, ',', ' ') . ' €</td><td>' .
                            (new DateTimeImmutable($item['date']))->format('d/m/Y') . '</td><td>' . textEscape($item['reason']) . '</td></tr>';
                    }
                    $body .= '</tbody></table></div>';
                }
            }
        }
    }
} catch (InvalidArgumentException $failure) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    renderPage('Cotisations', flash($failure->getMessage(), true) . '<p><a href="relances.php">Revenir</a></p>', $user ?? null);
    exit;
} catch (Throwable $failure) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    error_log('Fair Gestion relances: ' . $failure::class . ' ' . $failure->getMessage());
    http_response_code(503);
    renderPage('Cotisations', '<p class="error">Service indisponible. Réessaie dans quelques instants.</p>', null);
    exit;
}
renderPage('Cotisations et HelloAsso', $body, $user);
