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
        switch ($event['type']) {
            case 'follow':
                handleFollow($event, $access_token);
                break;
            
            case 'message':
                if ($event['message']['type'] == 'text') {
                    $replyToken = $event['replyToken'];
                    $userMessage = trim($event['message']['text']);
                    $userId = $event['source']['userId'];
                    
                    error_log("Message received: " . $userMessage . " from user: " . $userId);

                    // ตรวจสอบ state ปัจจุบันของผู้ใช้
                    $currentState = getUserState($userId, $conn);

                    if ($currentState == 'WAITING_CUSTOMER_NAME') {
                        // เมื่อผู้ใช้พิมพ์ชื่อลูกค้า
                        showCustomerContact($replyToken, $userId, $conn, $access_token, $userMessage);
                        // state จะถูกอัปเดตภายใน showCustomerContact
                    } elseif (strpos($currentState, 'WAITING_BILL_CONFIRM:') === 0) {
                        // เมื่อผู้ใช้ตอบ Quick Reply "ดู" หรือ "ไม่ดู"
                        $parts = explode(':', $currentState);
                        if (count($parts) == 2) {
                            $customerId = $parts[1]; // id_customer
                            $lowerMsg = strtolower(trim($userMessage));
                            if ($lowerMsg == "ดู") {
                                showCustomerBill($replyToken, $customerId, $conn, $access_token);
                                updateUserState($userId, null, $conn);
                            } elseif ($lowerMsg == "ไม่ดู") {
                                $welcomeMenu = createWelcomeMenu("สมาชิก");
                                sendFlexReply($replyToken, $welcomeMenu, $access_token);
                                updateUserState($userId, null, $conn);
                            } else {
                                $msg = "กรุณาเลือก 'ดู' หรือ 'ไม่ดู'";
                                sendReply($replyToken, $msg, $access_token);
                            }
                        }
                    } else {
                        // คำสั่งปกติ
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
                            updateUserState($userId, 'WAITING_CUSTOMER_NAME', $conn);
                            askForCustomerName($replyToken, $access_token);
                        } else {
                            $defaultReply = "สวัสดีครับ/ค่ะ ไม่เข้าใจคำสั่ง กรุณาพิมพ์ 'เข้าสู่ระบบ' หรือเลือกเมนูด้านล่าง";
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

function handleFollow($event, $accessToken) {
    $userId = $event['source']['userId'];
    $welcomeMessage = "สวัสดี! ยินดีต้อนรับสู่ระบบ\n" .
                      "กรุณาพิมพ์คำว่า 'เข้าสู่ระบบ' เพื่อเริ่มการยืนยันตัวตน";
    sendReply($event['replyToken'], $welcomeMessage, $accessToken);
}

function requestEmailVerification($replyToken, $accessToken) {
    $message = "กรุณายืนยันตัวตนด้วย Email ของคุณ\n" .
               "โปรดพิมพ์ Email ที่ใช้ลงทะเบียนในระบบมาในช่องแชท";
    sendReply($replyToken, $message, $accessToken);
}

function verifyUserByEmail($replyToken, $email, $userId, $conn, $accessToken) {
    try {
        $sql = "SELECT id, name, verify FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if ($user['verify'] == 1) {
                $welcomeMessage = createWelcomeMenu($user['name']);
                sendFlexReply($replyToken, $welcomeMessage, $accessToken);
                error_log("User verification successful: " . $email . " (User ID: " . $user['id'] . ")");
            } else {
                $message = "คุณยังไม่ได้ยืนยันตัวตน กรุณาลงทะเบียนหรือยืนยันอีเมลก่อนใช้งาน";
                sendReply($replyToken, $message, $accessToken);
            }
        } else {
            $message = "ไม่พบอีเมลนี้ในระบบ กรุณาลงทะเบียนก่อนใช้งานได้ที่นี่:https://246c-125-26-7-18.ngrok-free.app/NT/page/login.php";
            sendReply($replyToken, $message, $accessToken);
        }
    } catch (Exception $e) {
        error_log("Error in verifyUserByEmail: " . $e->getMessage());
        $message = "ขออภัย เกิดข้อผิดพลาด กรุณาลองอีกครั้งในภายหลัง";
        sendReply($replyToken, $message, $accessToken);
    }
}

function createWelcomeMenu($name) {
    return [
        "type" => "flex",
        "altText" => "ยินดีต้อนรับคุณ $name",
        "contents" => [
            "type" => "bubble",
            "hero" => [
                "type" => "image",
                "url" => "https://via.placeholder.com/1000x400",
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

// ฟังก์ชัน askForCustomerName: ส่งข้อความให้ผู้ใช้ระบุชื่อ
function askForCustomerName($replyToken, $accessToken) {
    $message = "กรุณาระบุชื่อลูกค้าที่ต้องการค้นหา:";
    sendReply($replyToken, $message, $accessToken);
}

// ฟังก์ชัน showCustomerContact: ค้นหาลูกค้าจากตาราง customers และส่ง Quick Reply ให้เลือก "ดู" หรือ "ไม่ดู"
function showCustomerContact($replyToken, $userId, $conn, $accessToken, $customerName) {
    $sql = "SELECT id_customer, name_customer, phone_customer, status_customer 
            FROM customers 
            WHERE name_customer = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $customerName);
    $stmt->execute();
    $result = $stmt->get_result();

    error_log("Search customer: " . $customerName . " => rows: " . $result->num_rows);

    if ($result->num_rows > 0) {
        // ถ้ามีข้อมูลลูกค้า
        $row = $result->fetch_assoc();
        $customerId = $row['id_customer'];
        $message = "ข้อมูลลูกค้า: ชื่อ: " . $row['name_customer'] . ", เบอร์: " . $row['phone_customer'] . ", สถานะ: " . $row['status_customer'] . ". ต้องการดูข้อมูลบิลหรือไม่?";

        // ตั้ง state ให้รอการตอบ Quick Reply โดยแนบ id_customer
        updateUserState($userId, 'WAITING_BILL_CONFIRM:' . $customerId, $conn);

        $quickActions = [
            [
                "action" => [
                    "type" => "message",
                    "label" => "ดู",
                    "text" => "ดู"
                ]
            ],
            [
                "action" => [
                    "type" => "message",
                    "label" => "ไม่ดู",
                    "text" => "ไม่ดู"
                ]
            ]
        ];

        sendQuickReply($replyToken, $message, $accessToken, $quickActions);
    } else {
        // ถ้าไม่พบข้อมูลลูกค้า
        $message = "ไม่พบข้อมูลลูกค้าชื่อ: " . $customerName . ". กรุณาลองอีกครั้ง";

        // สร้าง Quick Reply ที่เชื่อมกลับไปยังเมนูหลัก
        $quickActions = [
            [
                "action" => [
                    "type" => "message",
                    "label" => "กลับไปที่เมนูหลัก",
                    "text" => "กลับไปที่เมนูหลัก"
                ]
            ]
        ];

        sendQuickReply($replyToken, $message, $accessToken, $quickActions);

        // อัปเดต state ให้กลับไปที่เมนูหลัก
        updateUserState($userId, 'WAITING_MAIN_MENU', $conn);

        // ส่งเมนูหลัก
        sendToMainMenu($replyToken, $accessToken);
    }
}


// ฟังก์ชัน getUserState: ตรวจสอบสถานะของผู้ใช้
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

// ฟังก์ชันส่ง Flex Message สำหรับเมนูหลัก
function sendToMainMenu($replyToken, $accessToken) {
    $message = [
        "type" => "flex",
        "altText" => "เมนูหลัก",
        "contents" => [
            "type" => "bubble",
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
                        "text" => "กรุณาเลือกเมนูด้านล่าง",
                        "size" => "sm",
                        "color" => "#aaaaaa",
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
                        "action" => [
                            "type" => "message",
                            "label" => "ข้อมูลติดต่อลูกค้า",
                            "text" => "ข้อมูลติดต่อลูกค้า"
                        ],
                        "color" => "#1DB446"
                    ],
                    [
                        "type" => "button",
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

    sendFlexReply($replyToken, $message, $accessToken);
}

function showCustomerBill($replyToken, $customerId, $conn, $accessToken) {
    // ค้นหาชื่อลูกค้าจาก id_customer
    $sqlCustomer = "SELECT name_customer FROM customers WHERE id_customer = ?";
    $stmtCustomer = $conn->prepare($sqlCustomer);
    $stmtCustomer->bind_param("s", $customerId);
    $stmtCustomer->execute();
    $resultCustomer = $stmtCustomer->get_result();

    if ($resultCustomer->num_rows > 0) {
        // ดึงชื่อลูกค้า
        $customerRow = $resultCustomer->fetch_assoc();
        $nameCustomer = $customerRow['name_customer'];

        // ค้นหาข้อมูลบิลของลูกค้า
        $sqlBill = "SELECT number_bill, type_bill, end_date
                    FROM bill_customer
                    WHERE id_customer = ?";
        $stmtBill = $conn->prepare($sqlBill);
        $stmtBill->bind_param("s", $customerId);
        $stmtBill->execute();
        $resultBill = $stmtBill->get_result();

        if ($resultBill->num_rows > 0) {
            // ถ้ามีบิลหลายใบ ให้แสดงรายการบิลทั้งหมด
            $message = "ข้อมูลบิลของลูกค้า (Name: $nameCustomer):\n\n";
            
            while ($bill = $resultBill->fetch_assoc()) {
                $message .= "หมายเลขบิล: " . $bill['number_bill'] . "\n";
                $message .= "ประเภทบิล: " . $bill['type_bill'] . "\n";
                $message .= "วันหมดสัญญาบิล: " . $bill['end_date'] . "\n\n";
            }
        } else {
            $message = "ไม่พบข้อมูลบิลของลูกค้า (Name: $nameCustomer)";
        }

        sendReply($replyToken, $message, $accessToken);
    } else {
        $message = "ไม่พบข้อมูลลูกค้า ID: $customerId";
        sendReply($replyToken, $message, $accessToken);
    }
}


function showAccountDetails($replyToken, $userId, $conn, $accessToken) {
    $name_customer  = "สมชาย ใจดี";
    $phone_customer = "081-234-5678";
    $status_customer = "Active";
    
    $message  = "ข้อมูลลูกค้า:\n";
    $message .= "ชื่อ : " . $name_customer . "\n";
    $message .= "ข้อมูลติดต่อลูกค้า : " . $phone_customer . "\n";
    $message .= "สถานะ : " . $status_customer . "\n\n";
    $message .= "ต้องการดูข้อมูลบิลนี้หรือไม่?";
    sendReply($replyToken, $message, $accessToken);
}

function contactSupport($replyToken, $accessToken) {
    $message = "ช่องทางติดต่อเจ้าหน้าที่:\n\n" . 
               "โทร: 02-XXX-XXXX\n" . 
               "อีเมล: support@example.com\n" . 
               "Line Official: @example\n\n" . 
               "เวลาทำการ: จันทร์-ศุกร์ 8.30-17.30 น.";
    sendReply($replyToken, $message, $accessToken);
}

function showHelp($replyToken, $accessToken) {
    $message = "วิธีใช้งาน LINE Bot:\n\n" . 
               "1. พิมพ์ 'เข้าสู่ระบบ' เพื่อยืนยันตัวตน\n" . 
               "2. กรอกอีเมลที่ลงทะเบียนไว้\n" . 
               "3. เลือกเมนูที่ต้องการใช้งาน\n\n" . 
               "หากพบปัญหา กรุณาติดต่อเจ้าหน้าที่";
    sendReply($replyToken, $message, $accessToken);
}

function handlePostback($event, $conn, $accessToken) {
    $replyToken = $event['replyToken'];
    $data = $event['postback']['data'];
    sendReply($replyToken, "ได้รับคำสั่ง: " . $data, $accessToken);
}
// ------------------ ส่วนจัดการ User State ------------------ //
function updateUserState($userId, $state, $conn) {
    $sql = "INSERT INTO line_user_state (user_id, state, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $userId, $state);
    $stmt->execute();
}

// ------------------ ฟังก์ชันส่งข้อความ ------------------ //

function sendFlexReply($replyToken, $flexMessage, $accessToken) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ];
    $data = [
        'replyToken' => $replyToken,
        'messages' => [$flexMessage]
    ];
    error_log("Sending Flex Message: " . json_encode($data));
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log("LINE API Response Code: " . $httpCode . " Response: " . $response);
    return $response;
}

function sendReply($replyToken, $message, $accessToken) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ];
    $data = [
        'replyToken' => $replyToken,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message
            ]
        ]
    ];
    error_log("Sending Reply: " . json_encode($data));
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log("LINE API Response Code: " . $httpCode . " Response: " . $response);
    return $response;
}

function sendQuickReply($replyToken, $message, $accessToken, $actions = []) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ];
    $quickReplyItems = [];
    foreach ($actions as $action) {
        $quickReplyItems[] = [
            "type" => "action",
            "action" => $action['action']
        ];
    }
    // Debug: log quick reply items array
    error_log("Quick Reply Items: " . json_encode($quickReplyItems));
    
    $data = [
        'replyToken' => $replyToken,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message,
                'quickReply' => [
                    'items' => $quickReplyItems
                ]
            ]
        ]
    ];
    error_log("Sending Quick Reply: " . json_encode($data));
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log("LINE API Response Code (Quick Reply): " . $httpCode . " Response: " . $response);
    return $response;
}

?>


        
