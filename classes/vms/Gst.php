<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Gst
{
    private $conn;
    private $logger;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    /* ============================================================
     *                    GOODS & SERVICES
     * ============================================================ */


    // helper method for inserting a single goods/service (bound to this class)
    public function insertGoodsService($reference_id, $type, $description, $module, $username)
    {
        $query = "INSERT INTO vms_goods_services 
                    (reference_id, type, description)
                VALUES (?, ?, ?)";

        $params = [
            $reference_id,
            $type,
            $description ?? null
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Goods/Services inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }


    public function insertGoodsAndServices($reference_id, $type_of_counterparty, $others, $type, array $descriptions, $module, $username)
    {
        $inserted = 0;

        if ($type_of_counterparty === 'Others') {
            $others = $others ?? null;
        } else {
            $others = null;
        }

        $query = "INSERT INTO vms_type_of_counterparty 
                    (reference_id, type_of_counterparty, others)
                VALUES (?, ?, ?)";

        $params = [
            $reference_id,
            $type_of_counterparty,
            $others
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Type of counterparty inserted';
        $counterPartyInsertionId = $this->conn->insert($query, $params, $logMessage);

        if (!$counterPartyInsertionId) {
            return false;
        }

        foreach ($descriptions as $desc) {

            // Skip empty description fields (user may fill 1–5 fields)
            if (empty($desc) || trim($desc) === '' || $desc === null) {
                continue;
            }

            $res = $this->insertGoodsService(
                $reference_id,
                $type,          // Goods, Services, or Goods and Services (single dropdown value)
                $desc,          // One filled description
                $module,
                $username
            );

            if ($res) {
                $inserted++;
            }
        }

        return $inserted;
    }




    // helper method for updating a single goods/service (bound to this class)
    public function updateGoodsService($gs_id, $reference_id, $type, $description, $module, $username)
    {

        $query = "UPDATE vms_goods_services SET 
                    type = ?,
                    description = ?
                WHERE gs_id = ? AND reference_id = ?";
        $params = [
            $type,
            $description,
            $gs_id,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Goods/Services updated';
        return $this->conn->update($query, $params, $logMessage);
    }


    // helper method to delete all goods/services by reference_id (bound to this class)
    public function deleteExistingGoodsServicesByReference($reference_id, $module, $username)
    {
        $query = "DELETE FROM vms_goods_services WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $logMessage = 'Deleted existing goods/services by reference_id';
        return $this->conn->delete($query, [$reference_id], $logMessage);
    }



    public function updateGoodsAndServices($reference_id, $type_of_counterparty, $others, $type, array $items, $module, $username)
    {
        $count = 0;


        // update type of counterparty and others for all existing rows
        $query = "UPDATE vms_type_of_counterparty SET 
                    type_of_counterparty = ?,
                    others = ?
                WHERE reference_id = ?";

        $params = [
            $type_of_counterparty,
            $others,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Updated type_of_counterparty and others for all existing rows';
        $counterPartyUpdatedId = $this->conn->update($query, $params, $logMessage);

        if ($counterPartyUpdatedId === false) {
            return false;
        }


        $query = "SELECT type from vms_goods_services WHERE reference_id = ? LIMIT 1";
        $existingTypeRow = $this->conn->runSingle($query, [$reference_id]);
        $existingType = $existingTypeRow['type'] ?? null;

        // If existing type is different from the new type, delete all existing goods/services
        if ($existingType !== null && $existingType !== $type) {
            $this->deleteExistingGoodsServicesByReference($reference_id, $module, $username);
        }

        // get existing goods/services for the reference_id
        $query = "SELECT gs_id FROM vms_goods_services WHERE reference_id = ?";
        $existingGoodsServices = $this->conn->runQuery($query, [$reference_id]);
        $existingGsIds = array_map(function ($row) {
            return $row['gs_id'];
        }, $existingGoodsServices);

        // Process each item in the provided array

        foreach ($items as $item) {

            $gs_id = $item['gs_id'] ?? null;
            $description = $item['description'] ?? '';


            // delete existing goods/services that were not included in the update payload
            $incomingGsIds = array_filter(array_map(function ($item) {
                return $item['gs_id'] ?? null;
            }, $items));

            $gsIdsToDelete = array_diff($existingGsIds, $incomingGsIds);
            foreach ($gsIdsToDelete as $gs_idToDelete) {
                $this->deleteGoodsService($gs_idToDelete, $module, $username);
            }

            // Skip empty fields
            if (empty($description) || trim($description) === '' || $description === null) {
                continue;
            }

            // CASE 1: Update existing row
            if (!empty($gs_id) && in_array($gs_id, $existingGsIds) && $gs_id !== null) {



                // CASE 1: Update existing row


                $res = $this->updateGoodsService(
                    $gs_id,
                    $reference_id,
                    $type,
                    $description,
                    $module,
                    $username
                );

                if ($res) {
                    $count++;
                }

                continue;
            }

            // CASE 2: Insert a new row (user added a new description)
            if ($description !== null) {


                $res = $this->insertGoodsService(
                    $reference_id,
                    $type,
                    $description,
                    $module,
                    $username
                );
                if ($res) {
                    $count++;
                }
            }
        }

        return $count;
    }



    public function deleteGoodsService($gs_id, $module, $username)
    {
        $query = "DELETE FROM vms_goods_services WHERE gs_id = ?";
        $this->logger->logQuery($query, [$gs_id], 'classes', $module, $username);
        $logMessage = 'Goods/Services deleted';
        return $this->conn->delete($query, [$gs_id], $logMessage);
    }


    public function getGoodsServicesByReference($reference_id, $module, $username)
    {
        // vms_goods_services table
        $query = "SELECT * FROM vms_goods_services WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $goodsServices = $this->conn->runQuery($query, [$reference_id]);
        // vms_type_of_counterparty table
        $query = "SELECT * FROM vms_type_of_counterparty WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $typeOfCounterparty = $this->conn->runSingle($query, [$reference_id]);
        return [
            'goods_services' => $goodsServices,
            'type_of_counterparty' => $typeOfCounterparty
        ];
    }

    public function checkDuplicateGoodsService($reference_id, $type, $description)
    {
        $query = "SELECT 1 FROM vms_goods_services
                  WHERE reference_id = ? AND type = ? AND lower(trim(description)) = lower(trim(?))";
        $this->logger->logQuery($query, [$reference_id, $type, $description], 'classes');
        return !empty($this->conn->runSingle($query, [$reference_id, $type, $description]));
    }

    public function checkEditDuplicateGoodsService($reference_id, $type, $description, $gs_id)
    {
        $query = "SELECT 1 FROM vms_goods_services
                  WHERE reference_id = ? AND type = ? AND lower(trim(description)) = lower(trim(?)) AND gs_id != ?";
        $this->logger->logQuery($query, [$reference_id, $type, $description, $gs_id], 'classes');
        return !empty($this->conn->runSingle($query, [$reference_id, $type, $description, $gs_id]));
    }


    /* ============================================================
     *                    GST REGISTRATIONS
     * ============================================================ */

    public function insertGstRegistration($reference_id, $gst_applicable, $state, $gst_number, $module, $username)
    {



        // insert into vms_gst_registrations table        

        $query = "INSERT INTO vms_gst_registrations
                    (reference_id, gst_applicable, state, gst_number)
                    VALUES (?, ?, ?, ?)";

        $params = [
            $reference_id,
            $gst_applicable,
            $state,
            $gst_number,
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'GST registration inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }

    public function insertGSTRegisrations($reference_id, $gst_applicable, $reg_type, $gstr_filling_type, array $items, $module, $username)
    {
        $inserted = 0;

        // insert into vms_gst_type table
        $query = "INSERT INTO vms_gst_type(reference_id, reg_type, gstr_filling_type)
                    VALUES (?, ?, ?)";
        $params = [
            $reference_id,
            $reg_type,
            $gstr_filling_type
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'GST type inserted';
        $this->conn->insert($query, $params, $logMessage);


        foreach ($items as $item) {
            // $gst_applicable = $item['gst_applicable'];
            $state = $item['state'];
            $gst_number = $item['gst_number'];


            $res = $this->insertGstRegistration(
                $reference_id,
                $gst_applicable,
                $state,
                $gst_number,
                $module,
                $username
            );

            if ($res) {
                $inserted++;
            }
        }

        return $inserted;
    }



    public function updateGstRegistration($reference_id, $gst_id, $gst_applicable, $state, $gst_number, $module, $username)
    {
        $query = "UPDATE vms_gst_registrations SET
                 gst_applicable = ?, state = ?, gst_number = ?
                WHERE reference_id = ? AND gst_id = ?";

        $params = [
            $gst_applicable,
            $state,
            $gst_number,
            $reference_id,
            $gst_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'GST registration updated';
        return $this->conn->update($query, $params, $logMessage);
    }

    public function updateMultipleGstRegistrations($reference_id, array $updates, $gst_applicable, $reg_type, $gstr_filling_type, $module, $username)
    {
        $updatedCount = 0;

        if($gst_applicable === false){
            // delete the record from vms_gst_type table
            $query = "DELETE FROM vms_gst_type WHERE reference_id = ?";
            $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
            $logMessage = 'GST type deleted as gst_applicable is false';
            $this->conn->delete($query, [$reference_id], $logMessage);

            // also delete all gst registrations for this reference_id from vms_gst_registrations table
            $query = "DELETE FROM vms_gst_registrations WHERE reference_id = ?";
            $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
            $logMessage = 'All GST registrations deleted as gst_applicable is false';
            $this->conn->delete($query, [$reference_id], $logMessage);

            // insert new record in vms_gst_registrations with gst_applicable as false
            $query = "INSERT INTO vms_gst_registrations(reference_id, gst_applicable)
                    VALUES (?, ?)";
            $params = [
                $reference_id,
                false
            ];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = 'GST registration inserted with gst_applicable as false';
            $this->conn->insert($query, $params, $logMessage);

            return 0; // nothing updated
        }

        // if gst_applicable is true, proceed with normal update flow


        // check if there is a record in vms_gst_type table for this reference_id
        $updatedGstTypeId = false;

        $query = "SELECT gst_type_id FROM vms_gst_type WHERE reference_id = ?";
        $existingGstType = $this->conn->runSingle($query, [$reference_id]);
        if (!$existingGstType) {
            // insert new record into vms_gst_type table
            $query = "INSERT INTO vms_gst_type(reference_id, reg_type, gstr_filling_type)
                    VALUES (?, ?, ?)";
            $params = [
                $reference_id,
                $reg_type,
                $gstr_filling_type
            ];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = 'GST type inserted';
            $updatedGstTypeId = $this->conn->insert($query, $params, $logMessage);
        }else{
            // update existing record in vms_gst_type table
            $query = "UPDATE vms_gst_type SET
                    reg_type = ?, gstr_filling_type = ?
                WHERE reference_id = ?";
            $params = [
                $reg_type,
                $gstr_filling_type,
                $reference_id
            ];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = 'GST type updated';
            $updatedGstTypeId = $this->conn->update($query, $params, $logMessage);
        }
        


        // Step A: Fetch existing gst_ids for this reference_id
        $query = "SELECT gst_id FROM vms_gst_registrations WHERE reference_id = ?";
        $existingGstIds = $this->conn->runQuery($query, [$reference_id]);
        $existingGstIds = array_map(function ($row) {
            return $row['gst_id'];
        }, $existingGstIds);
        // returns array like [1,2,3]

        // Step B: Extract gst_ids from updates payload
        $incomingGstIds = [];
        foreach ($updates as $update) {
            if (!empty($update['gst_id'])) {
                $incomingGstIds[] = $update['gst_id'];
            }
        }

        // Step C: Find gst_ids that should be deleted (existing - incoming)
        $gstIdsToDelete = array_diff($existingGstIds, $incomingGstIds);

        // Step D: Delete removed GST registrations
        if (!empty($gstIdsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($gstIdsToDelete), '?'));
            $deleteQuery = "DELETE FROM vms_gst_registrations 
                            WHERE reference_id = ? AND gst_id IN ($placeholders)";
            $params = array_merge([$reference_id], $gstIdsToDelete);
            $this->logger->logQuery($deleteQuery, $params, 'classes', $module, $username);
            $logMessage = 'GST registrations removed because user removed them in update request';
            $this->conn->runQuery($deleteQuery, $params, $logMessage);
        }


        // Step E: Update or insert each GST registration from updates payload
        foreach ($updates as $update) {
            $gst_id = $update['gst_id'];
            $gst_applicable = $update['gst_applicable'];
            $state = $update['state'];
            $gst_number = $update['gst_number'];

            if (empty($gst_id) && $update['gst_number'] !== null && trim($update['gst_number']) !== '') {
                // Insert new GST registration
                $res = $this->insertGstRegistration(
                    $reference_id,
                    $gst_applicable,
                    $state,
                    $gst_number,
                    $module,
                    $username
                );

                if ($res) {
                    $updatedCount++;
                }
                continue;
            }

            $res = $this->updateGstRegistration(
                $reference_id,
                $gst_id,
                $gst_applicable,
                $state,
                $gst_number,
                $module,
                $username
            );



            // Increment count after each successful update
            if ($res) {
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            return $updatedCount;
        } else if ($updatedGstTypeId) {
            return 0; // GST type updated but no registrations changed
        } else {
            return -1; // nothing updated
        }
    }


    public function deleteGstRegistrationsByGstId($gst_id, $module, $username)
    {
        $query = "DELETE FROM vms_gst_registrations WHERE gst_id = ?";
        $this->logger->logQuery($query, [$gst_id], 'classes', $module, $username);
        $logMessage = 'GST registration deleted by gst_id';
        return $this->conn->delete($query, [$gst_id], $logMessage);
    }

    public function getGstRegistrationsByReference($reference_id, $module, $username)
    {
        $query = "SELECT * FROM vms_gst_registrations WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $gstRegistrations = $this->conn->runQuery($query, [$reference_id]);

        $query = "SELECT * FROM vms_gst_type WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $gstType = $this->conn->runSingle($query, [$reference_id]);
        return [
            'gst_registrations' => $gstRegistrations,
            'gst_type' => $gstType
        ];
    }

    public function deleteGstRegistration($gst_id, $module, $username)
    {
        $query = "DELETE FROM vms_gst_registrations WHERE gst_id = ?";
        $this->logger->logQuery($query, [$gst_id], 'classes', $module, $username);
        $logMessage = 'GST registration deleted';
        return $this->conn->update($query, [$gst_id], $logMessage);
    }

    /* ============================================================
     *                    INCOME TAX DETAILS    
     * ============================================================ */

    public function insertIncomeTaxDetails($reference_id, $fin_year, $currency_type, $others, $turnover, $status_of_itr, $itr_ack_num, $itr_filed_date, $module, $username)
    {
        $query = "INSERT INTO vms_income_tax_details
                    (reference_id, fin_year, currency_type, others, turnover, status_of_itr, itr_ack_num, itr_filed_date)
                VALUES (?,?,?,?,?,?,?,?)";

        $params = [
            $reference_id,
            $fin_year,
            $currency_type,
            $others,
            $turnover,
            $status_of_itr,
            $itr_ack_num,
            $itr_filed_date
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Income tax details inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }



    public function updateIncomeTaxDetails($reference_id, $it_id, $fin_year, $currency_type, $others, $turnover, $status_of_itr, $itr_ack_num, $itr_filed_date, $module, $username)
    {
        $query = "UPDATE vms_income_tax_details SET
                    fin_year = ?, currency_type = ?, others = ?, turnover = ?, status_of_itr = ?, itr_ack_num = ?, itr_filed_date = ?
                WHERE reference_id = ? AND it_id = ?";

        $params = [
            $fin_year,
            $currency_type,
            $others,
            $turnover,
            $status_of_itr,
            $itr_ack_num,
            $itr_filed_date,
            $reference_id,
            $it_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Income tax details updated';
        return $this->conn->update($query, $params, $logMessage);
    }


    public function deleteIncomeTaxDetails($it_id, $module, $username)
    {
        $query = "DELETE FROM vms_income_tax_details WHERE it_id = ?";
        $this->logger->logQuery($query, [$it_id], 'classes', $module, $username);
        $logMessage = 'Income tax details deleted';
        return $this->conn->update($query, [$it_id], $logMessage);
    }



    public function getIncomeTaxDetailsByReference($reference_id, $module, $username)
    {
        $query = "SELECT * FROM vms_income_tax_details WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$reference_id]);
    }

    public function calculateCurrentFinYear()
    {
        $currentDate = new DateTime();
        $currentYear = (int)$currentDate->format('Y');
        $currentMonth = (int)$currentDate->format('m');

        if ($currentMonth >= 4) {
            $startYear = $currentYear;
            $endYear = $currentYear + 1;
        } else {
            $startYear = $currentYear - 1;
            $endYear = $currentYear;
        }

        return $startYear . '-' . $endYear;
    }
}
