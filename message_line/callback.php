<?php
// ตั้งค่าค่าคงที่สำหรับการเชื่อมต่อกับ LINE API
$client_id = "U5af157334b6a45f0ff60ffb813818987";  // Channel ID ของคุณ
$client_secret = "07dd9687a42cc10947366481d99a86d2"; // Channel Secret ของคุณ
$redirect_uri = "https://246c-125-26-7-18.ngrok-free.app/NT/message_line/callback.php"; // Callback URL (ต้องตรงกับที่ตั้งค่าใน LINE Developers Console)
$line_bot_token = "GC+HTqBDARcs0B/kmqeewiQ9PVm0hG7P2dQaftfXiUZvEN69jW2Q4CxXmCK0RhNfQMgXMIL6SKH5BBEQKmxKgg8bxUGBdSWsLVE8QlutkKaS3XiDnJdbU+Mk+J+QZ1WV/zXnzOBCLqnhZ+6gCuHJSwdB04t89/1O/w1cDnyilFU="; // Access Token ของ LINE Bot

// เพิ่ม Error log เพื่อตรวจสอบการทำงาน
error_log("Callback script started");

// ตั้งค่าการเชื่อมต่อฐานข้อมูล
$db_host = 'localhost'; 
$db_user = 'root';     
$db_pass = '';         
$db_name = 'ntdb';     

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// ตรวจสอบว่าได้รับ code จาก LINE Login หรือไม่
if (!isset($_GET['code'])) {
    error_log("No code received from LINE Login");
    die("No code received.");
}

$code = $_GET['code'];
error_log("Received code: " . $code);

// ฟังก์ชันเพื่อขอ Access Token จาก LINE
function getAccessToken($code, $client_id, $client_secret, $redirect_uri) {
    $token_url = "https://api.line.me/oauth2/v2.1/token";
    
    // ข้อมูลที่ใช้ในการแลก Authorization Code เป็น Access Token
    $data = [
        "grant_type" => "authorization_code",
        "code" => $code,
        "redirect_uri" => $redirect_uri,
        "client_id" => $client_id,
        "client_secret" => $client_secret
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Access Token Response Code: " . $httpCode . " Response: " . $response);
    
    return json_decode($response, true);
}

// ฟังก์ชันเพื่อดึงข้อมูลโปรไฟล์ของผู้ใช้
function getProfile($access_token) {
    $profile_url = "https://api.line.me/v2/profile";
    
    $ch = curl_init($profile_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $access_token]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Profile Response Code: " . $httpCode . " Response: " . $response);
    
    return json_decode($response, true);
}

// ฟังก์ชันสร้าง Flex Message สำหรับเมนูหลัก
function createWelcomeMenu($name) {
    return [
        "type" => "flex",
        "altText" => "ยินดีต้อนรับคุณ $name",
        "contents" => [
            "type" => "bubble",
            "hero" => [
                "type" => "image",
                "url" => "https://via.placeholder.com/1000x400", // เปลี่ยนเป็น URL รูปภาพของคุณ
                "size" => "full",
                "aspectRatio" => "20:8",
                "aspectMode" => "cover"
            ],
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => "ยินดีต้อนรับ",
                        "weight" => "bold",
                        "size" => "xl",
                        "align" => "center"
                    ],
                    [
                        "type" => "text",
                        "text" => "คุณ $name",
                        "weight" => "bold",
                        "size" => "xl",
                        "align" => "center",
                        "margin" => "md"
                    ],
                    [
                        "type" => "separator",
                        "margin" => "xxl"
                    ],
                    [
                        "type" => "text",
                        "text" => "กรุณาเลือกเมนูด้านล่าง",
                        "size" => "sm",
                        "color" => "#aaaaaa",
                        "margin" => "md",
                        "align" => "center"
                    ]
                ]
            ],
            "footer" => [
                "type" => "box",
                "layout" => "vertical",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "button",
                        "style" => "primary",
                        "action" => [
                            "type" => "message",
                            "label" => "รายละเอียดบัญชี",
                            "text" => "รายละเอียดบัญชี"
                        ],
                        "color" => "#1DB446"
                    ],
                    [
                        "type" => "button",
                        "style" => "primary",
                        "action" => [
                            "type" => "message",
                            "label" => "ติดต่อเจ้าหน้าที่",
                            "text" => "ติดต่อเจ้าหน้าที่"
                        ],
                        "color" => "#4169E1"
                    ],
                    [
                        "type" => "button",
                        "action" => [
                            "type" => "message",
                            "label" => "ช่วยเหลือ",
                            "text" => "ช่วยเหลือ"
                        ]
                    ]
                ]
            ]
        ]
    ];
}

// ฟังก์ชันส่งข้อความไปยัง LINE Bot
function sendMessage($userId, $message, $token) {
    $url = "https://api.line.me/v2/bot/message/push";
    $data = ["to" => $userId, "messages" => [["type" => "text", "text" => $message]]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Message Response Code: " . $httpCode . " Response: " . $response);
    
    return $response;
}

// ฟังก์ชันส่ง Flex Message
function sendFlexMessage($userId, $flexMessage, $token) {
    $url = "https://api.line.me/v2/bot/message/push";
    $data = ["to" => $userId, "messages" => [$flexMessage]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Flex Message Response Code: " . $httpCode . " Response: " . $response);
    
    return $response;
}

// ฟังก์ชันตรวจสอบอีเมลในฐานข้อมูล
function checkUserByEmail($email, $conn) {
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0 ? $result->fetch_assoc() : false;
}

// เรียกฟังก์ชันเพื่อแลก `authorization code` เป็น `access token`
$tokenData = getAccessToken($code, $client_id, $client_secret, $redirect_uri);

// ตรวจสอบว่าได้ Access Token หรือไม่
if (isset($tokenData['access_token'])) {
    $access_token = $tokenData['access_token'];
    error_log("Received access token: " . $access_token);

    // ดึงข้อมูลโปรไฟล์ผู้ใช้
    $profile = getProfile($access_token);
    
    if (isset($profile['userId'])) {
        $userId = $profile['userId'];
        $userName = $profile['displayName'];
        error_log("User profile: " . json_encode($profile));

        // ส่งข้อความทักทายและขอให้ส่งอีเมล
        sendMessage($userId, "สวัสดีคุณ " . $userName . " กรุณายืนยันตัวตนโดยพิมพ์อีเมลที่ลงทะเบียนไว้", $line_bot_token);
        
        // แสดงหน้าเว็บยืนยันการเชื่อมต่อสำเร็จ
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1'>
            <title>เชื่อมต่อกับ LINE สำเร็จ</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 40px 20px;
                }
                .success-box {
                    max-width: 500px;
                    margin: 0 auto;
                    background-color: #f5f8fa;
                    padding: 30px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #06C755;
                }
                p {
                    color: #333;
                    line-height: 1.6;
                    margin-bottom: 20px;
                }
                .close-btn {
                    background-color: #06C755;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 5px;
                    font-size: 16px;
                    cursor: pointer;
                    margin-top: 20px;
                }
            </style>
        </head>
        <body>
            <div class='success-box'>
                <h1>เชื่อมต่อกับ LINE สำเร็จ!</h1>
                <p>คุณได้เชื่อมต่อบัญชี LINE ของคุณเรียบร้อยแล้ว</p>
                <p>กรุณาตรวจสอบข้อความใน LINE และทำตามคำแนะนำเพื่อยืนยันตัวตน</p>
                <button class='close-btn' onclick='window.close()'>ปิดหน้าต่างนี้</button>
            </div>
        </body>
        </html>";
        
        // บันทึก Line ID ลงในฐานข้อมูลเพื่อรอการยืนยันอีเมล
        $sql = "INSERT INTO line_verification (line_id, display_name, created_at) VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE display_name = ?, created_at = NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $userId, $userName, $userName);
        
        if (!$stmt->execute()) {
            error_log("Failed to insert LINE ID: " . $stmt->error);
        } else {
            error_log("LINE ID inserted/updated successfully");
        }
    } else {
        error_log("Failed to get user profile");
        echo "Failed to get user profile information.";
    }
} else {
    error_log("Failed to get access token: " . json_encode($tokenData));
    echo "Failed to authenticate with LINE.";
}

// สร้างตาราง webhook_handler.php เพื่อจัดการกับข้อความที่ส่งมาจาก LINE
// ฟังก์ชันนี้จะถูกเรียกเมื่อผู้ใช้ส่งข้อความกลับมาหลังจากการเชื่อมต่อสำเร็จ

// ปิดการเชื่อมต่อฐานข้อมูล
$conn->close();
?>