<?php

// for cron job to activate RFQs past their expiry date
require_once __DIR__ . '/../../DbController.php';
require_once __DIR__ . '/../../vms/Rfq.php';
require_once __DIR__ . '/../../../classes/Logger.php';
require_once __DIR__ . '/../GraphAutoMailer.php';


try {
    $db = new DbController();
    $rfqObj = new Rfq();
    $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
    $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);

    $logger = new Logger(false, $_SERVER['DOCUMENT_ROOT'] . '/logs');
    $expiredRfqs = $rfqObj->getExpiredRfqs($db); // gives an array of expired RFQs

    foreach ($expiredRfqs as $rfq) {
        $rfqId = $rfq['id'];
        $vendorId = $rfq['vendor_id'];

        // check if there are any other active RFQs for the same user
        $hasOtherActiveRfqs = $rfqObj->hasOtherActiveRfqs($vendorId, 'cron', );

        // if there are no active rfqs, exit the loop
        if(!$hasOtherActiveRfqs) {
            $logger->log("Skipping expiry for RFQ ID $rfqId as there are no other active RFQs for user ID $userId", [], 'cron');
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
            $logger->log("Failed to reactivate RFQ ID $activeRfqId or Vendor Code $vendorCode for user ID $userId", [], 'cron');
            continue;
        }

        $mailer = new AutoMail();
        $keyValueData = [
            "Message" => "Your Vendor ID has been activated as of " . date('Y-m-d') . ". Your new RFQ details are as follows. 
                        Please contact the VMS Team for further details.",
            "New Reference ID" => $activeRfq['reference_id'],
            "Vendor Code" => $vendorCode,
        ];

        $vmsAdminEmails = $rfqObj->getVmsAdminEmails('cron', 'system');

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'Vendor Activated - Reference ID: ' . $activeRfq['reference_id'],
            greetings: 'Dear Vendor,',
            name: 'Pvs Consultancy Services',
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
}
