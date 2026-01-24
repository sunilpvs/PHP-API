<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/vms/CounterPartyInfo.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once __DIR__ . '/../../classes/vms/rfq-review.php';
require_once __DIR__ . '/../../classes/vms/Rfq.php';

// Authenticate the request
authenticateJWT();

// Load configuration
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$auth = new UserLogin();
$rfqObj = new Rfq();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Admin';

// Validate user
if ($username === 'guest') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: No valid user']);
    exit;
}

// Get query param
$type = $_GET['type'] ?? null;

if (!in_array($type, ['reference_id', 'vendor-info', 'status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing type parameter. Use type=reference_id or type=vendor_id']);
    exit;
}

try {
    $vendorObj = new CounterPartyInfo();
    $rfqObj = new RfqReview();
    $rfqDataObj = new Rfq();
    
    // if vendor id is present for the rfq, then return only one reference id else return all reference ids associated with the user
    if ($type === 'reference_id') {
        $hasMultipleRfqs = $vendorObj->hasMultipleRfqsForUser($username, $module, $username);
        if (!$hasMultipleRfqs) {
            $reference_id = $vendorObj->getReferenceIdByUserId($username, $module, $username);
            if (!$reference_id) {
                http_response_code(404);
                echo json_encode(['error' => 'Reference ID not found for this user']);
                exit;
            }
            echo json_encode(['reference_id' => $reference_id]);
            exit;
        }
        $reference_ids = $vendorObj->getAllReferenceIdsByUserId($username, $module, $username);
        if (empty($reference_ids)) {
            http_response_code(404);
            echo json_encode(['error' => 'No reference IDs found for this user']);
            exit;
        }
        echo json_encode(['reference_ids' => $reference_ids]);
        exit;
    

    // check rfq status by reference id
    }else if($type === 'status'){
        $reference_id = $_GET['reference_id'] ?? null;
        if (!$reference_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing reference_id parameter']);
            exit;
        }
        $status = $rfqObj->checkRfqStatus($reference_id, $module, $username);
        if ($status === null) {
            http_response_code(404);
            echo json_encode(['error' => 'RFQ status not found for this reference ID']);
            exit;
        }

        if($status == 8){
            $resubmitted = $rfqDataObj->isFormSubmittedPreviously($reference_id, $module, $username);
            if($resubmitted){
                http_response_code(200);
                echo json_encode([
                    'resubmitted' => 'true',
                    // 'status' => $status
                ]);
            }
        }
       
        http_response_code(200);
        echo json_encode(['status' => $status]);
        exit;

        //
    } else if($type = 'vendor-info'){
        $userId = $_GET['user_id'] ?? $username;
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing user_id parameter']);
            exit;
        }

        $vendorInfo = $vendorObj->getVendorInfoByUserId($username, $module, $username);

        // if no vendor info found, return 404
        if (!$vendorInfo) {
            http_response_code(404);
            echo json_encode(['vendor' => 'Vendor information not found for this user ID']);
            exit;
        }

        // return vendor info
        http_response_code(200);
        echo json_encode(['vendor_info' => $vendorInfo]);
        exit;
    }
    
    else{
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type parameter']);
        exit;
    }


    

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
