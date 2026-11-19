<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

$year = null;
$item = null;
$error = '';
$instructions = '';
$available = 0;
$tracking = isset($_GET['dossier']);
try {
    beginWebRequest('gestclub_registration');
    $token = $tracking ? $_GET['dossier'] : ($_GET['saison'] ?? null);
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $csrf = $_SESSION['csrf'];
    $canSubmit = !$post || csrfValid($_POST['csrf'] ?? null);
    session_write_close();
    if ($post && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1048576) {
        http_response_code(413);
        $error = 'L’envoi est trop volumineux. Envoie au maximum trois fichiers de 1 Mo.';
    } elseif (!$canSubmit) {
        http_response_code(403);
        $error = 'Formulaire expiré. Rouvre le lien avant de réessayer.';
    } elseif (validEventToken($token)) {
        $connection = db();
        if ($post) $connection->beginTransaction();
        $state = fetchState($connection, $post);
        $location = registrationLocation($state['bureau'], $token, $tracking);
        if ($location) {
            [$year, $index] = $location;
            $period = &$state['bureau'][$year];
            $instructions = $period['registration']['instructions'] ?? '';
            $item = $tracking ? $period['applications'][$index] : null;
            $available = $tracking
                ? ($item['status'] === 'Incomplet' ? min(REGISTRATION_FILE_LIMIT, 6 - count($item['files'])) : 0)
                : REGISTRATION_FILE_LIMIT;
            if ($post) {
                if ($available < 1) throw new InvalidArgumentException('Ce dossier ne peut plus recevoir de pièces.');
                if (!$tracking) {
                    $item = ['id' => bin2hex(random_bytes(16)),
                        'first' => registrationName($_POST['first'] ?? null),
                        'last' => registrationName($_POST['last'] ?? null),
                        'email' => registrationEmail($_POST['email'] ?? null),
                        'date' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d'),
                        'status' => 'À vérifier', 'note' => '', 'files' => []];
                    foreach ($period['applications'] ?? [] as $previous) {
                        if ($previous['email'] === $item['email'] && sameApplicant($previous, $item)) {
                            throw new InvalidArgumentException('Un dossier existe déjà pour ce nom et cette adresse. Utilise ton lien de suivi ou contacte le club.');
                        }
                    }
                }
                $files = registrationUploads($_FILES['pieces'] ?? [], $available);
                if ($tracking && !$files) throw new InvalidArgumentException('Ajoute au moins une pièce.');
                if ($files) {
                    $insert = attachmentInsert($connection);
                    foreach ($files as $file) $item['files'][] = storeAttachment($insert, $file);
                }
                $item['status'] = 'À vérifier';
                $item['note'] = '';
                if ($tracking) $period['applications'][$index] = $item;
                else $period['applications'][] = $item;
                $update = $connection->prepare(
                    'UPDATE gestclub_state SET bureau = CAST(? AS jsonb), revision = revision + 1,
                     updated_at = now() WHERE id = true'
                );
                $update->execute([jsonEncode($state['bureau'])]);
                $connection->commit();
                header('Location: inscriptions.php?dossier=' . $item['id'], true, 303);
                exit;
            }
        }
        if ($post) $connection->rollBack();
    }
    if ($year === null && !$error) {
        http_response_code(404);
        $error = 'Ce lien d’inscription ou de suivi n’est plus actif.';
    }
} catch (InvalidArgumentException $failure) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    http_response_code(422);
    $error = $failure->getMessage();
} catch (Throwable $failure) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    error_log('Fair Gestion inscriptions: ' . $failure::class);
    http_response_code(503);
    $year = null;
    $error = 'Les inscriptions sont indisponibles. Réessaie dans quelques instants.';
}

function enteredValue(string $name): string
{
    return is_string($_POST[$name] ?? null) ? textEscape($_POST[$name]) : '';
}
?>
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="./assets/fair-icone.svg">
<title>Inscriptions · Fair Gestion</title>
<style>
:root { font: 16px Inter, system-ui, sans-serif; color: #182943; background: #eff3f7 }
body { margin: 0; padding: 24px 12px }
main { max-width: 560px; margin: auto; background: white; border: 1px solid #dbe2e8; border-top: 4px solid #1fb57a; border-radius: 12px; padding: clamp(16px, 5vw, 32px) }
.brand { color: #13294b; font-weight: 750; display: flex; align-items: center; gap: 8px }
h1 { font-size: clamp(1.6rem, 5vw, 2.2rem); margin-bottom: 8px }
h2 { font-size: 1.15rem }
form, label { display: grid; gap: 8px }
form { gap: 16px; margin-top: 24px }
input, button { box-sizing: border-box; font: inherit; border: 1px solid #aebdcb; border-radius: 8px; padding: 10px; min-width: 0; width: 100% }
button { background: #13294b; color: white; cursor: pointer; font-weight: 700 }
.error { color: #8e2428 }
.notice { padding: 12px; background: #edf5fa; border-radius: 8px }
.note { white-space: pre-wrap; overflow-wrap: anywhere }
small { color: #4e5e70 }
</style>
<main>
<p class="brand"><img src="./assets/fair-icone.svg" alt="" width="28" height="28">Fair Gestion</p>
<?php if ($year !== null): ?><p><?= textEscape(config()['club_name']) ?> · Saison <?= $year ?>–<?= $year + 1 ?></p><?php endif; ?>
<h1><?= $tracking ? 'Suivi de l’inscription' : 'Inscription au club' ?></h1>
<?php if ($error): ?><p class="error" role="alert"><?= textEscape($error) ?></p><?php endif; ?>
<?php if ($year !== null && $tracking && $item): ?>
<p>Inscription de <strong><?= textEscape($item['first'] . ' ' . $item['last']) ?></strong></p>
<p class="notice"><?= textEscape($item['status']) ?> · <?= count($item['files']) ?> pièce(s) reçue(s)</p>
<?php if ($item['note'] !== ''): ?><h2>Message du club</h2><p class="note"><?= textEscape($item['note']) ?></p><?php endif; ?>
<p><small>Garde l’adresse de cette page. Ce lien privé permet de retrouver ton dossier.</small></p>
<?php if ($item['status'] === 'Incomplet' && !$available): ?><p>Le dossier a atteint la limite de pièces. Contacte le club.</p><?php endif; ?>
<?php endif; ?>
<?php if ($year !== null && (!$tracking || $available > 0)): ?>
<?php if (!$tracking && $instructions !== ''): ?><p class="notice note"><?= textEscape($instructions) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= textEscape($csrf) ?>">
<?php if (!$tracking): ?>
<label>Prénom de l’adhérent <input name="first" required maxlength="60" value="<?= enteredValue('first') ?>"></label>
<label>Nom de l’adhérent <input name="last" required maxlength="60" value="<?= enteredValue('last') ?>"></label>
<label>E-mail de contact <input name="email" type="email" autocomplete="email" required maxlength="254" value="<?= enteredValue('email') ?>"></label>
<?php endif; ?>
<label><?= $tracking ? 'Ajouter les pièces demandées' : 'Pièces justificatives' ?>
<input type="file" name="pieces[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" <?= $tracking ? 'required' : '' ?>>
<small><?= $available ?> fichier(s) maximum par envoi. PDF, JPEG, PNG ou WebP, 1 Mo par fichier.</small></label>
<button type="submit"><?= $tracking ? 'Envoyer les pièces' : 'Déposer le dossier' ?></button>
</form>
<?php endif; ?>
</main>
</html>
