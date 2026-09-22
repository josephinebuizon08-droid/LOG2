<?php

    session_start();

    require_once __DIR__ . '/../config/ftms_db.php';
    require_once __DIR__ . '/../api/auth/otp.php';

    if (empty($_SESSION['pending_pre_auth_token'])) {
        header("Location: login.php");
        exit();
    }

    const OTP_RESEND_COOLDOWN_SECONDS = 180;

        $sentAt = $_SESSION['otp_sent_at'] ?? 0;
        $secondsSinceSend = time() - $sentAt;

        if ($secondsSinceSend < OTP_RESEND_COOLDOWN_SECONDS) {
            $_SESSION['otp_error'] = "Please wait before requesting another code.";
            header("Location: verify_otp_web1.php");
            exit();
        }

        $result = otp_resend($conn, $_SESSION['pending_pre_auth_token']);

        if (!$result['ok']) {
            $_SESSION['otp_error'] = $result['reason'];
        } else {
            $_SESSION['otp_error'] = "A new code was sent to your email.";
            $_SESSION['otp_sent_at'] = time();
        }

    header("Location: verify_otp_web1.php");
    exit();