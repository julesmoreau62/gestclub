<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

try {
    beginWebRequest();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfValid($_POST['csrf'] ?? null)) {
            http_response_code(403);
            $error = 'Formulaire expiré. Recharge la page.';
        } elseif (isset($_POST['logout'])) {
            $_SESSION = [];
            session_destroy();
            header('Location: ./', true, 303);
            exit;
        } elseif (is_string($_POST['email'] ?? null) && is_string($_POST['password'] ?? null) &&
            login($_POST['email'], $_POST['password'])) {
            header('Location: ./', true, 303);
            exit;
        } else {
            $error = 'Connexion refusée. Vérifie tes identifiants ou réessaie plus tard.';
        }
    }
    $user = currentUser();
    $club = config()['club_name'];
    if ($user) {
        $role = $user['roles'][0];
        $data = [
            'clubName' => $club, 'roles' => $user['roles'], 'role' => $role, 'userId' => (string) $user['id'],
            'csrf' => $_SESSION['csrf'], 'state' => scopedState(fetchState(db()), $role, (string) $user['id']),
        ];
        $board = file_get_contents(dirname(__DIR__) . '/index.html');
        $payload = jsonEncode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo str_replace('</head>', '<script>window.gestclubRemote = ' . $payload . ';</script></head>', $board);
        exit;
    }
    $csrf = textEscape($_SESSION['csrf']);
} catch (Throwable $failure) {
    error_log('Gestclub: ' . $failure::class);
    http_response_code(503);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Gest’club</title><p>Gest’club est indisponible. Vérifie la configuration et l’installation de PostgreSQL.</p></html>';
    exit;
}
?>
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · Gest’club</title>
<style>
:root { font: 16px system-ui, sans-serif; color: #192d21; background: #f6f7f2 }
body { margin: 0; min-height: 100dvh; display: grid; place-items: center }
main { box-sizing: border-box; width: min(420px, calc(100% - 32px)); padding: 28px; background: white; border: 1px solid #dde4dc; border-radius: 18px }
h1 { margin: 5px 0 20px } p { margin: 0 0 12px }
form, label { display: grid; gap: 8px } form { gap: 15px }
input, button { font: inherit; border: 1px solid #d5ded4; border-radius: 8px; padding: 11px }
button { background: #245139; color: white; cursor: pointer }
.error { color: #8e2428 }
</style>
<main>
<p><?= textEscape($club) ?></p>
<h1>Connexion à Gest’club</h1>
<?php if ($error): ?><p class="error" role="alert"><?= textEscape($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<label>Adresse e-mail<input type="email" name="email" autocomplete="username" required maxlength="254"></label>
<label>Mot de passe<input type="password" name="password" autocomplete="current-password" required maxlength="72"></label>
<button type="submit">Se connecter</button>
</form>
</main>
</html>
