<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

// Mot de passe oublié (sans jeton) et choix d’un mot de passe depuis un lien reçu par e-mail (avec jeton).
try {
    beginWebRequest('gestclub_password');
    $token = $_GET['jeton'] ?? null;
    $message = '';
    $done = false;
    $database = db();
    $target = $token === null ? null : passwordTokenUser($database, $token);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfValid($_POST['csrf'] ?? null)) {
            http_response_code(403);
            $message = flash('Formulaire expiré. Recharge la page.', true);
        } elseif ($token === null) {
            // Même réponse que le compte existe ou non.
            $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
            if (!rateLimitExceeded('reset', 5, 60) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $query = $database->prepare('SELECT id, email FROM gestclub_users WHERE email = ? AND active');
                $query->execute([$email]);
                if ($user = $query->fetch()) sendAccountLink($database, (int) $user['id'], $user['email'], false);
            }
            $message = flash('Si un compte actif correspond à cette adresse, un lien vient d’être envoyé. Il est valable une heure.');
            $done = true;
        } elseif ($target) {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $problem = passwordProblem($password);
            if ($problem === null && $password !== ($_POST['confirm'] ?? null)) $problem = 'Les deux mots de passe sont différents.';
            if ($problem) {
                $message = flash($problem, true);
            } else {
                setPassword($database, (int) $target['id'], $password);
                $message = flash('Mot de passe enregistré. Tu peux te connecter.');
                $done = true;
            }
        }
    }
} catch (Throwable $failure) {
    error_log('Fair Gestion mot de passe: ' . $failure::class);
    http_response_code(503);
    renderPage('Mot de passe', '<p class="error">Service indisponible. Réessaie dans quelques instants.</p>', null);
    exit;
}

$csrf = csrfField();
if ($token !== null && !$target && !$done) {
    http_response_code(404);
    $body = '<h1>Lien expiré</h1><p>Ce lien n’est plus valable. <a href="mot-de-passe.php">Demande un nouveau lien</a> ou contacte un dirigeant du club.</p>';
} elseif ($done) {
    $body = '<h1>Mot de passe</h1>' . $message . '<p><a href="./">Aller à la connexion</a></p>';
} elseif ($target) {
    $body = '<h1>Choisir mon mot de passe</h1><p>Compte <strong>' . textEscape($target['email']) . '</strong></p>' . $message .
        '<form method="post" class="stack">' . $csrf .
        '<input type="email" name="username" value="' . textEscape($target['email']) . '" autocomplete="username" hidden>' .
        '<label>Nouveau mot de passe <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>' .
        '<label>Confirmation <input type="password" name="confirm" autocomplete="new-password" minlength="12" maxlength="72" required></label>' .
        '<small>Au moins 12 caractères. Une phrase de plusieurs mots est facile à retenir et solide.</small>' .
        '<button type="submit">Enregistrer</button></form>';
} else {
    $body = '<h1>Mot de passe oublié</h1>' . $message .
        '<p>Indique l’adresse de ton compte Fair Gestion. Tu recevras un lien pour choisir un nouveau mot de passe.</p>' .
        '<form method="post" class="stack">' . $csrf .
        '<label>Adresse e-mail <input type="email" name="email" autocomplete="username" maxlength="254" required></label>' .
        '<button type="submit">Recevoir un lien</button></form><p><a href="./">Revenir à la connexion</a></p>';
}
renderPage('Mot de passe', $body, null);
