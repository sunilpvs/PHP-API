<?php

// for cron job to expire RFQs past their expiry date
require_once __DIR__ . '/../../DbController.php';
require_once __DIR__ . '/../../vms/Rfq.php';
require_once __DIR__ . '/../../../classes/Logger.php';
require_once __DIR__ . '/../GraphAutoMailer.php';

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
    $config = parse_ini_file(__DIR__ . '/../../../app.ini');
    $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);

    $logger = new Logger($debugMode, __DIR__ . '/../../../logs');
    $expiredRfqs = $rfqObj->getExpiredRfqsByDate(); // gives an array of expired RFQs

    foreach ($expiredRfqs as $rfq) {
        $rfqId = $rfq['id'];

        // get email of the vendor associated with this RFQ
        $query = 'SELECT email FROM vms_rfqs where id = ?';
        $logger->logQuery($query, [$rfqId], 'cron');
        $rfqVendorEmailResult = $db->runSingle($query, [$rfqId]);
        $rfqVendorEmail = $rfqVendorEmailResult['email'] ?? null;

        // update RFQ status to 'expired' (assuming status code 15 represents 'expired')
        $query = 'UPDATE vms_rfqs SET status = 15 WHERE id = ?';
        $logger->logQuery($query, [$rfqId], 'cron');
        $rfqExpiredStatus = $db->update($query, [$rfqId]);

        // update vms_vendor status to 'expired' for all vendors associated with this RFQ
        $query = 'UPDATE vms_vendor set vendor_status = 15 WHERE active_rfq = ?';
        $logger->logQuery($query, [$rfqId], 'cron');
        $vendorExpiredStatus = $db->update($query, [$rfqId]);

        // update is_active = false in vms_rfq_reviews for this RFQ
        $query = 'UPDATE vms_rfqs set is_active= 0 WHERE id = ?';
        $logger->logQuery($query, [$rfqId], 'cron');
        $rfqReviewInactive = $db->update($query, [$rfqId]);

        $vmsAdminEmails = $rfqObj->getVmsAdminEmails('cron', 'system');

        $mailer = new AutoMail();
        $keyValueData = [
            "Message" => "Your Vendor ID - " . $rfq['vendor_code'] . " has expired as of " . date('Y-m-d') . ". Please contact the VMS Team for further details.",
            "Reference ID" => $rfq['reference_id'],
            // "Comments" => $commentText
        ];

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'Vendor Expired - Reference ID: ' . $rfq['reference_id'],
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$rfqVendorEmail],
            bcc: $vmsAdminEmails,
        );
    }
    
} catch (Exception $e) {
    $logger->log("Error in ExpiryCron: " . $e->getMessage(), 'ERROR', 'cron');

    // Additionally, write to a separate log file if logger is not available
    file_put_contents(
        __DIR__ . '/ExpiryCronError.log',
        "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
}