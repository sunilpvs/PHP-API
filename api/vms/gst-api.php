<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/vms/Gst.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once __DIR__ . '../../../classes/utils/FileHerlper.php';
require_once __DIR__ . '../../../classes/vms/CounterPartyInfo.php';

// Validate login and authenticate JWT
authenticateJWT();

// Configuration & logger
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$gstOb = new Gst();
$counterPartyInfoOb = new CounterPartyInfo();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];

        $goods_services = $gstOb->getGoodsServicesByReference($reference_id, $module, $username);
        $gst_regs = $gstOb->getGstRegistrationsByReference($reference_id, $module, $username);
        $income_tax_details = $gstOb->getIncomeTaxDetailsByReference($reference_id, $module, $username);

        $type = $_GET['type'] ?? null;

        switch ($type) {
            case 'goods_services':
                $response = $goods_services;
                break;
            case 'gst_registrations':
                $response = $gst_regs;
                break;
            case 'income_tax_details':
                $response = $income_tax_details;
                break;
            default:
                $response = [
                    'goods_services' => $goods_services,
                    'gst_registrations' => $gst_regs,
                    'income_tax_details' => $income_tax_details,
                ];
                break;
        }

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_POST, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];
        $path = $_SERVER['REQUEST_URI'];

        // === GOODS / SERVICES ===
        if (strpos($path, 'goods_services') !== false) {

            if (!empty($input['descriptions']) && is_array($input['descriptions'])) {

                if (empty($input['type']) || empty($input['type_of_counterparty'])) {
                    http_response_code(400);
                    $error = ["error" => "'type' and 'type_of_counterparty' are required"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                $type = $input['type'];                     // Goods / Services / Goods and Services

                $type_of_counterparty = $input['type_of_counterparty'];

                $others = $input['others'] ?? null;

                if ($type_of_counterparty === 'Others' && empty($input['others'])) {
                    http_response_code(400);
                    $error = ["error" => "'others' is required when type_of_counterparty is 'Others'"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                if ($type_of_counterparty !== 'Others') {
                    $others = null; // Clear others if not 'Others'
                }


                // Insert multiple descriptions with one selected type
                $result = $gstOb->insertGoodsAndServices(
                    $reference_id,
                    $type_of_counterparty,
                    $others,
                    $type,
                    $input['descriptions'],     // array of descriptions
                    $module,
                    $username
                );

                $response = $result
                    ? ["message" => "$result Goods/Services added"]
                    : ["error" => "Insert failed"];
            } else {

                // Single insert fallback (old pattern)
                if (empty($input['type']) || empty($input['description']) || empty($input['type_of_counterparty'])) {
                    http_response_code(400);
                    $error = ["error" => "Both 'type', 'description', and 'type_of_counterparty' are required"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                $type_of_counterparty = $input['type_of_counterparty'];
                $others = $input['others'] ?? null;

                $result = $gstOb->insertGoodsService(
                    $reference_id,
                    $type_of_counterparty,
                    $others,
                    $input['type'],
                    $input['description'],
                    $module,
                    $username
                );

                $response = $result
                    ? ["message" => "Goods/Services added", "id" => (int)$result]
                    : ["error" => "Insert failed"];
            }

            // Response
            http_response_code(isset($response['error']) ? 400 : 201);
            echo json_encode($response);
            $logger->logRequestAndResponse($_POST, $response);
            break;
        }


        // === GST REGISTRATIONS ===
        if (strpos($path, 'gst_registrations') !== false) {

            if (!isset($input['gst_applicable'])) {
                http_response_code(400);
                $error = ["error" => "'gst_applicable' is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            $gst_applicable = $input['gst_applicable'];

            // =============================
            // CASE 1: gst_applicable = TRUE
            // =============================
            if ($gst_applicable === true) {

                // Validate reg_type & gstr_filling_type
                if (empty($input['reg_type']) || empty($input['gstr_filling_type'])) {
                    http_response_code(400);
                    $error = ["error" => "'reg_type' and 'gstr_filling_type' are required when gst_applicable = true"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                // Validate items
                if (empty($input['items']) || !is_array($input['items'])) {
                    http_response_code(400);
                    $error = ["error" => "'items' array required when gst_applicable = true"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                $reg_type = $input['reg_type'];
                $gstr_filling_type = $input['gstr_filling_type'];

                // -----------------------------
                // Insert multiple registrations
                // -----------------------------
                $result = $gstOb->insertGSTRegisrations(
                    $reference_id,
                    $gst_applicable,
                    $reg_type,
                    $gstr_filling_type,
                    $input['items'],
                    $module,
                    $username
                );

                $response = $result
                    ? ["message" => "$result GST registrations added"]
                    : ["error" => "Insert failed"];
            }

            // ==============================
            // CASE 2: gst_applicable = FALSE
            // ==============================
            else {

                // No reg_type, no items, everything NULL
                $result = $gstOb->insertGstRegistration(
                    $reference_id,
                    false,
                    null,
                    null,
                    $module,
                    $username
                );

                $response = $result
                    ? ["message" => "GST registration added (gst_applicable = false)", "id" => (int)$result]
                    : ["error" => "Insert failed"];
            }

            // Send HTTP response
            http_response_code(isset($response['error']) ? 400 : 201);
            echo json_encode($response);
            $logger->logRequestAndResponse($_POST, $response);
            break;
        }




        // === INCOME TAX DETAILS ===
        if (strpos($path, 'income_tax_details') !== false) {

            // MULTIPLE RECORDS
            if (!empty($input['items']) && is_array($input['items'])) {
                $count = 0;
                $result = null;
                $items = $input['items'];
                $previousFinYear = (date('Y') - 1) . '-' . date('Y');
                $beforePreviousFinYear = (date('Y') - 2) . '-' . (date('Y') - 1);

                // ---------- STEP 1: VALIDATE ALL ITEMS ----------
                $arrayLength = count($items);
                if ($arrayLength > 2) {
                    http_response_code(400);
                    echo json_encode(["error" => "A maximum of 2 income tax records can be added"]);
                    return;
                }

                foreach ($items as $index => $item) {

                    if (empty($item['fin_year']) || empty($item['currency_type']) || !isset($item['status_of_itr'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "fin_year, currency_type, and status_of_itr are required", "item_index" => $index]);
                        return; // stop execution
                    }

                    if ($item['currency_type'] === 'Others' && empty($item['others'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "'others' is required when currency_type is 'Others'", "item_index" => $index]);
                        return;
                    }

                    if (!in_array($item['fin_year'], [$previousFinYear, $beforePreviousFinYear])) {
                        http_response_code(400);
                        echo json_encode(["error" => "fin_year must be either '$previousFinYear' or '$beforePreviousFinYear'", "item_index" => $index + 1]);
                        return;
                    }
                }

                // ---------- STEP 2: INSERT ITEMS ----------
                $count = 0;
                foreach ($items as $item) {

                    // Clear 'others' if currency is INR
                    if ($item['currency_type'] === 'Rupees (INR)') $item['others'] = null;
                    if ($item['status_of_itr'] === false) {
                        $item['itr_ack_num'] = null;
                        $item['itr_filed_date'] = null;
                    }

                    $result = $gstOb->insertIncomeTaxDetails(
                        $reference_id,
                        $item['fin_year'],
                        $item['currency_type'],
                        $item['others'] ?? null,
                        $item['turnover'] ?? null,
                        $item['status_of_itr'],
                        $item['itr_ack_num'] ?? null,
                        $item['itr_filed_date'] ?? null,
                        $module,
                        $username
                    );

                    if ($result) $count++;
                }
                if ($count === 0) {
                    http_response_code(500);
                    $error = ["error" => "Insert failed"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                if ($result === null) {
                    http_response_code(500);
                    $error = ["error" => "Insert failed"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                http_response_code(200);
                echo json_encode(["message" => "$count income tax record(s) added"]);
                $logger->logRequestAndResponse($input, ["message" => "$count income tax record(s) added"]);
                break;
            }
        }
        break;




    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];
        $path = $_SERVER['REQUEST_URI'];

        // === GOODS / SERVICES ===
        if (strpos($path, 'goods_services') !== false) {

            // if (empty($input['id']) || empty($input['type']) || empty($input['description']) || empty($input['type_of_counterparty'])) {
            //     http_response_code(400);
            //     $error = ["error" => "id, type, description, and type_of_counterparty are required"];
            //     echo json_encode($error);
            //     $logger->logRequestAndResponse($input, $error);
            //     break;
            // }

            $type_of_counterparty = $input['type_of_counterparty'];
            $others = $input['others'] ?? null;


            $result = $gstOb->updateGoodsAndServices(
                $reference_id,
                $type_of_counterparty,
                $others,
                $input['type'],
                $input['items'],
                $module,
                $username
            );

            $response = $result
                ? ["message" => "Goods/Services updated"]
                : ["error" => "Update failed"];

            // Response
            http_response_code(isset($response['error']) ? 400 : 200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_POST, $response);
            break;
        }
        // === GST REGISTRATIONS ===
        // === UPDATE GST REGISTRATIONS ===
        if ($_SERVER['REQUEST_METHOD'] === 'PUT' && strpos($path, 'gst_registrations') !== false) {

            if (!isset($_GET['reference_id'])) {
                http_response_code(400);
                $error = ["error" => "reference_id is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            $reference_id = $_GET['reference_id'];

            try {

                // MUST have gst_applicable
                if (!isset($input['gst_applicable'])) {
                    http_response_code(400);
                    $error = ["error" => "'gst_applicable' is required"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                $gst_applicable = $input['gst_applicable'];

                // ====================================
                // CASE 1 → gst_applicable = TRUE
                // ====================================
                if ($gst_applicable === true) {

                    // reg_type and gstr_filling_type must be present
                    if (empty($input['reg_type']) || empty($input['gstr_filling_type'])) {
                        http_response_code(400);
                        $error = ["error" => "'reg_type' and 'gstr_filling_type' are required when gst_applicable = true"];
                        echo json_encode($error);
                        $logger->logRequestAndResponse($input, $error);
                        break;
                    }

                    // items array required
                    if (empty($input['items']) || !is_array($input['items'])) {
                        http_response_code(400);
                        $error = ["error" => "'items' array required when gst_applicable = true"];
                        echo json_encode($error);
                        $logger->logRequestAndResponse($input, $error);
                        break;
                    }

                    $reg_type = $input['reg_type'];
                    $gstr_filling_type = $input['gstr_filling_type'];

                    // Transform items for service method
                    // Each item: { gst_id?, state, gst_number, gst_applicable }
                    $updates = [];
                    foreach ($input['items'] as $item) {

                        // Required fields when gst_applicable = true
                        if (empty($item['state']) || empty($item['gst_number'])) {
                            http_response_code(400);
                            $error = ["error" => "'state' and 'gst_number' are required for each item when gst_applicable = true"];
                            echo json_encode($error);
                            $logger->logRequestAndResponse($input, $error);
                            return;
                        }

                        $updates[] = [
                            "gst_id"        => $item['gst_id'] ?? null,
                            "gst_applicable" => 1,
                            "state"         => $item['state'],
                            "gst_number"    => $item['gst_number']
                        ];
                    }

                    // Call service layer update
                    $updated = $gstOb->updateMultipleGstRegistrations(
                        $reference_id,
                        $updates,
                        $gst_applicable = true,
                        $reg_type,
                        $gstr_filling_type,
                        $module,
                        $username
                    );

                    if ($updated > 0) {
                        $response = ["message" => "$updated GST registrations updated"];
                    } else if ($updated === 0) {
                        $response = ["message" => "GST registrations updated"];
                    } else if ($updated == -1) {
                        $response = ["message" => "No changes made to GST registrations"];
                    } else {
                        $response = ["error" => "Update failed"];
                    }
                }

                // ====================================
                // CASE 2 → gst_applicable = FALSE
                // ====================================
                else {

                    // CASE: gst_applicable = false → NO items, NO reg_type, NO gstr_filling_type
                    // We only update ALL existing registrations to null values OR insert if none exist



                    $updates = [[
                        "gst_id"        => $input['gst_id'] ?? null,
                        "gst_applicable" => 0,
                        "state"         => null,
                        "gst_number"    => null
                    ]];

                    $updated = $gstOb->updateMultipleGstRegistrations(
                        $reference_id,
                        $updates,
                        $gst_applicable = false,
                        null,   // reg_type
                        null,   // gstr_filling_type
                        $module,
                        $username
                    );

                    if ($updated > 0) {
                        $response = ["message" => "$updated GST registrations updated to gst_applicable = false"];
                    } else if ($updated === 0) {
                        $response = ["message" => "GST registrations updated"];
                    } else if ($updated == -1) {
                        $response = ["message" => "No changes made to GST registrations"];
                    } else {
                        $response = ["error" => "Update failed"];
                    }
                }
            } catch (Exception $e) {
                http_response_code(500);
                $error = ["error" => "An error occurred: " . $e->getMessage()];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            // FINAL RESPONSE
            http_response_code(isset($response['error']) ? 400 : 200);
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
            break;
        }


        // === INCOME TAX DETAILS ===
        if (strpos($path, 'income_tax_details') !== false) {

            if (!empty($input['items']) && is_array($input['items'])) {
                $count = 0;
                $result = null;
                $items = $input['items'];
                $previousFinYear = (date('Y') - 1) . '-' . date('Y');
                $beforePreviousFinYear = (date('Y') - 2) . '-' . (date('Y') - 1);

                // ---------- STEP 1: VALIDATE ALL ITEMS ----------
                $arrayLength = count($items);
                if ($arrayLength > 2) {
                    http_response_code(400);
                    echo json_encode(["error" => "A maximum of 2 income tax records can be added"]);
                    return;
                }

                foreach ($items as $index => $item) {

                    if (empty($item['fin_year']) || empty($item['currency_type']) || !isset($item['status_of_itr'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "fin_year, currency_type, and status_of_itr are required", "item_index" => $index]);
                        return; // stop execution
                    }

                    if ($item['currency_type'] === 'Others' && empty($item['others'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "'others' is required when currency_type is 'Others'", "item_index" => $index]);
                        return;
                    }

                    if (!in_array($item['fin_year'], [$previousFinYear, $beforePreviousFinYear])) {
                        http_response_code(400);
                        echo json_encode(["error" => "fin_year must be either '$previousFinYear' or '$beforePreviousFinYear'", "item_index" => $index + 1]);
                        return;
                    }
                }

                // ---------- STEP 2: INSERT ITEMS ----------
                $count = 0;
                foreach ($items as $item) {

                    // Clear 'others' if currency is INR
                    if ($item['currency_type'] === 'Rupees (INR)') $item['others'] = null;

                    if ($item['status_of_itr'] === false) {
                        $item['itr_ack_num'] = null;
                        $item['itr_filed_date'] = null;
                    }

                    $result = $gstOb->updateIncomeTaxDetails(
                        $reference_id,
                        $item['it_id'],
                        $item['fin_year'],
                        $item['currency_type'],
                        $item['others'] ?? null,
                        $item['turnover'] ?? null,
                        $item['status_of_itr'],
                        $item['itr_ack_num'] ?? null,
                        $item['itr_filed_date'] ?? null,
                        $module,
                        $username
                    );

                    if ($result) $count++;
                }
                if ($count === 0) {
                    http_response_code(500);
                    $error = ["error" => "Update failed"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                if ($result === null) {
                    http_response_code(500);
                    $error = ["error" => "Update failed"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }

                http_response_code(200);
                echo json_encode(["message" => "$count income tax record(s) Updated"]);
                $logger->logRequestAndResponse($input, ["message" => "$count income tax record(s) updated"]);
                break;
            }
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        $type = $_GET['type'] ?? null;
        $id = $_GET['id'] ?? null;

        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            $error = ["error" => "id is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        switch ($type) {
            case 'goods_services':
                $result = $gstOb->deleteGoodsService(intval($id), $module, $username);
                break;
            case 'gst_registrations':
                $result = $gstOb->deleteGstRegistration(intval($id), $module, $username);
                break;
            case 'income_tax_details':
                $result = $gstOb->deleteIncomeTaxDetails(intval($id), $module, $username);
                break;
            default:
                http_response_code(400);
                $error = ["error" => "Invalid type"];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
                break 2;
        }

        if ($result !== false) {
            http_response_code(200);
            $response = ["message" => "Deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Delete failed"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
