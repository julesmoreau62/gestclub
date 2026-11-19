<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

$item = null;
$error = '';
$confirmed = false;
try {
    beginWebRequest('gestclub_volunteers');
    $token = $_GET['evenement'] ?? null;
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $csrf = $_SESSION['csrf'];
    $canClaim = !$isPost || csrfValid($_POST['csrf'] ?? null);
    if (!$isPost && validEventToken($token) && ($_SESSION['volunteer_confirmation'] ?? null) === $token) {
        $confirmed = true;
        unset($_SESSION['volunteer_confirmation']);
    }
    session_write_close();
    if (!$canClaim) {
        http_response_code(403);
        $error = 'Formulaire expiré. Rouvre le lien pour réessayer.';
    } elseif ($isPost && rateLimitExceeded('volunteers', 20, 60)) {
        http_response_code(429);
        $error = 'Trop d’inscriptions depuis cette connexion. Réessaie dans une heure ou contacte le club.';
    } elseif (validEventToken($token)) {
        $connection = db();
        if ($isPost) $connection->beginTransaction();
        $state = fetchState($connection, $isPost);
        $location = findSharedEvent($state['bureau'], $token);
        if ($location) {
            [$year, $index] = $location;
            $item = $state['bureau'][$year]['events'][$index];
        }
        if ($isPost && $item) {
            $selected = array_search($_POST['slot'] ?? null, array_column($item['slots'], 'id'), true);
            if ($selected === false || $item['slots'][$selected]['owner'] !== '') {
                http_response_code(409);
                $error = 'Ce créneau n’est plus disponible. Tu peux en choisir un autre.';
            } else {
                $item['slots'][$selected]['owner'] = eventText($_POST['name'] ?? null);
                $state['bureau'][$year]['events'][$index] = $item;
                savePublicBureau($connection, $state['bureau']);
                $confirmed = true;
            }
        }
        if ($isPost) $connection->commit();
        if ($isPost && $confirmed) {
            session_start();
            $_SESSION['volunteer_confirmation'] = $token;
            session_write_close();
            header('Location: benevoles.php?evenement=' . $token, true, 303);
            exit;
        }
    }
    if (!$item && !$error) {
        http_response_code(404);
        $error = 'Ce lien d’inscription n’est plus actif.';
    }
} catch (InvalidArgumentException $failure) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    http_response_code(422);
    $error = $failure->getMessage();
} catch (Throwable $failure) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    error_log('Fair Gestion bénévoles: ' . $failure::class);
    http_response_code(503);
    $item = null;
    $error = 'Le planning est indisponible. Réessaie dans quelques instants.';
}

$slots = $item ? $item['slots'] : [];
usort($slots, fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
?>
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="./assets/fair-icone.svg">
<title>Créneaux bénévoles · Fair Gestion</title>
<style>
:root { font: 16px Inter, system-ui, sans-serif; color: #182943; background: #eff3f7 }
body { margin: 0; padding: 24px 12px }
main { max-width: 620px; margin: auto; background: white; border: 1px solid #dbe2e8; border-top: 4px solid #1fb57a; border-radius: 12px; padding: clamp(16px, 5vw, 32px) }
.brand { color: #13294b; font-weight: 750; display: flex; align-items: center; gap: 8px }
h1 { font-size: clamp(1.6rem, 5vw, 2.2rem); margin-bottom: 8px }
h2 { font-size: 1.15rem; margin-top: 28px }
ul { margin: 0; padding: 0; list-style: none }
li { padding-block: 14px; border-bottom: 1px solid #dbe2e8 }
small { display: block; color: #4e5e70 }
form { display: flex; flex-wrap: wrap; align-items: end; gap: 8px; margin-top: 10px }
label { display: grid; gap: 4px; flex: 1 1 175px }
input, button { box-sizing: border-box; font: inherit; border: 1px solid #aebdcb; border-radius: 8px; padding: 9px }
input { min-width: 0; width: 100% }
button { background: #13294b; color: white; cursor: pointer; font-weight: 700 }
.error { color: #8e2428 }
.success { padding: 12px; color: #07533b; background: #e1f4eb; border-radius: 8px }
.privacy { margin-top: 24px; font-size: .875rem; color: #4e5e70 }
</style>
<main>
<p class="brand"><img src="./assets/fair-icone.svg" alt="" width="28" height="28">Fair Gestion</p>
<?php if ($item): ?>
<p><?= textEscape(config()['club_name']) ?></p>
<h1><?= textEscape($item['title']) ?></h1>
<p><?= textEscape((new DateTimeImmutable($item['date']))->format('d/m/Y')) ?> · <?= textEscape($item['place']) ?></p>
<?php else: ?>
<h1>Créneaux bénévoles</h1>
<?php endif; ?>
<?php if ($error): ?><p class="error" role="alert"><?= textEscape($error) ?></p><?php endif; ?>
<?php if ($item && $confirmed): ?><p class="success" role="status">Ton inscription est confirmée.</p><?php endif; ?>
<?php if ($item): ?>
<h2>Créneaux bénévoles</h2>
<?php if (!$slots): ?><p>Aucun créneau proposé pour le moment.</p><?php endif; ?>
<ul>
<?php foreach ($slots as $slot): ?>
<li>
<strong><?= textEscape($slot['title']) ?></strong>
<small><?= textEscape($slot['start']) ?>–<?= textEscape($slot['end']) ?><?= $slot['owner'] !== '' ? ' · Complet' : '' ?></small>
<?php if ($slot['owner'] === ''): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= textEscape($csrf) ?>">
<input type="hidden" name="slot" value="<?= textEscape($slot['id']) ?>">
<label>Prénom et nom <input name="name" autocomplete="name" required maxlength="120"></label>
<button type="submit">Prendre ce créneau</button>
</form>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<p class="privacy">Ton nom sert uniquement à organiser l’événement. Il n’apparaît pas sur cette page :
seuls les dirigeants du club le voient.<?php if (privacyContact() !== ''): ?>
Pour le faire retirer, écris à <?= textEscape(privacyContact()) ?>.<?php endif; ?></p>
<?php endif; ?>
</main>
</html>
