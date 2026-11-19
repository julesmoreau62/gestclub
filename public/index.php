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
        echo strtr($board, [
            './public/assets/' => './assets/',
            '</head>' => '<script>window.gestclubRemote = ' . $payload . ';</script></head>',
        ]);
        exit;
    }
    $csrf = textEscape($_SESSION['csrf']);
} catch (Throwable $failure) {
    error_log('Fair Gestion: ' . $failure::class);
    http_response_code(503);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Fair Gestion</title><p>Fair Gestion est indisponible. Vérifie la configuration et l’installation de PostgreSQL.</p></html>';
    exit;
}
?>
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="./assets/fair-icone.svg">
<title>Connexion · Fair Gestion</title>
<style>
:root { font: 16px Inter, system-ui, sans-serif; color: #182943; background: #13294b }
body { margin: 0; min-height: 100dvh; display: grid; place-items: center }
main { box-sizing: border-box; width: min(420px, calc(100% - 32px)); padding: clamp(20px, 6vw, 32px); background: white; border: 1px solid #dbe2e8; border-top: 4px solid #1fb57a; border-radius: 12px }
.association-logo { display: block; width: min(100%, 250px); height: auto; margin-bottom: 18px }
h1 { margin: 5px 0 20px; letter-spacing: -.045em } p { margin: 0 0 12px; color: #4e5e70 }
form, label { display: grid; gap: 8px } form { gap: 15px }
input, button { font: inherit; border: 1px solid #aebdcb; border-radius: 8px; padding: 11px }
button { background: #13294b; color: white; cursor: pointer; font-weight: 700 }
button:hover { background: #224568 }
input:focus-visible, button:focus-visible { outline: 2px solid #147556; outline-offset: 3px }
.error { color: #8e2428 }
</style>
<main>
<img class="association-logo" src="./assets/fair-logo-horizontal.svg" width="505" height="140" alt="FAIR — Former les Associations à l’IA Responsable">
<p><?= textEscape($club) ?></p>
<h1>Connexion à Fair Gestion</h1>
<?php if ($error): ?><p class="error" role="alert"><?= textEscape($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<label>Adresse e-mail<input type="email" name="email" autocomplete="username" required maxlength="254"></label>
<label>Mot de passe<input type="password" name="password" autocomplete="current-password" required maxlength="72"></label>
<button type="submit">Se connecter</button>
</form>
</main>
</html>
