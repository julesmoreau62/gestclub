<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

try {
    $user = pageUser();
    $database = db();
    $userId = (int) $user['id'];
    $isDirector = hasRole($user, 'director');
    $notice = '';
    $shownLink = '';

    if (isset($_GET['obligatoire']) && !$user['two_factor']) {
        $notice = flash('Ton rôle demande la double authentification. Active-la ci-dessous pour accéder au tableau de bord.', true);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!postedCsrfValid()) {
            $notice = flash('Formulaire expiré. Recharge la page.', true);
        } else {
            $do = $_POST['do'] ?? '';
            try {
                $notice = match ($do) {
                    'password' => changeOwnPassword($database, $userId),
                    'totp-enable' => enableTotp($database, $userId),
                    'totp-disable' => disableTotp($database, $userId, $user),
                    'invite', 'roles', 'disable', 'enable', 'resend', 'reset-2fa' => $isDirector
                        ? manageAccount($database, $do, $userId, $shownLink) : flash('Action réservée aux dirigeants.', true),
                    'calendar-on', 'calendar-off' => $isDirector
                        ? calendarSetting($database, $do === 'calendar-on') : flash('Action réservée aux dirigeants.', true),
                    default => flash('Action inconnue.', true),
                };
            } catch (InvalidArgumentException $failure) {
                $notice = flash($failure->getMessage(), true);
            }
            $user = currentUser() ?? $user;
        }
    }

    $body = '<h1>Mon compte</h1>' . $notice;
    if ($shownLink !== '') {
        $body .= '<p class="notice">L’envoi d’e-mails n’est pas configuré. Transmets ce lien à la personne par un canal privé. Il est valable 7 jours :<br><code>' .
            textEscape($shownLink) . '</code></p>';
    }
    $roles = implode(', ', array_map(fn(string $role): string => ROLE_LABELS[$role] ?? $role, $user['roles']));
    $body .= '<p>Connecté avec <strong>' . textEscape($user['email']) . '</strong> · ' . textEscape($roles) . '</p>';

    $body .= '<h2>Changer de mot de passe</h2><form method="post" class="stack">' . csrfField() .
        '<input type="hidden" name="do" value="password"><input type="email" name="username" value="' . textEscape($user['email']) . '" autocomplete="username" hidden>' .
        '<label>Mot de passe actuel <input type="password" name="current" autocomplete="current-password" maxlength="72" required></label>' .
        '<label>Nouveau mot de passe <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>' .
        '<label>Confirmation <input type="password" name="confirm" autocomplete="new-password" minlength="12" maxlength="72" required></label>' .
        '<button type="submit">Changer le mot de passe</button></form>';

    $body .= '<h2 id="double-authentification">Double authentification</h2>';
    if ($user['two_factor']) {
        $body .= '<p>Activée. À chaque connexion, Fair Gestion demande le code de ton application d’authentification.</p>';
        if (!requiresTwoFactor($user['roles'])) {
            $body .= '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="do" value="totp-disable">' .
                '<label>Code actuel <input name="code" inputmode="numeric" autocomplete="one-time-code" size="8" required></label>' .
                '<button type="submit" class="danger">Désactiver</button></form>';
        }
    } else {
        $_SESSION['totp_pending'] ??= base32Encode(random_bytes(20));
        $secret = $_SESSION['totp_pending'];
        $body .= '<p>Protège ton compte avec un code à 6 chiffres en plus du mot de passe. Installe une application d’authentification ' .
            '(Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…), choisis « saisir une clé » et recopie :</p>' .
            '<p class="notice secret"><strong>' . textEscape(trim(chunk_split($secret, 4, ' '))) . '</strong></p>' .
            '<p><small>Sur téléphone, <a href="' . textEscape(totpUri($user['email'], $secret)) . '">ce lien</a> ouvre directement l’application.</small></p>' .
            '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="do" value="totp-enable">' .
            '<label>Code affiché <input name="code" inputmode="numeric" autocomplete="one-time-code" size="8" required></label>' .
            '<button type="submit">Activer</button></form>' .
            '<p><small>En cas de perte du téléphone, un dirigeant peut retirer la double authentification de ton compte.</small></p>';
    }

    if ($isDirector) $body .= accountsSection($database, $userId) . calendarSection($database);
} catch (Throwable $failure) {
    error_log('Fair Gestion compte: ' . $failure::class . ' ' . $failure->getMessage());
    http_response_code(503);
    renderPage('Mon compte', '<p class="error">Service indisponible. Réessaie dans quelques instants.</p>', null);
    exit;
}
renderPage('Mon compte', $body, $user);

function changeOwnPassword(PDO $database, int $userId): string
{
    $query = $database->prepare('SELECT password_hash FROM gestclub_users WHERE id = ?');
    $query->execute([$userId]);
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    if (rateLimitExceeded('password-change', 10, 15) || strlen($current) > 72 || !password_verify($current, (string) $query->fetchColumn())) {
        throw new InvalidArgumentException('Mot de passe actuel incorrect.');
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $problem = passwordProblem($password) ?? ($password !== ($_POST['confirm'] ?? null) ? 'Les deux mots de passe sont différents.' : null);
    if ($problem) throw new InvalidArgumentException($problem);
    setPassword($database, $userId, $password);
    $version = $database->prepare('SELECT session_version FROM gestclub_users WHERE id = ?');
    $version->execute([$userId]);
    startSession($userId, (int) $version->fetchColumn());
    return flash('Mot de passe changé. Tes autres appareils sont déconnectés.');
}

function enableTotp(PDO $database, int $userId): string
{
    $secret = $_SESSION['totp_pending'] ?? null;
    $step = is_string($secret) && is_string($_POST['code'] ?? null) ? totpStep($secret, $_POST['code']) : null;
    if ($step === null) throw new InvalidArgumentException('Code incorrect. Vérifie l’heure du téléphone et réessaie.');
    $database->prepare('UPDATE gestclub_users SET totp_secret = ?, totp_last_step = ? WHERE id = ?')->execute([$secret, $step, $userId]);
    unset($_SESSION['totp_pending']);
    return flash('Double authentification activée.');
}

function disableTotp(PDO $database, int $userId, array $user): string
{
    if (requiresTwoFactor($user['roles'])) throw new InvalidArgumentException('Ton rôle demande la double authentification.');
    $database->beginTransaction();
    $valid = is_string($_POST['code'] ?? null) && verifyAccountTotp($database, $userId, $_POST['code']);
    if ($valid) $database->prepare('UPDATE gestclub_users SET totp_secret = NULL WHERE id = ?')->execute([$userId]);
    $database->commit();
    if (!$valid) throw new InvalidArgumentException('Code incorrect.');
    return flash('Double authentification désactivée.');
}

function postedRoles(): array
{
    $roles = array_values(array_intersect(GESTCLUB_ROLES, is_array($_POST['roles'] ?? null) ? $_POST['roles'] : []));
    if (!$roles) throw new InvalidArgumentException('Choisis au moins un rôle.');
    return $roles;
}

function activeDirectors(PDO $database, ?int $except = null): int
{
    $query = $database->prepare("SELECT count(*) FROM gestclub_users WHERE active AND roles @> '[\"director\"]' AND id <> ?");
    $query->execute([$except ?? 0]);
    return (int) $query->fetchColumn();
}

function manageAccount(PDO $database, string $do, int $selfId, string &$shownLink): string
{
    if ($do === 'invite') {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Adresse e-mail invalide.');
        $roles = postedRoles();
        $query = $database->prepare(
            'INSERT INTO gestclub_users (email, password_hash, roles) VALUES (?, ?, CAST(? AS jsonb))
             ON CONFLICT (email) DO NOTHING RETURNING id'
        );
        $query->execute([$email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), jsonEncode($roles)]);
        $id = $query->fetchColumn();
        if ($id === false) throw new InvalidArgumentException('Un compte existe déjà pour cette adresse.');
        return inviteResult($database, (int) $id, $email, $shownLink, 'Compte créé.');
    }
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $query = $database->prepare('SELECT id, email, roles, active FROM gestclub_users WHERE id = ?');
    $query->execute([$id]);
    $target = $query->fetch();
    if (!$target) throw new InvalidArgumentException('Compte introuvable.');
    $targetId = (int) $target['id'];
    $wasDirector = in_array('director', jsonDecode($target['roles']), true) && $target['active'];
    switch ($do) {
        case 'roles':
            $roles = postedRoles();
            if ($wasDirector && !in_array('director', $roles, true) && activeDirectors($database, $targetId) === 0) {
                throw new InvalidArgumentException('Le club doit garder au moins un dirigeant actif.');
            }
            $database->prepare('UPDATE gestclub_users SET roles = CAST(? AS jsonb), session_version = session_version + 1 WHERE id = ?')
                ->execute([jsonEncode($roles), $targetId]);
            if ($targetId === $selfId) {
                $version = $database->prepare('SELECT session_version FROM gestclub_users WHERE id = ?');
                $version->execute([$selfId]);
                startSession($selfId, (int) $version->fetchColumn());
            }
            return flash('Rôles de ' . $target['email'] . ' enregistrés.');
        case 'disable':
            if ($targetId === $selfId) throw new InvalidArgumentException('Tu ne peux pas désactiver ton propre compte.');
            if ($wasDirector && activeDirectors($database, $targetId) === 0) throw new InvalidArgumentException('Le club doit garder au moins un dirigeant actif.');
            $database->prepare('UPDATE gestclub_users SET active = false, session_version = session_version + 1 WHERE id = ?')->execute([$targetId]);
            $database->prepare('DELETE FROM gestclub_password_tokens WHERE user_id = ?')->execute([$targetId]);
            return flash('Compte ' . $target['email'] . ' désactivé. Ses sessions sont fermées.');
        case 'enable':
            $database->prepare('UPDATE gestclub_users SET active = true WHERE id = ?')->execute([$targetId]);
            return inviteResult($database, $targetId, $target['email'], $shownLink, 'Compte réactivé.');
        case 'resend':
            if (!$target['active']) throw new InvalidArgumentException('Réactive d’abord ce compte.');
            return inviteResult($database, $targetId, $target['email'], $shownLink, 'Nouveau lien créé.');
        case 'reset-2fa':
            $database->prepare('UPDATE gestclub_users SET totp_secret = NULL, totp_last_step = 0, session_version = session_version + 1 WHERE id = ?')
                ->execute([$targetId]);
            if ($targetId === $selfId) {
                $version = $database->prepare('SELECT session_version FROM gestclub_users WHERE id = ?');
                $version->execute([$selfId]);
                startSession($selfId, (int) $version->fetchColumn());
            }
            return flash('Double authentification retirée pour ' . $target['email'] . '.');
    }
    throw new InvalidArgumentException('Action inconnue.');
}

function inviteResult(PDO $database, int $id, string $email, string &$shownLink, string $done): string
{
    if (mailEnabled() || is_string(config()['mail_log'] ?? null)) {
        $sent = sendAccountLink($database, $id, $email, true);
        return flash($done . ($sent ? " Un lien pour choisir le mot de passe a été envoyé à $email." : ' L’e-mail n’a pas pu partir.'), !$sent);
    }
    $shownLink = appUrl('mot-de-passe.php?jeton=' . createPasswordToken($database, $id, 24 * 7));
    return flash($done);
}

function roleChecks(array $selected, string $suffix): string
{
    $html = '<span class="checks">';
    foreach (ROLE_LABELS as $role => $label) {
        $html .= '<label><input type="checkbox" name="roles[]" value="' . $role . '"' . (in_array($role, $selected, true) ? ' checked' : '') .
            '> ' . textEscape($label) . '</label>';
    }
    return $html . '</span>';
}

function accountsSection(PDO $database, int $selfId): string
{
    $rows = $database->query('SELECT id, email, roles, active, totp_secret IS NOT NULL AS two_factor FROM gestclub_users ORDER BY active DESC, email')->fetchAll();
    $html = '<h2>Comptes du bureau</h2><p>Invite une personne : elle reçoit un lien pour choisir son mot de passe. Retirer un compte ferme ses sessions immédiatement.</p>' .
        '<form method="post" class="stack">' . csrfField() . '<input type="hidden" name="do" value="invite">' .
        '<label>Adresse e-mail <input type="email" name="email" maxlength="254" required></label>' . roleChecks([], 'new') .
        '<button type="submit">Inviter</button></form><div class="table-wrap"><table><thead><tr><th>Compte</th><th>Rôles et actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $hidden = csrfField() . '<input type="hidden" name="id" value="' . $id . '">';
        $state = $row['active'] ? ($row['two_factor'] ? 'Actif · double authentification' : 'Actif') : 'Désactivé';
        $html .= '<tr><td><strong>' . textEscape($row['email']) . '</strong>' . ($id === $selfId ? ' <small>(toi)</small>' : '') .
            '<br><small>' . $state . '</small></td><td>' .
            '<form method="post" class="inline">' . $hidden . '<input type="hidden" name="do" value="roles">' . roleChecks(jsonDecode($row['roles']), (string) $id) .
            '<button type="submit" class="secondary">Enregistrer les rôles</button></form><div class="inline">';
        if ($row['active']) {
            $html .= '<form method="post">' . $hidden . '<input type="hidden" name="do" value="resend"><button class="secondary">Envoyer un lien de mot de passe</button></form>';
            if ($row['two_factor']) $html .= '<form method="post">' . $hidden . '<input type="hidden" name="do" value="reset-2fa"><button class="secondary">Retirer la double authentification</button></form>';
            if ($id !== $selfId) $html .= '<form method="post">' . $hidden . '<input type="hidden" name="do" value="disable"><button class="danger">Désactiver</button></form>';
        } else {
            $html .= '<form method="post">' . $hidden . '<input type="hidden" name="do" value="enable"><button class="secondary">Réactiver</button></form>';
        }
        $html .= '</div></td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function calendarSetting(PDO $database, bool $on): string
{
    setSetting($database, 'calendar_token', $on ? bin2hex(random_bytes(16)) : '');
    return flash($on ? 'Nouveau lien d’agenda créé. L’ancien ne fonctionne plus.' : 'Agenda partagé désactivé.');
}

function calendarSection(PDO $database): string
{
    $token = getSetting($database, 'calendar_token', '');
    $html = '<h2>Agenda partagé des matchs et événements</h2>' .
        '<p>Un lien d’abonnement à coller dans Google Agenda, Outlook ou le calendrier du téléphone. Il montre le titre, la date et le lieu des événements, sans aucun nom.</p>';
    if ($token) {
        $html .= '<p class="notice"><code>' . textEscape(appUrl('calendrier.php?cle=' . $token)) . '</code></p>';
    }
    return $html . '<form method="post" class="inline">' . csrfField() .
        '<button name="do" value="calendar-on" class="secondary">' . ($token ? 'Créer un nouveau lien' : 'Activer le lien') . '</button>' .
        ($token ? '<button name="do" value="calendar-off" class="danger">Désactiver</button>' : '') . '</form>';
}
