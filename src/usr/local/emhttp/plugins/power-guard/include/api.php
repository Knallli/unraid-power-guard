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
$actions = ['status', 'plan', 'detect', 'conflicts', 'telemetry', 'config'];
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
            // Activating needs the apply engine (phase 2); refuse until it exists.
            if (($patch['mode'] ?? null) === 'active') {
                respond(409, ['error' => 'Aktiver Modus ist in dieser Version noch nicht verfügbar.']);
            }
            respond(200, ['config' => State::saveConfig($patch)]);
    }
} catch (\InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}
