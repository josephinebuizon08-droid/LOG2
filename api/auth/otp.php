<?php

require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../../config/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

const OTP_CODE_LENGTH = 6;
const OTP_EXPIRY_MINUTES = 3;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN_SECONDS = 180;

function otp_start_challenge($conn, int $userId, string $email, string $firstName = ''): ?array {
    $code = str_pad((string) random_int(0, (10 ** OTP_CODE_LENGTH) - 1), OTP_CODE_LENGTH, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $preAuthToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

    $insert = pg_query_params(
        $conn,
        "INSERT INTO otp_codes (user_id, pre_auth_token, code_hash, expires_at)
         VALUES ($1, $2, $3, $4)",
        [$userId, $preAuthToken, $codeHash, $expiresAt]
    );

    if ($insert === false) {
        return null;
    }

    if (!otp_send_email($email, $firstName, $code)) {
        return null;
    }

    return [
        'pre_auth_token'      => $preAuthToken,
        'expires_in_seconds'  => OTP_EXPIRY_MINUTES * 90,
    ];
}

/**
 * Step 2 of login: checks a submitted code against the pre_auth_token's
 * row. On success, marks it verified (single-use) and returns the
 * user_id to proceed with issuing a real api_tokens row. On failure,
 * increments the attempt counter and returns a reason string.
 *
 * @return array{ok:bool, user_id?:int, reason?:string}
 */
function otp_verify_code($conn, string $preAuthToken, string $code): array {
    $result = pg_query_params(
        $conn,
        "SELECT otp_id, user_id, code_hash, attempts, verified_at, expires_at
         FROM otp_codes
         WHERE pre_auth_token = $1
         LIMIT 1",
        [$preAuthToken]
    );

    if (!$result || pg_num_rows($result) === 0) {
        return ['ok' => false, 'reason' => 'Invalid or expired login session. Please log in again.'];
    }

    $row = pg_fetch_assoc($result);

    if ($row['verified_at'] !== null) {
        return ['ok' => false, 'reason' => 'This code has already been used. Please log in again.'];
    }

    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'This code has expired. Please request a new one.'];
    }

    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'reason' => 'Too many incorrect attempts. Please request a new code.'];
    }

    if (!password_verify($code, $row['code_hash'])) {
        pg_query_params($conn, "UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = $1", [$row['otp_id']]);
        $remaining = OTP_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
        return ['ok' => false, 'reason' => "Incorrect code. $remaining attempt(s) remaining."];
    }

    pg_query_params($conn, "UPDATE otp_codes SET verified_at = CURRENT_TIMESTAMP WHERE otp_id = $1", [$row['otp_id']]);

    return ['ok' => true, 'user_id' => (int) $row['user_id']];
}

/**
 * Resend: only allowed if the existing pre_auth_token's row is still
 * unverified and unexpired, and at least OTP_RESEND_COOLDOWN_SECONDS has
 * passed since it was created -- prevents a tap-happy user (or a script)
 * from triggering an email flood.
 *
 * @return array{ok:bool, reason?:string}
 */
function otp_resend($conn, string $preAuthToken): array {
    $result = pg_query_params(
        $conn,
        "SELECT o.otp_id, o.user_id, o.verified_at, o.expires_at, o.created_at, u.email, u.first_name
         FROM otp_codes o
         JOIN users u ON u.user_id = o.user_id
         WHERE o.pre_auth_token = $1
         LIMIT 1",
        [$preAuthToken]
    );

    if (!$result || pg_num_rows($result) === 0) {
        return ['ok' => false, 'reason' => 'Invalid or expired login session. Please log in again.'];
    }

    $row = pg_fetch_assoc($result);

    if ($row['verified_at'] !== null) {
        return ['ok' => false, 'reason' => 'This login session is already complete.'];
    }

    $secondsSinceCreated = time() - strtotime($row['created_at']);
    if ($secondsSinceCreated < OTP_RESEND_COOLDOWN_SECONDS) {
        $wait = OTP_RESEND_COOLDOWN_SECONDS - $secondsSinceCreated;
        return ['ok' => false, 'reason' => "Please wait $wait more second(s) before requesting a new code."];
    }

    $code = str_pad((string) random_int(0, (10 ** OTP_CODE_LENGTH) - 1), OTP_CODE_LENGTH, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

    // Reuse the same row/pre_auth_token rather than creating a new one --
    // the app doesn't need to juggle a second pre_auth_token mid-flow.
    $update = pg_query_params(
        $conn,
        "UPDATE otp_codes SET code_hash = $1, expires_at = $2, attempts = 0, created_at = CURRENT_TIMESTAMP WHERE otp_id = $3",
        [$codeHash, $expiresAt, $row['otp_id']]
    );

    if ($update === false || !otp_send_email($row['email'], $row['first_name'], $code)) {
        return ['ok' => false, 'reason' => 'Could not send a new code. Try again shortly.'];
    }

    return ['ok' => true];
}

/**
 * Turns "johndoe@example.com" into "j***@example.com" for display in the
 * login response, so the app can show "code sent to j***@example.com"
 * without needing the full address round-tripped back to it.
 */
function otp_mask_email(string $email): string {
    $atPos = strpos($email, '@');
    if ($atPos === false || $atPos === 0) {
        return $email;
    }
    return substr($email, 0, 1) . str_repeat('*', max(1, $atPos - 1)) . substr($email, $atPos);
}

function otp_send_email(string $toEmail, string $firstName, string $code): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_SMTP_USERNAME;
        $mail->Password = MAIL_SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_SMTP_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your FTMS login code';
        $greetingName = $firstName !== '' ? htmlspecialchars($firstName) : 'there';
        $mail->Body = "
            <p>Hi {$greetingName},</p>
            <p>Your FTMS driver login code is:</p>
            <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">{$code}</p>
            <p>This code expires in " . OTP_EXPIRY_MINUTES . " minutes. If you didn't request this, you can ignore this email.</p>
        ";
        $mail->AltBody = "Your FTMS login code is {$code}. It expires in " . OTP_EXPIRY_MINUTES . " minutes.";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        // Don't leak SMTP internals to the API response -- log server-side
        // only, matching the "don't expose sensitive errors" requirement
        // used everywhere else in this API.
        error_log('OTP email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
