<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$pdo    = db();
$action = $_GET['action'] ?? '';

function norm_name(string $s): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

function log_login(PDO $pdo, string $id, bool $ok): void
{
    $st = $pdo->prepare('INSERT INTO login_logs (student_id, success, ip_address) VALUES (?, ?, ?)');
    $st->execute([$id, $ok ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function bump_failures(PDO $pdo, array $row): void
{
    $fails = (int)$row['failed_attempts'] + 1;
    if ($fails >= cfg('max_login_attempts')) {
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NOW() + INTERVAL ? SECOND WHERE student_id = ?')
            ->execute([cfg('lockout_sec'), $row['student_id']]);
    } else {
        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE student_id = ?')
            ->execute([$fails, $row['student_id']]);
    }
}

function assert_not_locked(array $row): void
{
    if ($row['lock_ts'] !== null && (int)$row['lock_ts'] > time()) {
        fail('Too many failed attempts. Try again in ' . ((int)$row['lock_ts'] - time()) . 's.', 429, 'locked');
    }
}

function fetch_auth_row(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT student_id, full_name, role, password_hash, activation_code_sha256, is_active, failed_attempts,
                UNIX_TIMESTAMP(locked_until) AS lock_ts
         FROM users WHERE student_id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

switch ($action) {

    case 'me': {
        $u = require_login();
        out(['user' => user_payload($u)]);
        break;
    }

    case 'login': {
        require_method('POST');
        $b  = body();
        $id = trim((string)($b['student_id'] ?? ''));
        $pw = (string)($b['password'] ?? '');

        if (!preg_match(ID_REGEX, $id)) {
            fail('Invalid Student ID format. Use 2X-XXXX-XXX (e.g. 23-1234-567).', 422);
        }
        $row = fetch_auth_row($pdo, $id);
        if (!$row) {
            fail('Account not found. Please register your Student ID first.', 404, 'not_registered');
        }
        if (!(int)$row['is_active']) {
            fail('This account has been deactivated. Contact the CPR officers.', 403);
        }
        assert_not_locked($row);
        if ($row['password_hash'] === null) {
            fail('Your account is pre-registered but not activated yet. Please use the Register tab to set your password.', 409, 'needs_activation');
        }
        if (!password_verify($pw, $row['password_hash'])) {
            bump_failures($pdo, $row);
            log_login($pdo, $id, false);
            fail('Incorrect Student ID or password.', 401, 'bad_credentials');
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE student_id = ?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE student_id = ?')
            ->execute([$id]);
        log_login($pdo, $id, true);

        session_regenerate_id(true);
        $_SESSION['sid'] = $id;
        out(['user' => user_payload(load_user($id))]);
        break;
    }

    case 'register': {
        require_method('POST');
        $b      = body();
        $name   = clean_text($b['full_name'] ?? '', 60);
        $id     = trim((string)($b['student_id'] ?? ''));
        $course = clean_text($b['course'] ?? '', 80);
        $pw     = (string)($b['password'] ?? '');
        $code   = strtoupper(trim((string)($b['activation_code'] ?? '')));
        $selectedRole = (string)($b['role'] ?? 'user');
        if (!in_array($selectedRole, ['user', 'member', 'officer', 'admin'], true)) {
            $selectedRole = 'user';
        }

        if (!preg_match(ID_REGEX, $id)) {
            fail('Invalid Student ID format. Use 2X-XXXX-XXX (e.g. 23-1234-567).', 422);
        }
        if (mb_strlen($name) < 2) {
            fail('Please enter your full name (2-60 characters).', 422);
        }
        if (strlen($pw) < 6 || strlen($pw) > 72) {
            fail('Password must be 6 to 72 characters.', 422);
        }

        $row = fetch_auth_row($pdo, $id);

        if ($row) {
            if ($row['password_hash'] !== null) {
                fail('This Student ID is already registered. Please sign in.', 409, 'already_registered');
            }
            assert_not_locked($row);
            if (norm_name($name) !== norm_name($row['full_name'])) {
                bump_failures($pdo, $row);
                fail('The name does not match our records for this Student ID. Use your full name exactly as enrolled.', 403, 'name_mismatch');
            }
            if ($row['activation_code_sha256'] !== null
                && !hash_equals($row['activation_code_sha256'], hash('sha256', $code))) {
                bump_failures($pdo, $row);
                fail('Invalid or missing activation code. Ask your CPR officer.', 403, 'bad_code');
            }
            $pdo->prepare(
                'UPDATE users SET password_hash = ?, activation_code_sha256 = NULL, failed_attempts = 0, locked_until = NULL,
                        course = COALESCE(NULLIF(?, \'\'), course)
                 WHERE student_id = ?'
            )->execute([password_hash($pw, PASSWORD_DEFAULT), $course, $id]);
            out(['activated' => true, 'role' => $row['role'], 'name' => $row['full_name']]);
        }

        if ($course === '') {
            fail('Please enter your year and course.', 422);
        }
        try {
            $pdo->prepare('INSERT INTO users (student_id, full_name, course, role, password_hash) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $name, $course, $selectedRole, password_hash($pw, PASSWORD_DEFAULT)]);
            
            if ($selectedRole === 'member' || $selectedRole === 'officer' || $selectedRole === 'admin') {
                $pdo->prepare('INSERT IGNORE INTO cpr_members (student_id, squad_id, zone_id, status) VALUES (?, 1, 1, "On Duty")')
                    ->execute([$id]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                fail('This Student ID is already registered. Please sign in.', 409, 'already_registered');
            }
            throw $e;
        }
        out(['activated' => true, 'role' => $selectedRole, 'name' => $name], 201);
        break;
    }

    case 'update_profile': {
        require_method('POST');
        $u = require_login();
        $b = body();

        $course  = clean_text($b['course'] ?? '', 80);
        $address = clean_text($b['address'] ?? '', 150);
        $contact = clean_text($b['emergency_contact'] ?? '', 80);
        $birth   = trim((string)($b['birthdate'] ?? ''));

        if ($course === '') {
            fail('Year & course cannot be empty.', 422);
        }
        if ($birth !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $birth);
            if (!$d || $d->format('Y-m-d') !== $birth || $birth < '1930-01-01' || $birth > date('Y-m-d')) {
                fail('Please enter a valid birthdate.', 422);
            }
        }
        $pdo->prepare('UPDATE users SET course = ?, birthdate = ?, address = ?, emergency_contact = ? WHERE student_id = ?')
            ->execute([$course, $birth === '' ? null : $birth, $address === '' ? null : $address, $contact === '' ? null : $contact, $u['student_id']]);

        out(['user' => user_payload(load_user($u['student_id']))]);
        break;
    }

    case 'logout': {
        require_method('POST');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        out(['ok' => true]);
        break;
    }

    case 'send_otp': {
        require_method('POST');
        $b  = body();
        $id = trim((string)($b['student_id'] ?? ''));

        if (!preg_match(ID_REGEX, $id)) {
            fail('Invalid Student ID format. Use 2X-XXXX-XXX (e.g. 23-1234-567).', 422);
        }

        $otp = sprintf("%06d", random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $st = $pdo->prepare('INSERT INTO otp_codes (student_id, otp_code, expires_at) VALUES (?, ?, ?)');
        $st->execute([$id, $otp, $expiresAt]);

        out([
            'message' => 'OTP sent successfully.',
            'demo_otp' => $otp
        ]);
        break;
    }

    case 'verify_otp': {
        require_method('POST');
        $b    = body();
        $id   = trim((string)($b['student_id'] ?? ''));
        $code = trim((string)($b['otp_code'] ?? ''));

        $st = $pdo->prepare(
            'SELECT id FROM otp_codes 
             WHERE student_id = ? AND otp_code = ? AND expires_at > NOW() AND is_verified = 0 
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$id, $code]);
        $row = $st->fetch();

        if (!$row) {
            fail('Invalid or expired OTP code.', 400);
        }

        $pdo->prepare('UPDATE otp_codes SET is_verified = 1 WHERE id = ?')->execute([$row['id']]);

        out(['verified' => true, 'message' => 'OTP verified successfully.']);
        break;
    }

    default:
        fail('Unknown action.', 404);
}