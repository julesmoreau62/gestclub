<?php
declare(strict_types=1);

/** Full address of a page of the instance, from the configured origin. */
function appUrl(string $path): string
{
    return rtrim(config()['origin'], '/') . '/' . ltrim($path, '/');
}

function mailEnabled(): bool
{
    return is_string(config()['mail_from'] ?? null) && trim(config()['mail_from']) !== '';
}

/**
 * Sends a plain text e-mail. Returns false when sending is not configured or fails, without throwing:
 * an e-mail never cancels the action that triggered it.
 * With 'mail_log' set in config.php, the message is written to that file instead (tests, local demo).
 */
function sendMail(string $to, string $subject, string $body): bool
{
    $to = trim(str_replace(["\r", "\n"], '', $to));
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $club = (string) config()['club_name'];
    $body = rtrim($body) . "\n\n-- \n$club\nMessage envoyé par Fair Gestion. Merci de ne pas répondre directement à cette adresse.\n";
    $log = config()['mail_log'] ?? null;
    if (is_string($log) && $log !== '') {
        return file_put_contents($log, jsonEncode(['to' => $to, 'subject' => $subject, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX) !== false;
    }
    if (!mailEnabled()) return false;
    $from = trim(str_replace(["\r", "\n"], '', config()['mail_from']));
    $headers = [
        'From' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
    $replyTo = privacyContact();
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers['Reply-To'] = $replyTo;
    $sent = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);
    if (!$sent) error_log('Fair Gestion: envoi d’e-mail impossible.');
    return $sent;
}

/** E-mails of the active accounts that hold one of the roles. */
function accountEmails(PDO $database, string $role): array
{
    $query = $database->prepare('SELECT email FROM gestclub_users WHERE active AND roles @> CAST(? AS jsonb) ORDER BY email');
    $query->execute([jsonEncode([$role])]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function registrationMail(array $item, int $year): array
{
    $club = (string) config()['club_name'];
    $link = appUrl('inscriptions.php?dossier=' . $item['id']);
    $name = $item['first'] . ' ' . $item['last'];
    $season = $year . '–' . ($year + 1);
    return match ($item['status']) {
        'À vérifier' => ["Dossier d’inscription reçu · $club", "Bonjour,\n\nLe club a bien reçu le dossier d’inscription de $name pour la saison $season.\n" .
            "Le secrétariat va le vérifier. Tu peux suivre son état et ajouter des pièces depuis ce lien privé :\n$link\n\n" .
            "Garde ce message : ce lien permet de retrouver le dossier."],
        'Incomplet' => ["Dossier d’inscription à compléter · $club", "Bonjour,\n\nLe dossier d’inscription de $name est incomplet.\n\n" .
            "Message du club :\n{$item['note']}\n\nAjoute les pièces demandées depuis ton lien de suivi :\n$link"],
        'Validé' => ["Inscription validée · $club", "Bonjour,\n\nL’inscription de $name pour la saison $season est validée. Bienvenue au club !" .
            ($item['note'] !== '' ? "\n\nMessage du club :\n{$item['note']}" : '')],
        'Refusé' => ["Dossier d’inscription · $club", "Bonjour,\n\nLe club n’a pas pu valider le dossier d’inscription de $name." .
            ($item['note'] !== '' ? "\n\nMessage du club :\n{$item['note']}" : '') . "\n\nPour toute question, contacte le club."],
    };
}

function sendRegistrationMail(array $item, int $year): bool
{
    [$subject, $body] = registrationMail($item, $year);
    return sendMail($item['email'], $subject, $body);
}
