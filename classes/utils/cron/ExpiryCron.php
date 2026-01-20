<?php

// for cron job to expire RFQs past their expiry date
require_once __DIR__ . '/../../DbController.php';
require_once __DIR__ . '/../../vms/Rfq.php';
require_once __DIR__ . '/../../../classes/Logger.php';
require_once __DIR__ . '/../GraphAutoMailer.php';


try {
    $db = new DbController();
    $rfqObj = new Rfq();
    $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
    $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);

    $logger = new Logger($debugMode, $_SERVER['DOCUMENT_ROOT'] . '/logs');
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
            "Message" => "Your Vendor ID has expired as of " . date('Y-m-d') . ". Please contact the VMS Team for further details.",
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
        //echo "RFQ ID $rfqId expired. Email sent to $rfqVendorEmail: " . ($emailSent ? 'Success' : 'Failed') . "\n";
    }
    
} catch (Exception $e) {
    $logger->log("Error in ExpiryCron: " . $e->getMessage(), 'ERROR', 'cron');
}