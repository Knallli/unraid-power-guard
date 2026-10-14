<?php
declare(strict_types=1);

/*
 * JSON API. POST only; the CSRF token is verified by Unraid's local_prepend.php
 * before this file runs (POST field csrf_token or X-CSRF-Token header).
 */

namespace PowerGuard;

require dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'POST required']);
}

$action = (string)($_POST['action'] ?? '');
$actions = ['status', 'plan', 'detect', 'conflicts', 'telemetry', 'config', 'preview', 'apply', 'revert', 'journal'];
if (!in_array($action, $actions, true)) {
    respond(400, ['error' => 'unknown action']);
}

try {
    switch ($action) {
        case 'status':
            respond(200, Service::status());
        case 'plan':
            $p = $_POST['profile'] ?? null;
            if ($p !== null && !in_array($p, Registry::PROFILES, true)) {
                respond(400, ['error' => 'unknown profile']);
            }
            respond(200, Service::plan($p));
        case 'detect':
            respond(200, Hardware::detect());
        case 'conflicts':
            respond(200, Conflicts::scan());
        case 'telemetry':
            respond(200, Telemetry::sample());
        case 'config':
            $patch = json_decode((string)($_POST['patch'] ?? '{}'), true);
            if (!is_array($patch)) {
                respond(400, ['error' => 'invalid patch']);
            }
            // "active" is entered by applying; it would have nothing to manage otherwise.
            if (($patch['mode'] ?? null) === 'active' && State::config()['managed'] === []) {
                respond(409, ['error' => 'Erst Empfehlungen anwenden, dann ist der aktive Modus verfügbar.']);
            }
            respond(200, ['config' => State::saveConfig($patch)]);
        case 'preview':
            respond(200, ['changes' => Service::preview(Service::ids($_POST['ids'] ?? null))]);
        case 'apply':
            $ids = Service::ids($_POST['ids'] ?? null);
            if ($ids === null || $ids === []) {
                respond(400, ['error' => 'Keine Tweaks ausgewählt']);
            }
            respond(200, Engine::apply($ids));
        case 'revert':
            respond(200, Engine::revert(Service::ids($_POST['ids'] ?? null)));
        case 'journal':
            respond(200, ['journal' => State::journalRead(200), 'baseline' => State::baseline()]);
    }
} catch (\InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}
