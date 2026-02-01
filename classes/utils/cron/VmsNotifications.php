<?php

// for cron job to expire RFQs past their expiry date
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
    $entityOb = new Entity();
    $rfqObj = new Rfq();
    $config = parse_ini_file(__DIR__ . '/../../../app.ini');
    $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);

    $logger = new Logger($debugMode, __DIR__ . '/../../../logs');

    // Loop through each notification interval
    $daysArray = [60, 45, 30, 15, 7, 3, 1];

    foreach ($daysArray as $days) {
        $rfqsToNotify = $rfqObj->getRfqsByDays($days); // get RFQs expiring in $days

        if (empty($rfqsToNotify)) {
            $logger->log("No RFQs found expiring in $days days", 'INFO', 'cron');
            continue;
        }

        foreach ($rfqsToNotify as $rfq) {
            $rfqId = $rfq['id'];
            $vendorId = $rfq['vendor_id'];
            $expiryDate = $rfq['expiry_date'] ?? date('Y-m-d', strtotime("+$days days"));

            // get vendor details
            $activeRfq = $rfqObj->getActiveRfqForVendor($vendorId, 'cron');
            if ($activeRfq) {
                $vendorEmail = $activeRfq['email'];
                $vendorCode = $activeRfq['vendor_code'];

                $vmsAdminEmails = $rfqObj->getVmsAdminEmails('cron', 'system');

                $entityName = $rfqObj->getEntityNameByReferenceId($rfq['reference_id'], 'cron', 'system');
                $RfqEntityId = $rfqObj->getEntityIdByReferenceId($rfq['reference_id'], 'cron', 'system');
                $salutationName = $entityOb->getSalutationNameByEntityId($RfqEntityId, 'cron', 'system');

                $mailer = new AutoMail();
                $keyValueData = [
                    "Message" => "Your Vendor ID - " . $vendorCode . " under " . $entityName . " is set to expire in " . $days . " days on " . $expiryDate . ". Please take necessary actions to renew your Vendor ID.",
                    "Reference ID" => $rfq['reference_id'],
                ];



                // Prepare email data and send email using the mailer
                $emailSent = $mailer->sendInfoEmail(
                    subject: 'Vendor Expiry Notification - Reference ID: ' . $rfq['reference_id'],
                    greetings: 'Dear Vendor,',
                    name: $salutationName ? $salutationName : 'Shrichandra Group Team',
                    keyValueArray: $keyValueData,
                    to: [$vendorEmail],
                    bcc: $vmsAdminEmails,
                );

                if ($emailSent) {
                    // Log success
                    $logger->log("Expiry notification sent for RFQ ID $rfqId to $vendorEmail (expires in $days days)", 'INFO', 'cron');
                } else {
                    // Log failure
                    $logger->log("Failed to send expiry notification for RFQ ID $rfqId to $vendorEmail", 'ERROR', 'cron');
                }
            } else {
                $logger->log("No active RFQ found for vendor ID $vendorId", 'WARNING', 'cron');
            }
        }
    }
} catch (Exception $e) {
    $logger->log("Error in VmsNotifications Cron: " . $e->getMessage(), 'ERROR', 'cron');

    // Additionally, write to a separate log file if logger is not available
    file_put_contents(
        __DIR__ . '/VmsNotificationsError.log',
        "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
}
