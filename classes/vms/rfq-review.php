<?php

use Dotenv\Dotenv;

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Rfq.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/CounterPartyInfo.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Comments.php';

class RfqReview
{


    private $conn;
    private $logger;
    private $rfqData;
    private $counterPartyInfo;
    private $commentOb;
    private $vendorLoginUrl;
    private $vmsPortalUrl;
    private $env;
    private $dotenv;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->rfqData = new Rfq();
        $this->counterPartyInfo = new CounterPartyInfo();
        $this->commentOb = new Comments();
        $this->logger = new Logger($debugMode, $logDir);

        // load environment variables
        $this->env = getenv('APP_ENV') ?: 'local';
        if ($this->env === 'production') {
            $this->dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env.prod');
        } else {
            $this->dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env');
        }
        $this->dotenv->load();

        $this->vendorLoginUrl = $_ENV['VENDOR_LOGIN_URL'] ?? '';
        $this->vmsPortalUrl = $_ENV['VMS_PORTAL_URL'] ?? '';
    }

    // send back rfq for corrections with comments
    public function sendBackRfq($reference_id, $module, $username)
    {
        $query = "UPDATE vms_rfqs SET 
                    status = ?
                    WHERE reference_id = ?";

        $params = [
            10,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'RFQ sent back for corrections';
        $updatedRfqId =  $this->conn->update($query, $params, $logMessage);

        // update vms_vendor status to sent back for corrections
        // $query = "UPDATE vms_vendor SET 
        //             vendor_status = ?
        //             WHERE reference_id = ?";

        // $params = [
        //     10,
        //     $reference_id
        // ];

        // $this->logger->logQuery($query, $params, 'classes', $module, $username);
        // $logMessage = 'Vendor status updated to sent back for corrections';
        // $updatedVendorId =  $this->conn->update($query, $params, $logMessage);

        // get latest comments for the reference id
        $comments = $this->commentOb->getLatestCommentsByReferenceId($reference_id, $module, $username);

        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        $commentText = "";

        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $commentText .= $comment['step_name'] . ":\n";
                $lines = preg_split('/\r\n|\r|\n/', trim($comment['comment_text']));
                foreach ($lines as $line) {
                    $commentText .= $line . "\n";
                }
                $commentText .= "\n"; // Add a blank line between steps
            }
        }

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        $mailer = new AutoMail();



        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your RFQ with Reference ID: $reference_id has been sent back for corrections. 
                            Please review the comments and make the necessary changes.",
            "Reference ID" => $reference_id,
            "Comments" => $commentText
        ];


        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'Returned for Revisions - RFQ Reference ID: ' . $reference_id,
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [], // remove this in production
            bcc: $vmsAdminEmails,
        );


        // Update email_sent status in comments table
        if ($emailSent) {
            $updateEmailSentStatus = $this->commentOb->updateEmailSentStatus($reference_id, 1, $module, $username);
        }

        if ($updatedRfqId && $emailSent && $updateEmailSentStatus) {
            return true;
        } else {
            return false;
        }
    }

    // verify rfq and forward to approval
    public function verifyRfq($reference_id, $expiry_date, $module, $username)
    {

        // Update RFQ status to verified - under review
        $query = "UPDATE vms_rfqs SET 
                    status = ?
                    WHERE reference_id = ?";

        $params = [
            9,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'RFQ verified and forwarded for approval';
        $rfqStatusUpdatedId =  $this->conn->update($query, $params, $logMessage);

        // set expiry date in vms_rfqs table after verification
        $query = "UPDATE vms_rfqs SET 
                    expiry_date = ?
                    WHERE reference_id = ?";
        $params = [
            $expiry_date,
            $reference_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $expiryDateUpdatedId = $this->conn->update($query, $params, 'Vendor expiry date set');

        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        $mailer = new AutoMail();

        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your RFQ with Reference ID: $reference_id has been verified and forwarded for approval. 
                            You will be notified once the approval process is complete.",
            "Reference ID" => $reference_id,
        ];

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'RFQ Verification Completed – Awaiting Approval',
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [], // remove this in production
            bcc: $vmsAdminEmails,
            attachments: []
        );
        if ($rfqStatusUpdatedId && $emailSent && $expiryDateUpdatedId) {
            return true;
        } else {
            return false;
        }
    }



    // approve rfq and generate vendor id if new vendor else activate existing vendor
    public function approveRfq($reference_id, $expiry_date, $module, $username)
    {
        $existingVendor = $this->rfqData->isExistingVendor($reference_id, $module, $username);
        if ($existingVendor) {
            // activate existing vendor
            return $this->activateVendorByReferenceId($reference_id, $expiry_date, $module, $username);
        } else {
            // approve new vendor
            return $this->approveNewRfq($reference_id, $expiry_date, $module, $username);
        }
    }


    // activate existing vendor
    public function activateVendorByReferenceId($reference_id, $expiry_date, $module, $username)
    {
        // set expiry date after approval 
        $query = "UPDATE vms_rfqs SET 
                    expiry_date = ?
                    WHERE reference_id = ?";
        $params = [
            $expiry_date,
            $reference_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $expiryDateUpdatedId = $this->conn->update($query, $params, 'Vendor expiry date set');

        // get vendor id from reference id
        $vendor_id = $this->rfqData->getVendorIdByReferenceId($reference_id, $module, $username);
        if (!$vendor_id) {
            return false; // Vendor ID retrieval failed
        }

        // set vendor code in rfq table
        $query = "UPDATE vms_rfqs SET 
                    status = ? ,
                    vendor_id = ?,
                    expiry_date = ?,
                    is_active = ?
                    WHERE reference_id = ?";
        $params = [
            11,
            $vendor_id,
            $expiry_date,
            1,
            $reference_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $rfqVendorCodeUpdatedId = $this->conn->update($query, $params, 'RFQ vendor code updated');

        // set vendor active_rfq id in vendor table
        $activeRfqId = $this->rfqData->getRfqIdByReferenceId($reference_id, $module, $username);
        $query = "UPDATE vms_vendor SET 
                    active_rfq = ?,
                    vendor_status = ?
                    WHERE id = ?";
        $params = [
            $activeRfqId,
            11,
            $vendor_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorStatusUpdatedId = $this->conn->update($query, $params, 'Vendor activated');

        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        $vendor_code = $this->rfqData->getVendorCodeByVendorId($vendor_id, $module, $username);

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');


        $mailer = new AutoMail();
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your RFQ with Reference ID: $reference_id has been approved. 
                            Your Vendor ID is: " . $vendor_code . ". You can now proceed with further transactions.",
            "Reference ID" => $reference_id,
            "Vendor Code" => $vendor_code
        ];
        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'RFQ Approval and Vendor ID Issued',
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [], // remove this in production
            bcc: $vmsAdminEmails
        );

        if (
            $rfqVendorCodeUpdatedId &&
            $vendorStatusUpdatedId && $expiryDateUpdatedId && $emailSent
        ) {
            return true;
        } else {
            return false;
        }
    }


    // approve new rfq
    public function approveNewRfq($reference_id, $expiry_date, $module, $username)
    {
        // set expiry date after approval 
        $query = "UPDATE vms_rfqs SET 
                    expiry_date = ?
                    WHERE reference_id = ?";
        $params = [
            $expiry_date,
            $reference_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $expiryDateUpdatedId = $this->conn->update($query, $params, 'Vendor expiry date set');

        // generate vendor id 
        $vendorCode = $this->counterPartyInfo->generateVendorCode($reference_id, $module, $username);
        if (!$vendorCode) {
            return false; // Vendor ID generation failed
        }

        $entity_id = $this->rfqData->getEntityIdByReferenceId($reference_id, $module, $username);

        // insert a new record in vms_vendor with vendor code and status approved
        $query = "INSERT INTO vms_vendor (vendor_code, vendor_status) VALUES (?, ?)";
        $params = [
            $vendorCode,
            11  // approved
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorCodeAndStatusUpdatedId = $this->conn->update($query, $params, 'Vendor approved and Vendor ID generated');

        // get vendor id from vendor code
        $vendor_id = $this->counterPartyInfo->getVendorIdByVendorCode($vendorCode, $module, $username);

        // set vendor code in rfq table
        $query = "UPDATE vms_rfqs SET 
                    status = ? ,
                    vendor_id = ?,
                    expiry_date = ?,
                    entity_id = ?,
                    is_active = ?
                    WHERE reference_id = ?";
        $params = [
            11,
            $vendor_id,
            $expiry_date,
            $entity_id,
            1,
            $reference_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $rfqVendorCodeUpdatedId = $this->conn->update($query, $params, 'RFQ vendor code updated');

        $activeRfqId = $this->rfqData->getRfqIdByReferenceId($reference_id, $module, $username);

        // set vendor active_rfq id in vendor table
        $query = "UPDATE vms_vendor SET 
                    active_rfq = ?,
                    vendor_status = ?
                    WHERE id = ?";
        $params = [
            $activeRfqId,
            11,
            $vendor_id
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorStatusUpdatedId = $this->conn->update($query, $params, 'Vendor expiry date set');

        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        $mailer = new AutoMail();
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Congratulations! Your RFQ with Reference ID: $reference_id has been approved. 
                            Your Vendor ID is: $vendorCode. You can now proceed with further transactions.",
            "Reference ID" => $reference_id,
            "Vendor Code" => $vendorCode
        ];

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'RFQ Approval and Vendor ID Issued',
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );

        if (
            $vendorCodeAndStatusUpdatedId && $rfqVendorCodeUpdatedId &&
            $vendorStatusUpdatedId && $expiryDateUpdatedId && $emailSent
        ) {
            return true;
        } else {
            return false;
        }
    }


    // 

    // reject rfq
    public function rejectRfq($reference_id, $module, $username)
    {

        // update status to rejected in rfq table
        $query = "UPDATE vms_rfqs SET 
                    status = ?,
                    is_active = ?
                    WHERE reference_id = ?";

        $params = [
            12,
            0,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'RFQ rejected';
        $rfqStatusUpdatedId =  $this->conn->update($query, $params, $logMessage);

        // check if vendor exists for the reference id
        $query = "SELECT vendor_id FROM vms_rfqs WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        $vendor_id = $result['vendor_id'] ?? null;

        if ($vendor_id) {
            // update vendor status to rejected
            $query = "UPDATE vms_vendor SET 
                        active_rfq = ?,
                        vendor_status = ?
                        WHERE id = ?";

            $params = [
                null,
                15, // but show reinitiate button 
                $vendor_id
            ];

            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = 'Vendor status updated to rejected';
            $vendorStatusUpdatedId =  $this->conn->update($query, $params, $logMessage);
        } else {
            $vendorStatusUpdatedId = true; // No vendor to update, consider as successful
        }

        // get vendor email from reference id
        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        $mailer = new AutoMail();

        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "We regret to inform you that your RFQ with Reference ID: $reference_id has been rejected. 
                            For further details, please contact our support team.",
            "Reference ID" => $reference_id,
        ];

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: "RFQ - $reference_id Rejected",
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );

        if ($vendorStatusUpdatedId && $rfqStatusUpdatedId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }

    // submit rfq for review
    public function submitRfqForReview($reference_id, $module, $username)
    {
        // declare emailSent and submissionStatusChanged variables
        $emailSent = false;
        $submissionStatusChanged = false;

        // get vendor email from reference id
        $vendorEmail = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        // check resubmission 
        $isResubmission = $this->rfqData->isFormSubmittedPreviously($reference_id, $module, $username);
        if ($isResubmission) {
            // log resubmission event
            $this->logger->log("Resubmission detected for Reference ID: $reference_id by user: $username", 'classes', $module, $username);

            // update resubmission count
            $this->rfqData->incrementSubmissionCount($reference_id, $module, $username);

            // update rfq and vendor status to 'Submitted' (status id 8)
            $submissionStatusChanged = $this->submitRfqStatusChange($reference_id, $module, $username);

            $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

            // send mail notification to vendor
            $mailer = new AutoMail();


            $keyValueData = [
                "Message" => "Dear Vendor, Your data has been successfully resubmitted with Reference ID: $reference_id. 
                            You will be notified once your application is reviewed. You can also check your status by logging
                            into the Vendor Portal with the credentials shared earlier. ",
                "Vendor Portal Link" => $this->vendorLoginUrl,
            ];

            $emailSent = $mailer->sendInfoEmail(
                subject: 'Vendor Registration Resubmitted - Reference ID: ' . $reference_id,
                greetings: 'Dear Vendor,',
                name: 'Shrichandra Group Team',
                keyValueArray: $keyValueData,
                to: [$vendorEmail],
                cc: [],
                bcc: $vmsAdminEmails,
                attachments: []
            );

            return $emailSent && $submissionStatusChanged;
        } else {
            // first time submission

            // log first time submission event
            $this->logger->log("First time submission for Reference ID: $reference_id by user: $username", 'classes', $module, $username);
            // update rfq and vendor status to 'Submitted' (status id 8)
            $submissionStatusChanged = $this->submitRfqStatusChange($reference_id, $module, $username);
            // send mail notification to vendor
            $mailer = new AutoMail();
            $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

            // Send notification email to vendor
            // Create the key-value array for the email body to vendor
            $keyValueData = [
                "Message" => "Dear Vendor, Your data has been successfully submitted with Reference ID: $reference_id. 
                            You will be notified once your application is reviewed. You can also check your status by logging
                            into the Vendor Portal with the credentials shared earlier. ",
                "Vendor Portal Link" => $this->vendorLoginUrl,
            ];

            $emailSent = $mailer->sendInfoEmail(
                subject: 'Vendor Registration Submitted - Reference ID: ' . $reference_id,
                greetings: 'Dear Vendor,',
                name: 'Shrichandra Group Team',
                keyValueArray: $keyValueData,
                to: [$vendorEmail],
                cc: [],
                bcc: $vmsAdminEmails,
                attachments: []
            );

            return $emailSent && $submissionStatusChanged;
        }
    }



    // block vendor
    public function blockVendor($vendor_code, $module, $username)
    {
        // update vendor status to blocked
        $query = "UPDATE vms_vendor SET 
                    vendor_status = ?
                    -- active_rfq = ?
                    WHERE vendor_code = ?";

        $params = [
            13,
            // null,
            $vendor_code
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorStatusUpdatedId =  $this->conn->update($query, $params, 'Vendor blocked');

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        // send a mail to vendor about blocking
        $mailer = new AutoMail();
        // get mail id from vendor code
        $vendorEmail = $this->rfqData->getEmailByVendorCode($vendor_code, $module, $username);
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your vendor account with Vendor Code: $vendor_code has been blocked. 
                            For further details, please contact our support team.",
            "Vendor Code" => $vendor_code,
        ];
        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: "Vendor Account - $vendor_code Blocked",
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team', 
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );


        if ($vendorStatusUpdatedId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }


    // suspend vendor
    public function suspendVendor($vendor_code, $module, $username)
    {
        // update vendor status to suspended
        $query = "UPDATE vms_vendor SET 
                    vendor_status = ?
                    -- active_rfq = ?
                    WHERE vendor_code = ?";

        $params = [
            14,
            // null,
            $vendor_code
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorStatusUpdatedId =  $this->conn->update($query, $params, 'Vendor suspended');

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        // send a mail to vendor about suspension
        $mailer = new AutoMail();
        // get mail id from vendor code
        $vendorEmail = $this->rfqData->getEmailByVendorCode($vendor_code, $module, $username);
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your vendor account with Vendor Code: $vendor_code has been suspended. 
                            For further details, please contact our support team.",
            "Vendor Code" => $vendor_code,
        ];
        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
        subject: "Vendor Account - $vendor_code Suspended",
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',    
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );


        if ($vendorStatusUpdatedId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }


    public function activateVendor($vendor_code, $module, $username)
    {
        // update vendor status to active
        $query = "UPDATE vms_vendor SET 
                    vendor_status = ?
                    WHERE vendor_code = ?";

        $params = [
            11,
            $vendor_code
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorStatusUpdatedId =  $this->conn->update($query, $params, 'Vendor activated');

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        // send a mail to vendor about activation
        $mailer = new AutoMail();
        // get mail id from vendor code
        $vendorEmail = $this->rfqData->getEmailByVendorCode($vendor_code, $module, $username);
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your vendor account with Vendor Code: $vendor_code has been activated. 
                            You can now proceed with further transactions.",
            "Vendor Code" => $vendor_code,
        ];
        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: "Vendor Account - $vendor_code Activated",
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendorEmail],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );


        if ($vendorStatusUpdatedId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }


    // reinitiate vendor
    public function reinitiateVendor($vendor_code, $module, $username)
    {

        // get active reference id from vms_vendor table via vendor code
        $query = "SELECT r.reference_id FROM vms_vendor v
                    JOIN vms_rfqs r ON v.active_rfq = r.id
                    WHERE v.vendor_code = ?";
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_code]);
        $reference_id = $result['reference_id'] ?? null;
        if (!$reference_id) {
            return false; // No active reference ID found
        }

        // no affect to vendor status 
        // $query = "UPDATE vms_vendor SET 
        //             vendor_status = ?,
        //             active_rfq = ?
        //             WHERE vendor_code = ?";

        // $params = [
        //     15, // expired but shown as reinitiated in UI
        //     null,
        //     $vendor_code
        // ];
        // $this->logger->logQuery($query, $params, 'classes', $module, $username);
        // $vendorStatusUpdatedId =  $this->conn->update($query, $params, 'Vendor reinitiated');
        // if (!$vendorStatusUpdatedId) {
        //     return false; // Vendor status update failed
        // }

        // no affect to previous rfq - it will expire automatically based on expiry date
        // $query = "UPDATE vms_rfqs SET 
        //             is_active = ?,
        //             status = ?
        //             WHERE reference_id = ?";

        // $params = [
        //     0,
        //     15,
        //     $reference_id
        // ];

        // $this->logger->logQuery($query, $params, 'classes', $module, $username);
        // $logMessage = 'Vendor reinitiated';
        // $vendorStatusUpdatedId =  $this->conn->update($query, $params, $logMessage);


        // create new rfq with same details as previous rfq for reinitiation
        $newReferenceId = $this->rfqData->generateReferenceId();
        $query = "INSERT INTO vms_rfqs (
                reference_id,
                vendor_id,
                vendor_name,
                contact_name,
                email,
                mobile,
                entity_id,
                status,
                user_id,
                created_by
            )
            SELECT
                ?,
                vendor_id,
                vendor_name,
                contact_name,
                email,
                mobile,
                entity_id,
                7,
                user_id,
                ?
            FROM vms_rfqs
            WHERE reference_id = ?";

        $params = [$newReferenceId, $username, $reference_id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $newRfqInsertionId = $this->conn->insert($query, $params, 'New RFQ created');

        // copy counterparty details to new reference id
        $query = "INSERT INTO vms_counterparty (
                reference_id,
                full_registered_name,
                business_entity_type,
                reg_number,
                tan_number,
                trading_name,
                country_type,
                country_id,
                state_id,
                country_text,
                state_text,
                telephone,
                registered_address,
                business_address,
                contact_person_title,
                contact_person_name,
                contact_person_mobile,
                contact_person_email,
                accounts_person_title,
                accounts_person_name,
                accounts_person_contact_no,
                accounts_person_email
            )
            SELECT
                ?,
                full_registered_name,
                business_entity_type,
                reg_number,
                tan_number,
                trading_name,
                country_type,
                country_id,
                state_id,
                country_text,
                state_text,
                telephone,
                registered_address,
                business_address,
                contact_person_title,
                contact_person_name,
                contact_person_mobile,
                contact_person_email,
                accounts_person_title,
                accounts_person_name,
                accounts_person_contact_no,
                accounts_person_email
            FROM vms_counterparty
            WHERE reference_id = ?";

        $params = [$newReferenceId, $reference_id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $newCounterpartyInsertionId = $this->conn->insert($query, $params, 'Counterparty details copied to new RFQ');

        // send notification email to vendor
        $mailer = new AutoMail();
        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Dear Vendor, Your registration has been reinitiated. 
                        Please complete the submission process using the new Reference ID: $newReferenceId. 
                        You can log in to the Vendor Portal using your existing credentials.",
            "New Reference ID" => $newReferenceId,
            "Vendor Portal Link" => $this->vendorLoginUrl,
        ];

        $vmsAdminEmails = $this->rfqData->getVmsAdminEmails('cron', 'system');

        $vendor_email = $this->rfqData->getEmailByReferenceId($reference_id, $module, $username);

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendInfoEmail(
            subject: 'Vendor Registration Reinitiated - New Reference ID: ' . $newReferenceId,
            greetings: 'Dear Vendor,',
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$vendor_email],
            cc: [],
            bcc: $vmsAdminEmails,
            attachments: []
        );

        // update email_sent status in vms_rfqs table for new reference id
        if ($emailSent) {
            // Update email_sent flag in vms_rfqs table
            $updateQuery = 'UPDATE vms_rfqs SET email_sent = true WHERE id = ?';
            $this->logger->logQuery($updateQuery, [$newRfqInsertionId], 'classes', $module, $username);
            $this->conn->update($updateQuery, [$newRfqInsertionId], 'RFQ email_sent updated');
        }

        if ($newRfqInsertionId && $newCounterpartyInsertionId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }

    // util methods
    public function checkRfqStatus($reference_id, $module, $username)
    {
        $query = 'SELECT status FROM vms_rfqs WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result['status'] ?? null;
    }

    public function submitRfqStatusChange($reference_id, $module, $username)
    {
        // update rfq table status
        $query = 'UPDATE vms_rfqs SET status = ? WHERE reference_id = ?';
        $params = [8, $reference_id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $updatedRfqTableId = $this->conn->update($query, $params, 'RFQ status updated to Submitted after declaration insertion');

        // // update vendor table status to 'Submitted' (status id 8)
        // $query = 'UPDATE vms_vendor SET vendor_status = ? WHERE reference_id = ?';
        // $params = [8, $reference_id];
        // $this->logger->logQuery($query, $params, 'classes', $module, $username);
        // $updatedVendorTableId = $this->conn->update($query, $params, 'Vendor status updated to Submitted after declaration insertion');

        if ($updatedRfqTableId) {
            return true;
        } else {
            return false;
        }
    }

    public function checkVendorCodeExists($vendor_code, $module, $username)
    {
        $query = 'SELECT 1 FROM vms_vendor WHERE vendor_code = ?';
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        return !empty($this->conn->runSingle($query, [$vendor_code]));
    }

    public function checkVendorStatus($vendor_code, $module, $username)
    {
        $query = 'SELECT vendor_status FROM vms_vendor WHERE vendor_code = ?';
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_code]);
        return $result['vendor_status'] ?? null;
    }

    // check duplicate reinitiation 
    public function checkDuplicateReinitiation($vendor_code, $module, $username)
    {
        $query = 'SELECT r.id
                    FROM vms_rfqs r
                    join vms_vendor v on 
                    v.id = r.vendor_id
                    where v.vendor_code = ?
                    AND r.status IN (7,8,9,10) LIMIT 1';

        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_code]);
        return true ? !empty($result) : false;
    }

    public function getExpiryDateByVendorCode($vendor_code, $module, $username)
    {
        $query = 'SELECT r.expiry_date FROM vms_vendor v
                    JOIN vms_rfqs r ON v.active_rfq = r.id
                    WHERE v.vendor_code = ?';
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_code]);
        return $result['expiry_date'] ?? null;
    }
}
