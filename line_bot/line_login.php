<?php

$client_id = "U5af157334b6a45f0ff60ffb813818987";
$redirect_uri = "https://random-id.ngrok-free.app/callback.php"; // Callback เมื่อ Login เสร็จ
$state = uniqid(); // ใช้ตรวจสอบว่า Request มาจากบอทจริง

// URL สำหรับให้ผู้ใช้ Login ผ่าน LINE
$login_url = "https://access.line.me/oauth2/v2.1/authorize?response_type=code"
    . "&client_id=" . $client_id
    . "&redirect_uri=" . urlencode($redirect_uri)
    . "&state=" . $state
    . "&scope=profile%20openid";

// Redirect ไปยัง LINE Login
header("Location: " . $login_url);
exit();

?>
