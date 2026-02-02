<?php

// for cron job to activate RFQs past their expiry date
require_once __DIR__ . '/../../DbController.php';
require_once __DIR__ . '/../../vms/Rfq.php';
require_once __DIR__ . '/../../../classes/Logger.php';
require_once __DIR__ . '/../GraphAutoMailer.php';
require_once __DIR__ . '/../../../classes/admin/Entity.php';

// Protect against unauthorized execution
$configPath = __DIR__ . '/../../../app.ini';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Config file not found']);
    exit;
}

$config = parse_ini_file($configPath, true);
if (!$config) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Failed to parse config']);
    exit;
}

$cronSecret = trim($config['cron']['SECRET'] ?? '');
$isCli = (php_sapi_name() === 'cli');
$requestSecret = trim($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));

if (!$isCli && (empty($cronSecret) || !hash_equals($cronSecret, $requestSecret))) {
    http_response_code(403);
    echo json_encode([
        'status' => 403,
        'message' => 'Forbidden - Missing cron secret key',
    ]);
    exit;
}

try {
    $db = new DbController();
    $rfqObj = new Rfq();
    $entityOb = new Entity();
    $config = parse_ini_file(__DIR__ . '/../../../app.ini');
    $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);

    $logger = new Logger($debugMode, __DIR__ . '/../../../logs');
    $expiredRfqs = $rfqObj->getExpiredRfqs($db); // gives an array of expired RFQs

    foreach ($expiredRfqs as $rfq) {
        $rfqId = $rfq['id'];
        $vendorId = $rfq['vendor_id'];

        // check if there are any other active RFQs for the same vendor
        $hasOtherActiveRfqs = $rfqObj->hasOtherActiveRfqs($vendorId, 'cron', );

        // if there are no active rfqs, exit the loop
        if(!$hasOtherActiveRfqs) {
            $logger->log("Skipping expiry for RFQ ID $rfqId as there are no other active RFQs for vendor with ID $vendorId", [], 'cron');
            continue;
        }

        // get the active rfq details
        $activeRfq = $rfqObj->getActiveRfqForVendor($vendorId, 'cron', );
        $activeRfqId = $activeRfq['id'] ?? null;
        $vendorCode = $activeRfq['vendor_code'] ?? null;
        $activeRfqVendorEmail = $activeRfq['email'] ?? null;
        
        // update is_active = true in vms_rfqs for the active RFQ 
        $query = 'UPDATE vms_rfqs set is_active= 1 WHERE id = ?';
        $logger->logQuery($query, [$activeRfqId], 'cron');
        $rfqActivated = $db->update($query, [$activeRfqId]);

        // update vms_vendor status to 'active (approved = 11)', and set active_rfq to the active RFQ id for all vendors associated with this user
        $query = 'UPDATE vms_vendor set vendor_status = 11, active_rfq = ? WHERE vendor_code = ?';
        $logger->logQuery($query, [$activeRfqId, $vendorCode], 'cron');
        $vendorActivated = $db->update($query, [$activeRfqId, $vendorCode]);

        if(!$rfqActivated || !$vendorActivated) {
            $logger->log("Failed to reactivate RFQ ID $activeRfqId or Vendor Code $vendorCode for vendor ID $vendorId", [], 'cron');
            continue;
        }

        $entityName = $rfqObj->getEntityNameByVendorCode($vendorCode, 'cron', 'system');
        $RfqEntityId = $rfqObj->getEntityIdByVendorCode($vendorCode, 'cron', 'system');
        $salutationName = $entityOb->getSalutationNameByEntityId($RfqEntityId, 'cron', 'system');

        $mailer = new AutoMail();
        $keyValueData = [
            "Message" => "Your Vendor ID - " . $vendorCode . " under " . $entityName . " has been activated as of " . date('Y-m-d') . ". Your new RFQ details are as follows. 
                        Please contact the VMS Team for further details.",
            "New Reference ID" => $activeRfq['reference_id'],
            "Vendor Code" => $vendorCode,
        ];

        $vmsAdminEmails = $rfqObj->getVmsAdminEmails('cron', 'system');

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'Vendor Activated - Reference ID: ' . $activeRfq['reference_id'],
            greetings: 'Dear Vendor,',
            name: $salutationName ? $salutationName : 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$activeRfqVendorEmail],
            bcc: $vmsAdminEmails,
        );

        if(!$emailSent) {
            $logger->log("Failed to send reactivation email to $activeRfqVendorEmail for RFQ ID $activeRfqId", [], 'cron');
            continue;
        }
        $logger->log("Successfully reactivated RFQ ID $activeRfqId and sent email to $activeRfqVendorEmail", [], 'cron');

    }

    

    
} catch (Exception $e) {
    // Log the exception message
    if (isset($logger)) {
        $logger->log("Exception in ReactivateCron: " . $e->getMessage(), [], 'cron');
    } else {
        error_log("Exception in ReactivateCron: " . $e->getMessage());
    }

    // Additionally, write to a separate log file if logger is not available
    file_put_contents(
        __DIR__ . '/ReactivateCronError.log',
        "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
}