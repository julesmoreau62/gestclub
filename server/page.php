<?php
declare(strict_types=1);

/** Signed-in user for a server-rendered page, or a redirect to the login page. */
function pageUser(): array
{
    beginWebRequest();
    $user = currentUser();
    if (!$user) {
        header('Location: ./', true, 303);
        exit;
    }
    if (requiresTwoFactor($user['roles']) && !$user['two_factor'] && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'compte.php') {
        header('Location: compte.php?obligatoire=1#double-authentification', true, 303);
        exit;
    }
    return $user;
}

function hasRole(array $user, string ...$roles): bool
{
    return array_intersect($roles, $user['roles']) !== [];
}

function denyUnless(bool $allowed): void
{
    if ($allowed) return;
    http_response_code(403);
    renderPage('Accès réservé', '<p>Cette page est réservée à d’autres rôles du bureau.</p>', null);
    exit;
}

/** Links of the extra pages a user can open. */
function extraPages(array $user): array
{
    $links = [['compte.php', 'Mon compte']];
    if (hasRole($user, 'director', 'secretary', 'treasurer')) $links[] = ['documents.php', 'Documents'];
    if (hasRole($user, 'treasurer')) $links[] = ['relances.php', 'Cotisations et HelloAsso'];
    $links[] = ['historique.php', 'Historique'];
    return $links;
}

function postedCsrfValid(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid($_POST['csrf'] ?? null);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . textEscape($_SESSION['csrf']) . '">';
}

function flash(string $message, bool $error = false): string
{
    return '<p class="' . ($error ? 'error' : 'success') . '" role="' . ($error ? 'alert' : 'status') . '">' . textEscape($message) . '</p>';
}

function renderPage(string $title, string $body, ?array $user, bool $printable = false): void
{
    $club = textEscape((string) config()['club_name']);
    $nav = '';
    if ($user) {
        $items = ['<a href="./">← Tableau de bord</a>'];
        foreach (extraPages($user) as [$href, $label]) {
            $current = basename($_SERVER['SCRIPT_NAME'] ?? '') === $href ? ' aria-current="page"' : '';
            $items[] = '<a href="' . $href . '"' . $current . '>' . textEscape($label) . '</a>';
        }
        $nav = '<nav class="no-print" aria-label="Pages du compte">' . implode('', $items) . '</nav>';
    }
    $safeTitle = textEscape($title);
    $width = $printable ? '800px' : '760px';
    echo <<<HTML
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="./assets/fair-icone.svg">
<title>{$safeTitle} · Fair Gestion</title>
<style>
:root { font: 16px Inter, system-ui, sans-serif; color: #182943; background: #eff3f7 }
body { margin: 0; padding: 24px 12px }
main { box-sizing: border-box; max-width: {$width}; margin: auto; background: white; border: 1px solid #dbe2e8; border-top: 4px solid #1fb57a; border-radius: 12px; padding: clamp(16px, 5vw, 32px) }
nav { max-width: {$width}; margin: 0 auto 12px; display: flex; flex-wrap: wrap; gap: 6px 16px }
nav a { color: #13294b; font-weight: 600; text-decoration: none } nav a[aria-current] { text-decoration: underline }
.brand { color: #13294b; font-weight: 750; display: flex; align-items: center; gap: 8px; margin: 0 }
h1 { font-size: clamp(1.5rem, 5vw, 2rem); margin: 8px 0 16px } h2 { font-size: 1.15rem; margin-top: 32px }
form.stack, form.stack label { display: grid; gap: 6px } form.stack { gap: 14px; margin: 12px 0 }
input, select, textarea, button { box-sizing: border-box; font: inherit; border: 1px solid #aebdcb; border-radius: 8px; padding: 9px; min-width: 0; max-width: 100% }
textarea { width: 100%; min-height: 120px }
button, .button { background: #13294b; color: white; cursor: pointer; font-weight: 700; text-decoration: none; display: inline-block }
button.secondary { background: white; color: #13294b }
button.danger { background: white; color: #8e2428; border-color: #8e2428 }
.inline { display: inline-flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 4px 0 }
.checks { display: flex; flex-wrap: wrap; gap: 6px 16px } .checks label { display: flex; gap: 6px; align-items: center }
table { width: 100%; border-collapse: collapse; margin: 12px 0 } th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #dbe2e8; vertical-align: top }
.table-wrap { overflow-x: auto }
small, .muted { color: #4e5e70 }
.error { color: #8e2428 } .success { padding: 12px; color: #07533b; background: #e1f4eb; border-radius: 8px }
.notice { padding: 12px; background: #edf5fa; border-radius: 8px }
code, .secret { font-family: ui-monospace, monospace; overflow-wrap: anywhere }
.document { line-height: 1.55 } .document h1 { text-align: center } .signature { margin-top: 48px; text-align: right }
@media print { body { background: white; padding: 0 } main { border: 0; padding: 0; max-width: none } .no-print { display: none !important } }
</style>
{$nav}
<main>
<p class="brand no-print"><img src="./assets/fair-icone.svg" alt="" width="28" height="28">Fair Gestion · {$club}</p>
{$body}
</main>
</html>
HTML;
}
