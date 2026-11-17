<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_method('POST');

$body = body();
$action = $_GET['action'] ?? $body['action'] ?? 'register';

try {
    $pdo = db();

    if ($action === 'register' || $action === 'send_otp') {
        $studentId = trim($body['student_id'] ?? '');
        $fullName = trim($body['full_name'] ?? '');
        $course = trim($body['year_course'] ?? $body['course'] ?? '');
        $role = trim($body['account_role'] ?? 'Regular Student / User');
        $password = $body['password'] ?? '';

        if (!preg_match(ID_REGEX, $studentId)) {
            fail('Invalid Student ID format. Expected format: YY-NNNN-NNN.', 400);
        }

        // Hash the password securely if provided, or store directly if your app uses plaintext/existing hashing
        $passwordHash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

        // Insert or update the user in the database
        $userStmt = $pdo->prepare('
            INSERT INTO users (student_id, full_name, course, role, is_active) 
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name),
                course = VALUES(course),
                role = VALUES(role)
        ');
        $userStmt->execute([$studentId, $fullName, $course, $role]);

        out([
            'success' => true,
            'activated' => true,
            'role' => $role,
            'name' => $fullName,
            'message' => 'Account successfully registered and activated!'
        ]);
    } 
    elseif ($action === 'login') {
        $studentId = trim($body['student_id'] ?? '');
        $password = $body['password'] ?? '';

        if (!preg_match(ID_REGEX, $studentId)) {
            fail('Invalid Student ID format.', 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE student_id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $user = $stmt->fetch();

        if (!$user) {
            fail('Student ID not found. Please register an account first.', 400, ['code' => 'not_registered']);
        }

        // Start session or return success
        out([
            'success' => true,
            'message' => 'Logged in successfully.',
            'user' => [
                'studentId' => $user['student_id'],
                'name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    } 
    elseif ($action === 'verify_otp') {
        // Kept for backward compatibility if frontend still calls it, instantly succeeds
        out([
            'success' => true,
            'message' => 'Bypassed successfully.'
        ]);
    }
    else {
        fail('Invalid action specified.', 400);
    }

} catch (Throwable $e) {
    fail('Database Error: ' . $e->getMessage(), 500);
}
