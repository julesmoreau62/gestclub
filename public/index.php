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
:root { font: 15px Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #162820; background: #f7f7f2 }
*, *::before, *::after { box-sizing: border-box }
body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px }
main { width: min(400px, 100%); padding: 28px; background: #fff; border: 1px solid #e4e8e1; border-radius: 15px }
.brand { display: flex; align-items: center; gap: 10px; margin: 0 0 22px; font-size: 22px; font-weight: 780; letter-spacing: -.06em }
.brand svg { width: 32px; height: 32px; padding: 5px; border-radius: 9px; background: #154433; color: #fff }
h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: -.03em } p { margin: 0 0 18px; color: #536359 }
form, label { display: grid; gap: 6px } form { gap: 14px } label { font-size: 13px; font-weight: 690 }
input, button { font: inherit; font-size: 16px; min-height: 44px; border: 1px solid #dbe1d9; border-radius: 8px; padding: 9px 11px }
button { margin-top: 4px; border-color: transparent; background: #154433; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer }
button:hover { background: #0d3225 }
input:focus-visible, button:focus-visible { outline: 2px solid #a54b33; outline-offset: 3px }
.error { padding: 10px 12px; border-radius: 8px; background: #fbece5; color: #843923; font-weight: 600 }
</style>

<main>
<div class="brand"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7h14v10H5zM12 7v10"/><circle cx="12" cy="12" r="2"/></svg>Gest’club</div>
<h1>Connexion</h1>
<p>Espace du bureau · <?= textEscape($club) ?></p>
<?php if ($error): ?><p class="error" role="alert"><?= textEscape($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<label>Adresse e-mail<input type="email" name="email" autocomplete="username" required maxlength="254"></label>
<label>Mot de passe<input type="password" name="password" autocomplete="current-password" required maxlength="72"></label>
<button type="submit">Se connecter</button>
</form>
</main>
</html>
