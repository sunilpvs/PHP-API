<?php
// vms/rfq-review.php

require_once(__DIR__ . "/../../classes/vms/rfq-review.php");
require_once(__DIR__ . "/../../classes/authentication/JWTHandler.php");
require_once __DIR__ . '../../../classes/Logger.php';
require_once(__DIR__ . "/../../classes/authentication/LoginUser.php");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// Initialize logger
$user = new UserLogin();
$token = $user->getToken();

if (!$token) {
    http_response_code(401);
    echo json_encode(["error" => "Access token not found"]);
    exit();
}

$jwt = new JWTHandler();

try {
    $decodedToken = $jwt->decodeJWT($token);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token"]);
    exit();
}

$username = $decodedToken['username'] ?? 'guest';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Invalid request method"]);
    exit();
}


$input = json_decode(file_get_contents('php://input'), true);
// if (!$input) {
//     http_response_code(400);
//     echo json_encode(["error" => "Invalid or missing JSON body"]);
//     exit();
// }


$action = $_GET['action'] ?? null;
$reference_id = $_GET['reference_id'] ?? null;
$vendor_code = $input['vendor_code'] ?? null;
$module = 'vms';

if (!$action) {
    http_response_code(400);
    echo json_encode(["error" => "Missing action parameter"]);
    exit();
}

// exclude for block, suspend, activate, reinitiate actions
if (!in_array($action, ['block', 'suspend', 'activate', 'reinitiate'])) {
    if (!$reference_id) {
        http_response_code(400);
        echo json_encode(["error" => "Missing reference_id"]);
        exit();
    }
}

$rfqReview = new RfqReview();

try {
    switch ($action) {

        case 'submit':

            $submitStatus = $rfqReview->checkRfqStatus($reference_id, $module, $username);

            if ($submitStatus == 9) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already verified and cannot be resubmitted until it is sent back"]);
                exit();
            }

            if ($submitStatus == 11) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already approved and cannot be resubmitted"]);
                exit();
            }

            if (in_array($submitStatus, [12, 13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is rejected / blocked / suspended and cannot be resubmitted"]);
                exit();
            }

            $submissionStatusChanged = $rfqReview->submitRfqForReview($reference_id, $module, $username);

            if ($submissionStatusChanged) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "RFQ submitted for review successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to submit RFQ for review"]);
            }
            break;

        case 'send-back':

            $sendBackStatus = $rfqReview->checkRfqStatus($reference_id, $module, $username);

            if ($sendBackStatus == 10) {
                $response = ["error" => "RFQ is already sent back for corrections"];
                http_response_code(400);
                echo json_encode($response);
                $logger->logRequestAndResponse($input, $response);
                exit();
            }

            if ($sendBackStatus == 7) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ must be submitted before it can be sent back"]);
                exit();
            }

            if ($vendorStatus == 11) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already approved and cannot be sent back"]);
                exit();
            }

            if (in_array($sendBackStatus, [12, 13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is rejected / blocked / suspended and cannot be sent back"]);
                exit();
            }


            $result = $rfqReview->sendBackRfq($reference_id, $module, $username);

            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "RFQ sent back for corrections"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to send back RFQ"]);
            }
            break;

        case 'verify':

            $expiry_date = $input['expiry_date'] ?? null;

            if (!$expiry_date) {
                http_response_code(400);
                echo json_encode(["error" => "Missing expiry_date in request body"]);
                exit();
            }

            if (strtotime($expiry_date) < time()) {
                http_response_code(400);
                echo json_encode(["error" => "Expiry date must be a future date"]);
                exit();
            }

            $verifiedStatus = $rfqReview->checkRfqStatus($reference_id, $module, $username);

            if ($verifiedStatus == 9) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already verified"]);
                exit();
            }

            if ($verifiedStatus == 11) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already approved and cannot be verified"]);
                exit();
            }

            if (in_array($verifiedStatus, [12, 13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is rejected / blocked / suspended and cannot be verified"]);
                exit();
            }

            if ($vendorStatus == 7) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ must be submitted before it can be verified"]);
                exit();
            }

            if ($vendorStatus == 10) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is sent back for corrections and cannot be verified until resubmitted"]);
                exit();
            }


            $result = $rfqReview->verifyRfq($reference_id, $expiry_date, $module, $username);

            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "RFQ verified and forwarded for approval"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to verify RFQ"]);
            }
            break;

        case 'approve':

            $expiry_date = $input['expiry_date'] ?? null;

            if (!$expiry_date) {
                http_response_code(400);
                echo json_encode(["error" => "Missing expiry_date in request body"]);
                exit();
            }

            if (strtotime($expiry_date) < time()) {
                http_response_code(400);
                echo json_encode(["error" => "Expiry date must be a future date"]);
                exit();
            }

            $approvedStatus = $rfqReview->checkRfqStatus($reference_id, $module, $username);

            if ($approvedStatus == 11) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already approved"]);
                exit();
            }

            if ($approvedStatus == 7 || $approvedStatus == 8) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ must be verified before approval"]);
                exit();
            }


            // 7 initial, 8 submitted, 9 verified, 10 send-back, 12 rejected, 13 blocked, 14 suspended

            if (in_array($approvedStatus, [12, 13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "Cannot approve RFQ that is rejected, blocked, or suspended"]);
                exit();
            }

            if ($approvedStatus == 10) {
                http_response_code(400);
                echo json_encode(["error" => "Cannot approve RFQ that is sent back for corrections"]);
                exit();
            }

            if ($approvedStatus != 9) {
                http_response_code(400);
                echo json_encode(["error" => "Only verified RFQs can be approved"]);
                exit();
            }

            $result = $rfqReview->approveRfq($reference_id, $expiry_date, $module, $username);

            if ($result) {
                http_response_code(200);
                echo json_encode(["message" => "RFQ approved successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to approve RFQ"]);
            }
            break;

        case 'reject':

            $rejectedStatus = $rfqReview->checkRfqStatus($reference_id, $module, $username);

            if ($rejectedStatus == 12) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already rejected"]);
                exit();
            }

            if ($rejectedStatus == 11) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is already approved and cannot be rejected"]);
                exit();
            }

            if (in_array($rejectedStatus, [13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is blocked / suspended and cannot be rejected"]);
                exit();
            }

            if ($rejectedStatus == 7) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ must be submitted before it can be rejected"]);
                exit();
            }

            if ($rejectedStatus == 10) {
                http_response_code(400);
                echo json_encode(["error" => "RFQ is sent back for corrections and cannot be rejected until resubmitted"]);
                exit();
            }

            $result = $rfqReview->rejectRfq($reference_id, $module, $username);

            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "RFQ rejected successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to reject RFQ"]);
            }
            break;

        case 'block':

            // block only approved vendors
            $blockStatus = $rfqReview->checkVendorStatus($vendor_code, $module, $username);
            if (!in_array($blockStatus, [11, 14, 15])) {
                http_response_code(400);
                echo json_encode(["error" => "Only approved, suspended, or expired vendors can be blocked"]);
                exit();
            }
            $result = $rfqReview->blockVendor($vendor_code, $module, $username);
            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "Vendor blocked successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to block vendor"]);
            }

            break;

        case 'suspend':

            // suspend only approved vendors
            $status = $rfqReview->checkVendorStatus($vendor_code, $module, $username);

            if ($status == 14) {
                http_response_code(400);
                echo json_encode(["error" => "Vendor is already blocked and cannot be suspended. Activate the vendor before suspending"]);
                exit();
            }

            if (!in_array($status, [11, 15])) {
                http_response_code(400);
                echo json_encode(["error" => "Only approved or expired vendors can be suspended"]);
                exit();
            }

            $result = $rfqReview->suspendVendor($vendor_code, $module, $username);
            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "Vendor suspended successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to suspend vendor"]);
            }


            break;

        case 'activate':

            // activate only blocked or suspended vendors
            $status = $rfqReview->checkVendorStatus($vendor_code, $module, $username);


            if (!in_array($status, [13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "Only blocked or suspended vendors can be activated"]);
                exit();
            }

            if ($status == 11) {
                http_response_code(400);
                echo json_encode(["error" => "Vendor is already approved and cannot be activated"]);
                exit();
            }

            if ($status == 15) {
                http_response_code(400);
                echo json_encode(["error" => "Vendor is expired and cannot be activated. Please reinitiate the vendor"]);
                exit();
            }

            $result = $rfqReview->activateVendor($vendor_code, $module, $username);
            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "Vendor activated successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to activate vendor"]);
            }

            break;

        case 'reinitiate':

            // only vendors with vendor code can be reinitiated
            $reinitiateStatus = $rfqReview->checkVendorStatus($vendor_code, $module, $username);
            $hasVendorCode = $rfqReview->checkVendorCodeExists($vendor_code, $module, $username);
            if (!$hasVendorCode) {
                http_response_code(400);
                echo json_encode(["error" => "Vendor code is required for reinitiation"]);
                exit();
            }

            // only approved vendors or expired vendors or rejected vendors can be reinitiated
            if (!in_array($reinitiateStatus, [11, 12, 15])) {
                http_response_code(400);
                echo json_encode(["error" => "Only approved or rejected RFQs or expired RFQs can be reinitiated"]);
                exit();
            }

            // prevent reinitiation if vendor is blocked or suspended
            if(in_array($reinitiateStatus, [13, 14])) {
                http_response_code(400);
                echo json_encode(["error" => "Blocked or suspended vendors cannot be reinitiated. Please activate the vendor first."]);
                exit();
            }

            // prevent duplicate reinitiation
            $duplicate = $rfqReview->checkDuplicateReinitiation($vendor_code, $module, $username);
            if ($duplicate) {
                http_response_code(400);
                echo json_encode(["error" => "Vendor already reinitiated."]);
                exit();
            }

            // allow reinitiation before 60 days of expiry
            $expiryDate = $rfqReview->getExpiryDateByVendorCode($vendor_code, $module, $username);
            $currentDate = new DateTime();
            $expiryDateObj = new DateTime($expiryDate);
            $interval = $currentDate->diff($expiryDateObj);
            $daysToExpiry = (int)$interval->format('%r%a');

            if ($daysToExpiry > 60) {
                http_response_code(400);
                echo json_encode(["error" => "Reinitiation is only allowed within 60 days before expiry"]);
                exit();
            }

            $result = $rfqReview->reinitiateVendor($vendor_code, $module, $username);

            if ($result) {
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "Vendor reinitiated successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to reinitiate vendor"]);
            }

            break;

        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid action. Valid actions are: send-back, verify, approve, reject"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server error: " . $e->getMessage()]);
    exit();
}
