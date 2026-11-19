<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

// Flux iCalendar des matchs et événements : titre, date et lieu, aucun nom de bénévole.
function icsText(string $value): string
{
    return str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $value);
}

function icsLine(string $line): string
{
    // Lignes de 75 octets au plus, sans couper un caractère UTF-8.
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

try {
    $key = $_GET['cle'] ?? '';
    $database = db();
    $token = getSetting($database, 'calendar_token', '');
    if (!is_string($key) || !validEventToken($key) || !is_string($token) || $token === '' || !hash_equals($token, $key)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Agenda introuvable.\n";
        exit;
    }
    $state = fetchState($database);
    $host = parse_url(config()['origin'], PHP_URL_HOST) ?: 'fair-gestion';
    $stamp = gmdate('Ymd\THis\Z');
    $ics = icsLine('BEGIN:VCALENDAR') . icsLine('VERSION:2.0') . icsLine('PRODID:-//Fair Gestion//FR') .
        icsLine('CALSCALE:GREGORIAN') . icsLine('X-WR-CALNAME:' . icsText(config()['club_name'] . ' · matchs et événements'));
    foreach ($state['bureau'] as $period) {
        foreach ($period['events'] ?? [] as $event) {
            $day = new DateTimeImmutable($event['date']);
            $slots = array_map(fn(array $slot): string => $slot['title'] . ' ' . $slot['start'] . '–' . $slot['end'], $event['slots']);
            $ics .= icsLine('BEGIN:VEVENT') . icsLine('UID:' . $event['id'] . '@' . $host) . icsLine('DTSTAMP:' . $stamp) .
                icsLine('DTSTART;VALUE=DATE:' . $day->format('Ymd')) . icsLine('DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd')) .
                icsLine('SUMMARY:' . icsText($event['title'])) . icsLine('LOCATION:' . icsText($event['place'])) .
                ($slots ? icsLine('DESCRIPTION:' . icsText("Créneaux :\n" . implode("\n", $slots))) : '') . icsLine('END:VEVENT');
        }
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="agenda.ics"');
    header('Cache-Control: private, max-age=900');
    echo $ics . icsLine('END:VCALENDAR');
} catch (Throwable $failure) {
    error_log('Fair Gestion agenda: ' . $failure::class);
    http_response_code(503);
}
