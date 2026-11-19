<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/state.php';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo jsonEncode($data);
    exit;
}

try {
    beginWebRequest();
    $user = currentUser();
    if (!$user) respond(['error' => 'Session expirée. Reconnecte-toi.'], 401);
    if (requiresTwoFactor($user['roles']) && !$user['two_factor']) {
        respond(['error' => 'Active la double authentification depuis « Mon compte ».'], 403);
    }
    $role = $_GET['role'] ?? $user['roles'][0];
    if (!is_string($role) || !in_array($role, $user['roles'], true)) {
        respond(['error' => 'Cette vue est réservée aux comptes autorisés.'], 403);
    }
    $method = $_SERVER['REQUEST_METHOD'];
    $allowed = $method === 'POST' && csrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    session_write_close();
    $connection = db();
    if ($method === 'GET') {
        $view = scopedState(fetchState($connection), $role, (string) $user['id']);
        if (!isset($_GET['attachment'])) respond($view);
        $id = $_GET['attachment'];
        if (!is_string($id) || !preg_match('/^[0-9a-f]{32}$/D', $id) || !canReadAttachment($view, $id)) {
            respond(['error' => 'Justificatif introuvable.'], 404);
        }
        $file = $connection->prepare("SELECT name, mime, encode(content, 'base64') AS content FROM gestclub_attachments WHERE id = ?");
        $file->execute([$id]);
        $content = $file->fetch();
        if (!$content) respond(['error' => 'Justificatif introuvable.'], 404);
        header('Content-Type: ' . $content['mime']);
        header("Content-Disposition: attachment; filename=justificatif; filename*=UTF-8''" . rawurlencode($content['name']));
        echo base64_decode($content['content'], true);
        exit;
    }
    if (!$allowed) respond(['error' => 'Requête non autorisée.'], 403);
    $raw = file_get_contents('php://input', false, null, 0, GESTCLUB_BODY_LIMIT + 1);
    if (strlen($raw) > GESTCLUB_BODY_LIMIT) respond(['error' => 'Les données dépassent la limite de stockage de cette version.'], 413);
    $payload = jsonDecode($raw);
    if (!is_array($payload) || !is_int($payload['revision'] ?? null)) {
        respond(['error' => 'Requête invalide.'], 400);
    }
    $connection->beginTransaction();
    $state = fetchState($connection, true);
    // A page loaded before a public sign-up or registration can still save: it has seen every bureau change,
    // and the server keeps what came from the public links (see mergeState).
    $behindPublicOnly = $payload['revision'] < $state['revision'] && $payload['revision'] >= $state['staffRevision'];
    if ($state['revision'] !== $payload['revision'] && !$behindPublicOnly) {
        $connection->rollBack();
        respond(['error' => 'Le club a été modifié sur un autre appareil. Les données ont été rechargées. Reprends ta modification.',
            'state' => scopedState($state, $role, (string) $user['id'])], 409);
    }
    if (isset($payload['registrationCommand']) && $role !== 'secretary') {
        $connection->rollBack();
        respond(['error' => 'Cette action est réservée au secrétaire.'], 403);
    }
    $next = persistAttachments($connection, $state, mergeState($state, $payload, $role, (string) $user['id'], $behindPublicOnly));
    if (isset($payload['registrationCommand'])) {
        $next = applyRegistrationCommand($next, $payload['registrationCommand']);
    }
    $update = $connection->prepare(
        'UPDATE gestclub_state SET records = CAST(? AS jsonb), bureau = CAST(? AS jsonb),
         revision = revision + 1, staff_revision = revision + 1, updated_at = now() WHERE id = true RETURNING revision'
    );
    $update->execute([jsonEncode($next['records']), jsonEncode($next['bureau'])]);
    $next['revision'] = (int) $update->fetchColumn();
    recordAudit($connection, $user['email'], $role, changeSummary($state, $next, $payload['registrationCommand'] ?? null));
    $connection->commit();
    $command = $payload['registrationCommand'] ?? null;
    if (is_array($command) && ($command['action'] ?? null) === 'review') {
        foreach ($next['bureau'][$command['year']]['applications'] as $application) {
            if ($application['id'] === $command['id']) sendRegistrationMail($application, (int) $command['year']);
        }
    }
    respond(scopedState($next, $role, (string) $user['id']));
} catch (InvalidArgumentException | JsonException $error) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    respond(['error' => $error instanceof JsonException ? 'Données JSON invalides.' : $error->getMessage()], 422);
} catch (Throwable $error) {
    if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
    error_log('Fair Gestion API: ' . $error::class);
    respond(['error' => 'Enregistrement indisponible. Réessaie dans quelques instants.'], 503);
}
