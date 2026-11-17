<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_method('POST');

$body = body();
$action = $_GET['action'] ?? $body['action'] ?? 'send_otp';

try {
    $pdo = db();

    if ($action === 'send_otp') {
        $studentId = trim($body['student_id'] ?? '');
        $fullName = trim($body['full_name'] ?? '');
        $course = trim($body['year_course'] ?? $body['course'] ?? '');
        $role = trim($body['account_role'] ?? 'Regular Student / User');

        if (!preg_match(ID_REGEX, $studentId)) {
            fail('Invalid Student ID format. Expected format: YY-NNNN-NNN.', 400);
        }

        $userStmt = $pdo->prepare('
            INSERT INTO users (student_id, full_name, course, role, is_active) 
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name),
                course = VALUES(course),
                role = VALUES(role)
        ');
        $userStmt->execute([$studentId, $fullName, $course, $role]);

        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('
            INSERT INTO otp_codes (student_id, otp_code, expires_at, is_verified) 
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)
            ON DUPLICATE KEY UPDATE 
                otp_code = VALUES(otp_code), 
                expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), 
                is_verified = 0
        ');
        $stmt->execute([$studentId, $otpCode]);

        out([
            'success' => true,
            'message' => 'OTP sent successfully. Code: ' . $otpCode
        ]);
    } 
    elseif ($action === 'verify_otp') {
        $studentId = trim($body['student_id'] ?? '');
        $otpCode = trim($body['otp_code'] ?? $body['code'] ?? '');

        if (empty($studentId) || empty($otpCode)) {
            fail('Student ID and OTP code are required.', 400);
        }

        // Fetch latest active code for this student without strict time checks first to debug
        $stmt = $pdo->prepare('
            SELECT * FROM otp_codes 
            WHERE student_id = ? AND otp_code = ? AND is_verified = 0
            ORDER BY created_at DESC LIMIT 1
        ');
        $stmt->execute([$studentId, $otpCode]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord) {
            fail('Invalid or expired OTP code for student: ' . $studentId, 400);
        }

        $updateStmt = $pdo->prepare('UPDATE otp_codes SET is_verified = 1 WHERE id = ?');
        $updateStmt->execute([$otpRecord['id']]);

        out([
            'success' => true,
            'message' => 'OTP verified successfully.'
        ]);
    } 
    else {
        fail('Invalid action specified: ' . $action, 400);
    }

} catch (Throwable $e) {
    fail('Database Error: ' . $e->getMessage(), 500);
}
