<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_method('POST');

$body = body();
$action = $body['action'] ?? 'send_otp';

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

        // Auto-register or update the user in the users table so they don't get blocked
        $userStmt = $pdo->prepare('
            INSERT INTO users (student_id, full_name, course, role, is_active) 
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name),
                course = VALUES(course),
                role = VALUES(role)
        ');
        $userStmt->execute([$studentId, $fullName, $course, $role]);

        // Generate 6-digit OTP and set 5 minutes expiration
        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // Insert or update OTP record
        $stmt = $pdo->prepare('
            INSERT INTO otp_codes (student_id, otp_code, expires_at, is_verified) 
            VALUES (?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE 
                otp_code = VALUES(otp_code), 
                expires_at = VALUES(expires_at), 
                is_verified = 0
        ');
        $stmt->execute([$studentId, $otpCode, $expiresAt]);

        out([
            'success' => true,
            'message' => 'OTP sent successfully. Code: ' . $otpCode
        ]);
    } 
    elseif ($action === 'verify_otp') {
        $studentId = trim($body['student_id'] ?? '');
        $otpCode = trim($body['otp_code'] ?? '');

        if (!preg_match(ID_REGEX, $studentId) || empty($otpCode)) {
            fail('Invalid student ID or OTP code.', 400);
        }

        $stmt = $pdo->prepare('
            SELECT * FROM otp_codes 
            WHERE student_id = ? AND otp_code = ? AND is_verified = 0 AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ');
        $stmt->execute([$studentId, $otpCode]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord) {
            fail('Invalid or expired OTP code.', 400);
        }

        $updateStmt = $pdo->prepare('UPDATE otp_codes SET is_verified = 1 WHERE id = ?');
        $updateStmt->execute([$otpRecord['id']]);

        out([
            'success' => true,
            'message' => 'OTP verified successfully.'
        ]);
    } 
    else {
        fail('Invalid action specified.', 400);
    }

} catch (Throwable $e) {
    fail('Database Error: ' . $e->getMessage(), 500);
}
