<?php
// ตั้งค่า LINE API
$access_token ='GC+HTqBDARcs0B/kmqeewiQ9PVm0hG7P2dQaftfXiUZvEN69jW2Q4CxXmCK0RhNfQMgXMIL6SKH5BBEQKmxKgg8bxUGBdSWsLVE8QlutkKaS3XiDnJdbU+Mk+J+QZ1WV/zXnzOBCLqnhZ+6gCuHJSwdB04t89/1O/w1cDnyilFU='; 

// ตั้งค่าการเชื่อมต่อฐานข้อมูล
$db_host = 'localhost'; 
$db_user = 'root';     
$db_pass = '';         
$db_name = 'ntdb';     

// สร้างการเชื่อมต่อฐานข้อมูล
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// รับข้อมูลจาก webhook
$json = file_get_contents('php://input');
$events = json_decode($json, true);

// บันทึก Log สำหรับ Debug
error_log("Webhook received: " . $json);

if (!empty($events['events'])) {
    foreach ($events['events'] as $event) {
        // ตรวจสอบประเภทของเหตุการณ์
        switch ($event['type']) {
            case 'follow':
                handleFollow($event, $access_token);
                break;
            
            case 'message':
                if ($event['message']['type'] == 'text') {
                    $replyToken = $event['replyToken'];
                    $userMessage = trim($event['message']['text']);
                    $userId = $event['source']['userId'];

                    // บันทึก Log ข้อความที่ได้รับ
                    error_log("Message received: " . $userMessage . " from user: " . $userId);

                    // ตรวจสอบข้อความ
                    if (in_array(strtolower($userMessage), ['สวัสดี', 'เข้าสู่ระบบ', 'hi', 'hello'])) {
                        requestEmailVerification($replyToken, $access_token);
                    } elseif (filter_var($userMessage, FILTER_VALIDATE_EMAIL)) {
                        verifyUserByEmail($replyToken, $userMessage, $userId, $conn, $access_token);
                    } elseif ($userMessage == "รายละเอียดบัญชี") {
                        showAccountDetails($replyToken, $userId, $conn, $access_token);
                    } elseif ($userMessage == "ติดต่อเจ้าหน้าที่") {
                        contactSupport($replyToken, $access_token);
                    } elseif ($userMessage == "ช่วยเหลือ") {
                        showHelp($replyToken, $access_token);
                    } else {
                        // ข้อความอื่นๆ ที่ไม่เข้าเงื่อนไข
                        $defaultReply = "สวัสดีครับ/ค่ะ ไม่เข้าใจคำสั่ง กรุณาพิมพ์ 'เข้าสู่ระบบ' เพื่อเริ่มใช้งาน หรือเลือกเมนูด้านล่าง";
                        sendReply($replyToken, $defaultReply, $access_token);
                    }
                }
                break;
                
            case 'postback':
                handlePostback($event, $conn, $access_token);
                break;
        }
    }
}

// ฟังก์ชันเมื่อผู้ใช้เพิ่มเพื่อน
function handleFollow($event, $accessToken) {
    $userId = $event['source']['userId'];
    $welcomeMessage = "สวัสดี! ยินดีต้อนรับสู่ระบบ\n" . 
                      "กรุณาพิมพ์คำว่า 'เข้าสู่ระบบ' เพื่อเริ่มการยืนยันตัวตน";
    
    sendReply($event['replyToken'], $welcomeMessage, $accessToken);
}

// ฟังก์ชันขอให้ส่งอีเมลเพื่อยืนยันตัวตน
function requestEmailVerification($replyToken, $accessToken) {
    $message = "กรุณายืนยันตัวตนด้วย Email ของคุณ\n" .
               "โปรดพิมพ์ Email ที่ใช้ลงทะเบียนในระบบมาในช่องแชท";
    
    sendReply($replyToken, $message, $accessToken);
}

// ฟังก์ชันตรวจสอบอีเมลและค้นหาผู้ใช้
function verifyUserByEmail($replyToken, $email, $userId, $conn, $accessToken) {
    try {
        // ค้นหาผู้ใช้ในฐานข้อมูลจากอีเมล
        $sql = "SELECT id, name, verify FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // ถ้ามีอีเมลและยืนยันแล้ว ให้ส่งข้อความต้อนรับทันที
            if ($user['verify'] == 1) {
                // สร้างเมนูแบบ Flex Message
                $welcomeMessage = createWelcomeMenu($user['name']);
                
                // ส่ง Flex Message
                sendFlexReply($replyToken, $welcomeMessage, $accessToken);
                
                // บันทึก Log เมื่อยืนยันตัวตนสำเร็จ
                error_log("User verification successful: " . $email . " (User ID: " . $user['id'] . ")");
            } else {
                // กรณียังไม่ได้ยืนยัน
                $message = "คุณยังไม่ได้ยืนยันตัวตน กรุณาลงทะเบียนหรือยืนยันอีเมลก่อนใช้งาน";
                sendReply($replyToken, $message, $accessToken);
            }
        } else {
            // ไม่พบอีเมล
            $message = "ไม่พบอีเมลนี้ในระบบ กรุณาตรวจสอบความถูกต้องหรือลงทะเบียนก่อนใช้งาน";
            sendReply($replyToken, $message, $accessToken);
        }
    } catch (Exception $e) {
        // บันทึก Log ข้อผิดพลาด
        error_log("Error in verifyUserByEmail: " . $e->getMessage());
        $message = "ขออภัย เกิดข้อผิดพลาดในการตรวจสอบข้อมูล กรุณาลองอีกครั้งในภายหลัง";
        sendReply($replyToken, $message, $accessToken);
    }
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
                            "label" => "ข้อมูลติดต่อลูกค้า",
                            "text" => "ข้อมูลติดต่อลูกค้า"
                        ],
                        "color" => "#1DB446"
                    ],
                    [
                        "type" => "button",
                        "style" => "primary",
                        "action" => [
                            "type" => "message",
                            "label" => "ข้อมูลบิลลูกค้า",
                            "text" => "ข้อมูลบิลลูกค้า"
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

// ฟังก์ชันแสดงรายละเอียดบัญชี
function showAccountDetails($replyToken, $userId, $conn, $accessToken) {
    $message = "ข้อมูลบัญชีของคุณ:\n\n" . 
               "- ชื่อผู้ใช้: [ชื่อผู้ใช้]\n" . 
               "- อีเมล: [อีเมล]\n" . 
               "- สถานะ: ใช้งาน\n\n" .
               "หากต้องการแก้ไขข้อมูล กรุณาติดต่อเจ้าหน้าที่";
    
    sendReply($replyToken, $message, $accessToken);
}

// ฟังก์ชันติดต่อเจ้าหน้าที่
function contactSupport($replyToken, $accessToken) {
    $message = "ช่องทางติดต่อเจ้าหน้าที่:\n\n" . 
               "โทร: 02-XXX-XXXX\n" . 
               "อีเมล: support@example.com\n" . 
               "Line Official: @example\n\n" .
               "เวลาทำการ: จันทร์-ศุกร์ 8.30-17.30 น.";
    
    sendReply($replyToken, $message, $accessToken);
}

// ฟังก์ชันแสดงความช่วยเหลือ
function showHelp($replyToken, $accessToken) {
    $message = "วิธีใช้งาน LINE Bot:\n\n" . 
               "1. พิมพ์ 'เข้าสู่ระบบ' เพื่อยืนยันตัวตน\n" . 
               "2. กรอกอีเมลที่ลงทะเบียนไว้\n" . 
               "3. เลือกเมนูที่ต้องการใช้งาน\n\n" .
               "หากพบปัญหา กรุณาติดต่อเจ้าหน้าที่";
    
    sendReply($replyToken, $message, $accessToken);
}

// ฟังก์ชันจัดการ Postback
function handlePostback($event, $conn, $accessToken) {
    $replyToken = $event['replyToken'];
    $data = $event['postback']['data'];
    
    // จัดการข้อมูล postback ตามที่กำหนด
    // สามารถเพิ่มเติมได้ตามความต้องการ
    
    sendReply($replyToken, "ได้รับคำสั่ง: " . $data, $accessToken);
}

// ฟังก์ชันส่งข้อความ Flex Reply
function sendFlexReply($replyToken, $flexMessage, $accessToken) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    );

    $data = array(
        'replyToken' => $replyToken,
        'messages' => [
            $flexMessage
        ]
    );

    // บันทึก Log ข้อมูลที่จะส่ง
    error_log("Sending Flex Message: " . json_encode($data));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // บันทึก Log ผลลัพธ์
    error_log("LINE API Response Code: " . $httpCode . " Response: " . $response);
    
    return $response;
}

// ฟังก์ชันส่งข้อความตอบกลับปกติ
function sendReply($replyToken, $message, $accessToken) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    );

    $data = array(
        'replyToken' => $replyToken,
        'messages' => array(
            array(
                'type' => 'text',
                'text' => $message
            )
        )
    );

    // บันทึก Log ข้อมูลที่จะส่ง
    error_log("Sending Reply: " . json_encode($data));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // บันทึก Log ผลลัพธ์
    error_log("LINE API Response Code: " . $httpCode . " Response: " . $response);
    
    return $response;
}
?>