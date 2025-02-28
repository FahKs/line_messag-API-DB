<?php

$access_token = 'RU+drLKbPqg/5QRJIZ9uGnL0uOx0FS7lJJDXADcimqhbidZsl6eKIb5xYYMaKqNRQMgXMIL6SKH5BBEQKmxKgg8bxUGBdSWsLVE8QlutkKZB5/vEfUIE7IWjg54IWDVwJP8wwRU/kRG9Oe5Kc2/+BQdB04t89/1O/w1cDnyilFU='; // ใส่ Channel Access Token
$login_url = 'https://29ad-125-26-7-18.ngrok-free.app/NT/page/login.php'; // URL ของ Web App สำหรับ Login

$json = file_get_contents('php://input');
$events = json_decode($json, true);

if (!empty($events['events'])) {
    foreach ($events['events'] as $event) {
        if ($event['type'] == 'message') {
            $replyToken = $event['replyToken'];
            $userMessage = strtolower(trim($event['message']['text'])); // ลบช่องว่าง & แปลงเป็นตัวเล็ก

            if (strcasecmp($userMessage, 'login') == 0) {
                sendReply($replyToken, "🔑 กรุณาเข้าสู่ระบบโดยกดที่ลิงก์นี้: \n" . $login_url, $access_token);
            } else {
                sendReply($replyToken, "กรุณาพิมพ์ 'Login' เพื่อเข้าสู่ระบบ 🔑", $access_token);
            }
        }
    }
}

function sendReply($replyToken, $message, $access_token) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $replyToken,
        'messages' => [['type' => 'text', 'text' => $message]],
    ];
    
    $post = json_encode($data);
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
}

echo "OK";
?>
