<?php
declare(strict_types=1);

const ID_REGEX = '/^2\d-\d{4}-\d{3}$/';

$CONFIG = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

set_exception_handler(function (Throwable $e): void {
    error_log('[lifeline] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['error' => 'Server error. Please try again.']);
});

session_name('lifeline_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// CSRF defence: every state-changing request must carry a custom header.
// Browsers will not send it cross-site without a CORS preflight, which we never allow.
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'lifeline') {
    fail('Forbidden.', 403);
}

function cfg(string $key)
{
    global $CONFIG;
    return $CONFIG[$key];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', cfg('db_host'), cfg('db_name'), cfg('db_charset'));
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . cfg('timezone_offset') . "'");
    }
    return $pdo;
}

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400, ?string $errCode = null): never
{
    $payload = ['error' => $message];
    if ($errCode !== null) {
        $payload['code'] = $errCode;
    }
    out($payload, $code);
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        fail('Method not allowed.', 405);
    }
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Invalid request body.', 400);
    }
    return $data;
}

function load_user(string $studentId): ?array
{
    $st = db()->prepare(
        'SELECT u.student_id, u.full_name, u.course, u.role, u.birthdate, u.address, u.emergency_contact, u.is_active,
                s.name AS squad_name, s.role_label AS squad_label, z.name AS zone_name, m.duty_status
         FROM users u
         LEFT JOIN cpr_members m ON m.student_id = u.student_id
         LEFT JOIN squads s ON s.id = m.squad_id
         LEFT JOIN zones  z ON z.id = m.zone_id
         WHERE u.student_id = ?'
    );
    $st->execute([$studentId]);
    $row = $st->fetch();
    return $row ?: null;
}

// The session only stores the student ID; role/name are always re-read from the database,
// so demoting or deactivating someone takes effect immediately.
function current_user(): ?array
{
    $id = $_SESSION['sid'] ?? null;
    if (!is_string($id)) {
        return null;
    }
    $u = load_user($id);
    if (!$u || !(int)$u['is_active']) {
        $_SESSION = [];
        return null;
    }
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        fail('Please sign in.', 401, 'unauthenticated');
    }
    return $u;
}

function is_responder(array $u): bool
{
    return in_array($u['role'], ['member', 'officer', 'admin'], true);
}

function is_officer(array $u): bool
{
    return in_array($u['role'], ['officer', 'admin'], true);
}

function require_responder(array $u): void
{
    if (!is_responder($u)) {
        fail('Only CPR members and officers can do this.', 403);
    }
}

function require_officer(array $u): void
{
    if (!is_officer($u)) {
        fail('Only CPR officers can do this.', 403);
    }
}

function user_payload(array $u): array
{
    return [
        'studentId'        => $u['student_id'],
        'name'             => $u['full_name'],
        'course'           => $u['course'],
        'role'             => $u['role'],
        'birthdate'        => $u['birthdate'],
        'address'          => $u['address'],
        'emergencyContact' => $u['emergency_contact'],
        'squad'            => $u['squad_name'] ? $u['squad_name'] . ' (' . $u['squad_label'] . ')' : null,
        'zone'             => $u['zone_name'],
        'dutyStatus'       => $u['duty_status'],
    ];
}

function ts_ms($seconds): ?int
{
    return $seconds === null ? null : (int)$seconds * 1000;
}

function clean_text($value, int $max): string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string)$value));
    return mb_substr($v, 0, $max);
}
