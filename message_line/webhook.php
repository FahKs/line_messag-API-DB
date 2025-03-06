<?php
// ตั้งค่า LINE API
$access_token = 'GC+HTqBDARcs0B/kmqeewiQ9PVm0hG7P2dQaftfXiUZvEN69jW2Q4CxXmCK0RhNfQMgXMIL6SKH5BBEQKmxKgg8bxUGBdSWsLVE8QlutkKaS3XiDnJdbU+Mk+J+QZ1WV/zXnzOBCLqnhZ+6gCuHJSwdB04t89/1O/w1cDnyilFU='; 

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

                    // 1) ตรวจสอบสถานะปัจจุบันของผู้ใช้ในตาราง line_user_state
                    $currentState = getUserState($userId, $conn);

                    // 2) ถ้าสถานะคือ WAITING_CUSTOMER_NAME แสดงว่าข้อความที่ส่งมาคือชื่อของลูกค้า
                    if ($currentState == 'WAITING_CUSTOMER_NAME') {
                        // เรียกฟังก์ชันสำหรับดึงข้อมูลลูกค้าจาก DB
                        showCustomerContact($replyToken, $userId, $conn, $access_token, $userMessage);

                        // เมื่อดึงข้อมูลเสร็จแล้ว อาจตั้งค่าสถานะกลับเป็น null หรือสถานะอื่น
                        updateUserState($userId, null, $conn);

                    // 3) หากสถานะยังไม่ใช่ WAITING_CUSTOMER_NAME ให้ดูเงื่อนไขคำสั่งปกติ
                    } else {
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

                        } elseif ($userMessage == "ข้อมูลติดต่อลูกค้า") {
                            // เมื่อผู้ใช้กดปุ่ม "ข้อมูลติดต่อลูกค้า" -> ขอให้ระบุชื่อ
                            // และตั้งสถานะเป็น WAITING_CUSTOMER_NAME
                            askForCustomerName($replyToken, $userId, $conn, $access_token);

                        } else {
                            // ข้อความอื่นๆ ที่ไม่เข้าเงื่อนไข
                            $defaultReply = "สวัสดีครับ/ค่ะ ไม่เข้าใจคำสั่ง กรุณาพิมพ์ 'เข้าสู่ระบบ' เพื่อเริ่มใช้งาน หรือเลือกเมนูด้านล่าง";
                            sendReply($replyToken, $defaultReply, $access_token);
                        }
                    }
                }
                break;
                
            case 'postback':
                handlePostback($event, $conn, $access_token);
                break;
        }
    }
}

// ------------------ ฟังก์ชันต่าง ๆ ------------------ //

// 1. ฟังก์ชันเมื่อผู้ใช้เพิ่มเพื่อน
function handleFollow($event, $accessToken) {
    $userId = $event['source']['userId'];
    $welcomeMessage = "สวัสดี! ยินดีต้อนรับสู่ระบบ\n" . 
                      "กรุณาพิมพ์คำว่า 'เข้าสู่ระบบ' เพื่อเริ่มการยืนยันตัวตน";
    
    sendReply($event['replyToken'], $welcomeMessage, $accessToken);
}

// 2. ฟังก์ชันขอให้ส่งอีเมลเพื่อยืนยันตัวตน
function requestEmailVerification($replyToken, $accessToken) {
    $message = "กรุณายืนยันตัวตนด้วย Email ของคุณ\n" .
               "โปรดพิมพ์ Email ที่ใช้ลงทะเบียนในระบบมาในช่องแชท";
    
    sendReply($replyToken, $message, $accessToken);
}

// 3. ฟังก์ชันตรวจสอบอีเมลและค้นหาผู้ใช้
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

// 4. สร้าง Flex Message สำหรับเมนูหลัก
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

// 5. ฟังก์ชันถามชื่อก่อน (เมื่อต้องการดูข้อมูลติดต่อลูกค้า)
function askForCustomerName($replyToken, $userId, $conn, $accessToken) {
    // อัปเดตสถานะผู้ใช้เป็น WAITING_CUSTOMER_NAME
    updateUserState($userId, 'WAITING_CUSTOMER_NAME', $conn);

    // ส่งข้อความให้ผู้ใช้ระบุชื่อ
    $message = "กรุณาระบุชื่อลูกค้าที่ต้องการค้นหา:";
    sendReply($replyToken, $message, $accessToken);
}

// 6. ฟังก์ชันแสดงข้อมูลติดต่อลูกค้า (หลังจากผู้ใช้พิมพ์ชื่อมาแล้ว)
function showCustomerContact($replyToken, $userId, $conn, $accessToken, $customerName) {
    // ตัวอย่าง: ค้นในตาราง customers โดยใช้ชื่อ (customerName)
    // ในโค้ดนี้ สมมติว่าตาราง customers มีคอลัมน์:
    //   - name_customer
    //   - phone_customer
    //   - status_customer
    // ต้องตรวจสอบว่าใน DB มีข้อมูลชื่อที่ตรงกันจริง ๆ

    $sql = "SELECT name_customer, phone_customer, status_customer 
            FROM customers 
            WHERE name_customer = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $customerName);
    $stmt->execute();
    $result = $stmt->get_result();

    // Debug: ดูว่าค้นหาแล้วได้กี่แถว
    error_log("Search customer: " . $customerName . " => rows: " . $result->num_rows);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // สร้างข้อความที่ต้องการส่งกลับ
        $message  = "ข้อมูลลูกค้า:\n";
        $message .= "ชื่อ : " . $row['name_customer'] . "\n";
        $message .= "เบอร์โทร : " . $row['phone_customer'] . "\n";
        $message .= "สถานะ : " . $row['status_customer'] . "\n\n";
        $message .= "ต้องการดูข้อมูลบิลลูกค้าหรือไม่?";
    } else {
        $message = "ไม่พบข้อมูลลูกค้าชื่อ: " . $customerName;
    }

    sendReply($replyToken, $message, $accessToken);
}

// 7. ฟังก์ชันแสดงรายละเอียดบัญชี (ตัวอย่างเดิม)
function showAccountDetails($replyToken, $userId, $conn, $accessToken) {
    // สมมุติข้อมูลของลูกค้า (ในโปรเจคจริง ควร query จากฐานข้อมูลโดยใช้ $userId)
    $name_customer  = "สมชาย ใจดี";
    $phone_customer = "081-234-5678";
    $status_customer = "Active";
    
    // สร้างข้อความแสดงรายละเอียดลูกค้า
    $message  = "ข้อมูลลูกค้า:\n";
    $message .= "ชื่อ : " . $name_customer . "\n";
    $message .= "ข้อมูลติดต่อลูกค้า : " . $phone_customer . "\n";
    $message .= "สถานะ : " . $status_customer . "\n\n";
    $message .= "ต้องการดูข้อมูลบิลนี้หรือไม่?";

    sendReply($replyToken, $message, $accessToken);
}

// 8. ฟังก์ชันติดต่อเจ้าหน้าที่
function contactSupport($replyToken, $accessToken) {
    $message = "ช่องทางติดต่อเจ้าหน้าที่:\n\n" . 
               "โทร: 02-XXX-XXXX\n" . 
               "อีเมล: support@example.com\n" . 
               "Line Official: @example\n\n" .
               "เวลาทำการ: จันทร์-ศุกร์ 8.30-17.30 น.";
    
    sendReply($replyToken, $message, $accessToken);
}

// 9. ฟังก์ชันแสดงความช่วยเหลือ
function showHelp($replyToken, $accessToken) {
    $message = "วิธีใช้งาน LINE Bot:\n\n" . 
               "1. พิมพ์ 'เข้าสู่ระบบ' เพื่อยืนยันตัวตน\n" . 
               "2. กรอกอีเมลที่ลงทะเบียนไว้\n" . 
               "3. เลือกเมนูที่ต้องการใช้งาน\n\n" .
               "หากพบปัญหา กรุณาติดต่อเจ้าหน้าที่";
    
    sendReply($replyToken, $message, $accessToken);
}

// 10. ฟังก์ชันจัดการ Postback
function handlePostback($event, $conn, $accessToken) {
    $replyToken = $event['replyToken'];
    $data = $event['postback']['data'];
    
    // จัดการข้อมูล postback ตามที่กำหนด
    // สามารถเพิ่มเติมได้ตามความต้องการ
    
    sendReply($replyToken, "ได้รับคำสั่ง: " . $data, $accessToken);
}

// ------------------ ส่วนจัดการ User State ใน DB ------------------ //

// ฟังก์ชันดึงสถานะผู้ใช้จากตาราง line_user_state
function getUserState($userId, $conn) {
    $sql = "SELECT state FROM line_user_state WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['state'];
    }
    return null;
}

// ฟังก์ชันอัปเดตสถานะผู้ใช้ในตาราง line_user_state
function updateUserState($userId, $state, $conn) {
    $sql = "INSERT INTO line_user_state (user_id, state, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $userId, $state);
    $stmt->execute();
}

// ------------------ ฟังก์ชันส่งข้อความ (Reply / Flex) ------------------ //

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
