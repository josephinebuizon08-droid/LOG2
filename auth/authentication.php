<?php

session_start();

require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/../api/auth/otp.php';

$username = trim($_POST['username']);
$password = $_POST['password'];

$sql = "SELECT * FROM users WHERE username = $1";
$result = pg_query_params($conn, $sql, array($username));

if (pg_num_rows($result) == 1) {
    $user = pg_fetch_assoc($result);

    if (password_verify($password, $user['password'])) {

        $otpChallenge = otp_start_challenge(
            $conn,
            (int) $user['user_id'],
            $user['email'],
            $user['first_name']
        );

        if ($otpChallenge === null) {
            $_SESSION['login_error'] = "Unable to send OTP. Please try again.";
            header("Location: login.php");
            exit();
        }

        $_SESSION['pending_pre_auth_token'] = $otpChallenge['pre_auth_token'];
        $_SESSION['pending_user_id'] = $user['user_id'];
        $_SESSION['otp_sent_at'] = time();

        header("Location: verify_otp_web1.php");
        exit();

    } else {
        $_SESSION['login_error'] = "Incorrect Password.";
        header("Location: login.php");
        exit();
    }

} else {
    $_SESSION['login_error'] = "User not found.";
    header("Location: login.php");
    exit();
}