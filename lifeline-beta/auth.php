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

        if (!preg_match(ID_REGEX, $studentId)) {
            fail('Invalid Student ID format. Expected format: YY-NNNN-NNN.', 400);
        }

        // Verify if student exists in the users table
        $checkUser = $pdo->prepare('SELECT student_id FROM users WHERE student_id = ?');
        $checkUser->execute([$studentId]);
        if (!$checkUser->fetch()) {
            fail('Student ID not found in the official registry.', 404);
        }

        // Generate 6-digit OTP code and 5-minute expiration
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
            'message' => 'OTP sent successfully. Code: ' . $otpCode // Included for testing/debugging on deployment
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
    // Return the exact error string during troubleshooting
    fail('Database Error: ' . $e->getMessage(), 500);
}
