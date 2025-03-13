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
                
                        // ตรวจสอบว่าข้อความเป็นหมายเลขบิลหรือไม่ (เป็นตัวเลขทั้งหมด)
                        if (is_numeric($userMessage)) {
                            showBillDetails($replyToken, $userMessage, $conn, $access_token);
                            continue; // ข้ามการประมวลผลส่วนที่เหลือ
                        }
                
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
                            } elseif (strpos(strtolower($userMessage), "ดูบิลหมายเลขที่") === 0) {
                                // แยกหมายเลขบิลออกจากข้อความ
                                $billNumber = trim(str_replace("ดูบิลหมายเลขที่", "", $userMessage));
                                
                                // ตรวจสอบว่าหมายเลขบิลเป็นตัวเลขหรือไม่
                                if (is_numeric($billNumber)) {
                                    showBillDetails($replyToken, $billNumber, $conn, $access_token);
                                } else {
                                    $message = "รูปแบบหมายเลขบิลไม่ถูกต้อง กรุณาระบุหมายเลขบิลที่เป็นตัวเลขเท่านั้น\nตัวอย่าง: ดูบิลหมายเลขที่123456789";
                                    sendReply($replyToken, $message, $access_token);
                                }
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
            $message = "ไม่พบอีเมลนี้ในระบบ กรุณาลงทะเบียนก่อนใช้งานได้ที่นี่:https://ef9a-122-155-57-103.ngrok-free.app/NT/page/login.php";
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

// ฟังก์ชัน showCustomerBill ที่อัปเดตใหม่เมื่อผู้ใช้เลือก "ดู"
function showCustomerBill($replyToken, $customerId, $conn, $accessToken) {
    error_log("Showing bills for customer ID: " . $customerId);

    // 1. ดึงข้อมูลลูกค้า
    $sqlCustomer = "SELECT name_customer FROM customers WHERE id_customer = ?";
    $stmtCustomer = $conn->prepare($sqlCustomer);
    $stmtCustomer->bind_param("i", $customerId);
    $stmtCustomer->execute();
    $resultCustomer = $stmtCustomer->get_result();
    
    if ($resultCustomer->num_rows == 0) {
        sendReply($replyToken, "ไม่พบข้อมูลลูกค้า", $accessToken);
        return;
    }

    $customer = $resultCustomer->fetch_assoc();
    $customerName = $customer['name_customer'];

    // 2. ดึงข้อมูลบิลทั้งหมดของลูกค้า
    $sqlBills = "SELECT id_bill, number_bill, type_bill, end_date FROM bill_customer WHERE id_customer = ?";
    $stmtBills = $conn->prepare($sqlBills);
    $stmtBills->bind_param("i", $customerId);
    $stmtBills->execute();
    $resultBills = $stmtBills->get_result();
    
    // 3. คำนวณรายได้รวม
    $totalRevenue = getTotalRevenue($customerId, $conn);
    
    // 4. สร้างข้อความตอบกลับ
    $message = "ข้อมูลบิลของลูกค้า: " . $customerName . "\n\n";
    
    if ($resultBills->num_rows > 0) {
        while ($bill = $resultBills->fetch_assoc()) {
            $message .= "บิลเลขที่: " . $bill['number_bill'] . "\n";
            $message .= "ประเภท: " . $bill['type_bill'] . "\n";
            $message .= "วันหมดอายุ: " . $bill['end_date'] . "\n\n";
        }

        // เพิ่มข้อมูลรายได้รวม
        $message .= "รายได้รวมทั้งหมด: " . number_format($totalRevenue, 2) . " บาท";
        
        // ส่ง Quick Reply ให้เลือก "สนใจหมายเลขบิล" หรือ "ไม่สนใจบิล"
        $quickActions = [];

        // ดึงข้อมูลหมายเลขบิลทั้งหมด
        $resultBills->data_seek(0); // Reset pointer to start
        while ($bill = $resultBills->fetch_assoc()) {
            $quickActions[] = [
                "action" => [
                    "type" => "message",
                    "label" => "บิลเลขที่ " . $bill['number_bill'],
                    "text" => $bill['number_bill']
                ]
            ];
        }

        // ส่ง Quick Reply สำหรับการเลือกหมายเลขบิล
        $quickActions[] = [
            "action" => [
                "type" => "message",
                "label" => "ไม่สนใจบิล",
                "text" => "ไม่สนใจบิล"
            ]
        ];

        sendQuickReply($replyToken, $message, $accessToken, $quickActions);
    } else {
        $message .= "ไม่พบข้อมูลบิลของลูกค้าท่านนี้";
        sendReply($replyToken, $message, $accessToken);
    }
}

function showBillDetails($replyToken, $billNumber, $conn, $accessToken) { 
    error_log("Retrieving details for bill number: " . $billNumber);

    // 1. ดึง id_bill จากหมายเลขบิล
    $sqlGetBillId = "SELECT id_bill FROM bill_customer WHERE number_bill = ?";
    $stmtGetBillId = $conn->prepare($sqlGetBillId);
    $stmtGetBillId->bind_param("s", $billNumber);
    $stmtGetBillId->execute();
    $resultBillId = $stmtGetBillId->get_result();
    
    if ($resultBillId->num_rows == 0) {
        $message = "ไม่พบข้อมูลบิลเลขที่: " . $billNumber;
        sendReply($replyToken, $message, $accessToken);
        return;
    }
    
    $billRow = $resultBillId->fetch_assoc();
    $idBill = $billRow['id_bill'];
    error_log("Found id_bill: " . $idBill . " for Bill Number: " . $billNumber);

    // Prepare the response message
    $message = "ข้อมูลในบิลเลขที่: " . $billNumber . "\n\n";

    // 2. ดึงข้อมูลอุปกรณ์จากตาราง gedget
    $sqlGadgets = "SELECT name_gedget FROM gedget WHERE id_bill = ?";
    $stmtGadgets = $conn->prepare($sqlGadgets);
    $stmtGadgets->bind_param("i", $idBill);
    $stmtGadgets->execute();
    $resultGadgets = $stmtGadgets->get_result();

    // Check if gadgets exist and add to message
    if ($resultGadgets && $resultGadgets->num_rows > 0) {
        $message .= "อุปกรณ์:\n";
        $gadgets = [];
        while ($gadget = $resultGadgets->fetch_assoc()) {
            $gadgets[] = $gadget['name_gedget'];
        }
        $message .= implode(", ", $gadgets) . "\n\n"; // แสดงอุปกรณ์ทั้งหมดที่เชื่อมโยง
    } else {
        $message .= "อุปกรณ์: ไม่มีข้อมูล\n\n";
    }

    // 3. ดึงข้อมูลบริการทั้งหมดก่อน (แยกจาก package และ product)
    $sqlServices = "
        SELECT 
            code_service,
            type_service,
            type_gadget,
            status_service,
            id_service
        FROM service_customer
        WHERE id_bill = ?
    ";

    $stmtServices = $conn->prepare($sqlServices);
    $stmtServices->bind_param("i", $idBill);
    $stmtServices->execute();
    $resultServices = $stmtServices->get_result();

    // Check if services exist and add to message
    if ($resultServices && $resultServices->num_rows > 0) {
        $message .= "บริการ:\n";
        $totalOverride = 0;
        $serviceCounter = 1;
        
        // สร้าง array เพื่อเก็บข้อมูล service ทั้งหมด
        $services = [];
        while ($service = $resultServices->fetch_assoc()) {
            $services[] = $service;
        }

        // วนลูปแสดงข้อมูลบริการแต่ละรายการ
        foreach ($services as $service) {
            $message .= "{$serviceCounter}. หมายเลขบริการ: " . $service['code_service'] . "\n";
            $message .= "   ประเภทบริการ: " . $service['type_service'] . "\n";
            $message .= "   ประเภทอุปกรณ์: " . $service['type_gadget'] . "\n";
            $message .= "   สถานะ: " . $service['status_service'] . "\n\n";

            // ดึงข้อมูล package ทั้งหมดที่เกี่ยวข้องกับบริการนี้
            $sqlPackages = "
                SELECT id_package, name_package 
                FROM package_list 
                WHERE id_service = ?
            ";
            $stmtPackages = $conn->prepare($sqlPackages);
            $stmtPackages->bind_param("i", $service['id_service']);
            $stmtPackages->execute();
            $resultPackages = $stmtPackages->get_result();

            if ($resultPackages && $resultPackages->num_rows > 0) {
                $message .= "   แพ็คเกจที่เกี่ยวข้อง:\n";
                $packageCounter = 1;
                
                while ($package = $resultPackages->fetch_assoc()) {
                    $message .= "   {$packageCounter}. " . $package['name_package'] . "\n";
                    
                    // ดึงข้อมูล product และ override ทั้งหมดที่เกี่ยวข้องกับ package นี้
                    $sqlProducts = "
                        SELECT 
                            pr.id_product,
                            pr.name_product,
                            o.mainpackage_price,
                            o.ict_price,
                            o.all_price
                        FROM product_list pr
                        LEFT JOIN overide o ON pr.id_product = o.id_product
                        WHERE pr.id_package = ?
                    ";
                    $stmtProducts = $conn->prepare($sqlProducts);
                    $stmtProducts->bind_param("i", $package['id_package']);
                    $stmtProducts->execute();
                    $resultProducts = $stmtProducts->get_result();

                    if ($resultProducts && $resultProducts->num_rows > 0) {
                        $message .= "      โปรดักส์:\n";
                        $productCounter = 1;
                        
                        while ($product = $resultProducts->fetch_assoc()) {
                            $message .= "      {$productCounter}. " . $product['name_product'] . "\n";
                            
                            // แสดงราคา Override
                            if (isset($product['mainpackage_price'])) {
                                $message .= "         ราคาแพ็กเกจหลัก: " . number_format($product['mainpackage_price'], 2) . " บาท\n";
                            }
                            
                            if (isset($product['ict_price'])) {
                                $message .= "         ราคา ICT: " . number_format($product['ict_price'], 2) . " บาท\n";
                            }
                            
                            if (isset($product['all_price'])) {
                                $message .= "         ราคารวม: " . number_format($product['all_price'], 2) . " บาท\n";
                                $totalOverride += $product['all_price'];
                            }
                            
                            $productCounter++;
                            $message .= "\n";
                        }
                    } else {
                        $message .= "      ไม่พบข้อมูลโปรดักส์\n\n";
                    }
                    
                    $packageCounter++;
                }
            } else {
                $message .= "   ไม่พบข้อมูลแพ็คเกจ\n\n";
            }
            
            $serviceCounter++;
        }

        // เพิ่มราคา Override รวมทั้งหมด
        $message .= "ราคา Override รวมทั้งหมด: " . number_format($totalOverride, 2) . " บาท";
    } else {
        $message .= "บริการ: ไม่พบข้อมูล";
    }

    // ส่งข้อความให้ผู้ใช้
    sendReply($replyToken, $message, $accessToken);
}

function handleMessage($replyToken, $userMessage, $userId, $conn, $accessToken) {
    $lowerMsg = strtolower(trim($userMessage));
    $currentState = getUserState($userId, $conn);

    // ตรวจสอบว่าเป็นหมายเลขบิลหรือไม่ (เช่น เป็นตัวเลข 9-10 หลัก)
    if (preg_match('/^[0-9]{9,10}$/', $userMessage)) {
        // ถ้าเป็นรูปแบบของหมายเลขบิล (ตัวเลข 9-10 หลัก) 
        // ให้เรียกฟังก์ชัน showBillDetails
        showBillDetails($replyToken, $userMessage, $conn, $accessToken);
        return; // ออกจากฟังก์ชันหลังจากที่ทำงานกับหมายเลขบิลแล้ว
    }

    // ถ้าผู้ใช้เลือกหมายเลขบิลที่มีในระบบ
    if ($currentState == 'WAITING_BILL_NUMBER') {
        showBillDetails($replyToken, $userMessage, $conn, $accessToken);
        updateUserState($userId, null, $conn);
    }

    // ถ้าผู้ใช้ตอบ "ดู" หรือ "ไม่ดู" ในกรณีของการดูบิล
    if ($currentState == 'WAITING_BILL_CONFIRM') {
        if ($lowerMsg == 'ดู') {
            // แสดงข้อมูลบิลลูกค้า
            showCustomerBill($replyToken, $userId, $conn, $accessToken);
            updateUserState($userId, null, $conn);
        } elseif ($lowerMsg == 'ไม่ดู') {
            // กลับไปที่เมนูหลัก
            sendToMainMenu($replyToken, $accessToken);
            updateUserState($userId, 'WAITING_MAIN_MENU', $conn);
        } else {
            $msg = "กรุณาเลือก 'ดู' หรือ 'ไม่ดู'";
            sendReply($replyToken, $msg, $accessToken);
        }
    }
}

// ฟังก์ชัน handlePostback ที่รับคำสั่งจากการเลือกใน Quick Reply
function handlePostback($event, $conn, $accessToken) {
    $replyToken = $event['replyToken'];
    $data = $event['postback']['data'];
    sendReply($replyToken, "ได้รับคำสั่ง: " . $data, $accessToken);
}


// ฟังก์ชันคำนวณรายได้รวมจากทุกๆ บิลของลูกค้า
function getTotalRevenue($customerId, $conn) {
    // เพิ่ม error log เพื่อตรวจสอบว่าฟังก์ชันถูกเรียกด้วย customerId ที่ถูกต้อง
    error_log("Calculating total revenue for customer ID: " . $customerId);
    
    // ปรับ SQL ให้เรียบง่ายขึ้นและตรวจสอบว่าตรงกับโครงสร้างฐานข้อมูลจริง
    $sql = "
         SELECT SUM(o.all_price) AS total_revenue
        FROM bill_customer b
        JOIN service_customer s ON b.id_bill = s.id_bill
        JOIN package_list p ON s.id_service = p.id_service
        JOIN product_list pr ON p.id_package = pr.id_package
        JOIN overide o ON pr.id_product = o.id_product
        WHERE b.id_customer = ?
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        
        // ตรวจสอบว่า prepare statement สำเร็จหรือไม่
        if ($stmt === false) {
            error_log("SQL Prepare Error: " . $conn->error);
            return 0;
        }
        
        $stmt->bind_param("i", $customerId);
        $success = $stmt->execute();
        
        // ตรวจสอบว่า execute สำเร็จหรือไม่
        if ($success === false) {
            error_log("SQL Execute Error: " . $stmt->error);
            return 0;
        }
        
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $total = $row['total_revenue'] ? $row['total_revenue'] : 0;
            error_log("Total Revenue calculated: " . $total);
            return $total;
        } else {
            error_log("No revenue data found or SQL error");
            return 0;
        }
    } catch (Exception $e) {
        error_log("Exception in getTotalRevenue: " . $e->getMessage());
        return 0;
    }
}


// เพิ่มฟังก์ชันตรวจสอบโครงสร้างฐานข้อมูล (สำหรับการแก้ไขปัญหา)
function debugDatabaseSchema($conn) {
    // ตรวจสอบโครงสร้างตาราง bill_customer
    $tables = [
        'bill_customer', 
        'service_customer', 
        'package_list', 
        'product_list', 
        'overide'
    ];
    
    $debugInfo = "Database Schema Debug:\n";
    
    foreach ($tables as $table) {
        $result = $conn->query("DESCRIBE " . $table);
        if ($result) {
            $debugInfo .= "Table {$table} exists with columns:\n";
            while ($row = $result->fetch_assoc()) {
                $debugInfo .= "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
            }
        } else {
            $debugInfo .= "Table {$table} does not exist or error: " . $conn->error . "\n";
        }
        $debugInfo .= "\n";
    }
    
    error_log($debugInfo);
    return $debugInfo;
}

function showAccountDetails($replyToken, $userId, $conn, $accessToken) {
    // ค้นหาข้อมูลลูกค้าจากฐานข้อมูล
    $sql = "SELECT name_customer, phone_customer, status_customer FROM customers WHERE id_customer = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId); // ใช้ userId เพื่อค้นหาข้อมูลของลูกค้า
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // หากพบข้อมูลลูกค้า
        $customer = $result->fetch_assoc();
        $name_customer = $customer['name_customer'];
        $phone_customer = $customer['phone_customer'];
        $status_customer = $customer['status_customer'];

        // สร้างข้อความแสดงข้อมูลลูกค้า
        $message = "ข้อมูลลูกค้า:\n";
        $message .= "ชื่อ : " . $name_customer . "\n";
        $message .= "ข้อมูลติดต่อลูกค้า : " . $phone_customer . "\n";
        $message .= "สถานะ : " . $status_customer . "\n\n";
        $message .= "ต้องการดูข้อมูลบิลนี้หรือไม่?";

        // ส่งข้อความตอบกลับ
        sendReply($replyToken, $message, $accessToken);
    } else {
        // หากไม่พบข้อมูลลูกค้า
        $message = "ไม่พบข้อมูลของคุณ กรุณาลองใหม่อีกครั้ง!";
        sendReply($replyToken, $message, $accessToken);
    }
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
    error_log("Sending message: " . $message); // ตรวจสอบข้อความที่ถูกส่งไป

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