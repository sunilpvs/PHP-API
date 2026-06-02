<?php

use PgSql\Lob;
use GuzzleHttp\Exception\ClientException;

require_once(__DIR__ . "/../../classes/authentication/JWTHandler.php");
require_once(__DIR__ . "/../../classes/authentication/LoginUser.php");
require_once __DIR__ . '../../../classes/Logger.php';


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);


$user = new UserLogin();
$token = $user->getToken();

if (empty($token)) {
    http_response_code(401);
    echo json_encode(["error" => "Access token not found"]);
    exit();
}

$jwt = new JWTHandler();

try {
    $decodedToken = $jwt->decodeJWT($token);
} catch (\Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token"]);
    exit();
}

$app = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . "/app.ini", true);

$debugMode = isset($app['generic']['DEBUG_MODE']) && in_array(strtolower($app['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$siteId = $app['sharepoint-leave-management']['siteId'];
$listId = $app['sharepoint-leave-management']['listId'];

$authProvider = $decodedToken['auth_provider'] ?? 'local';
$username     = $decodedToken['username'] ?? '';

if ($authProvider !== 'microsoft') {
    http_response_code(400);
    echo json_encode(["error" => "Microsoft authentication required"]);
    exit();
}


require_once($_SERVER['DOCUMENT_ROOT'] . "/vendor/autoload.php");

$accessToken = $_COOKIE['microsoft_access_token'] ?? null;

if (empty($accessToken)) {
    http_response_code(401);
    echo json_encode(["error" => "Microsoft access token not found"]);
    exit();
}

$graph = new \Microsoft\Graph\Graph();
$graph->setAccessToken($accessToken);


switch ($_SERVER['REQUEST_METHOD']) {


    case 'GET':
        $logger->log("Received GET request for SharePoint Leave Requests API", [
            "queryParams" => $_GET,
            "username" => $username
        ]);

        if (isset($_GET['type']) && $_GET['type'] === 'my-leaves' && isset($_GET['id'])) {

            $itemId = intval($_GET['id']);

            try {
                $item = $graph->createRequest(
                    "GET",
                    "/sites/$siteId/lists/$listId/items/$itemId?\$expand=fields"
                )
                    ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                    ->execute();

                if (!$item) {
                    http_response_code(404);
                    echo json_encode(["error" => "Leave request not found"]);
                    exit();
                }

                $fields = $item->getFields()->getProperties();


                $data = [
                    "id" => $item->getId(),
                    "title" => $fields['Title'] ?? null,
                    "employeeEmail" => $fields['EmployeeEmail'] ?? null,
                    "startDate" => $fields['StartDate'] ?? null,
                    "endDate" => $fields['EndDate'] ?? null,
                    "leaveType" => $fields['LeaveType'] ?? null,
                    "reason" => $fields['Reason'] ?? null,
                    "status" => $fields['Status'] ?? null,
                    "adminComment" => $fields['AdminComments'] ?? null,
                    "approverName" => $fields['ApproverName'] ?? null,
                    "approverEmail" => $fields['ApproverEmail'] ?? null,
                ];

                echo json_encode(["data" => $data]);
                $logger->logRequestAndResponse($_GET, $data);
                exit();
            } catch (\Microsoft\Graph\Exception\GraphException $e) {
                http_response_code(500);
                $logger->logRequestAndResponse($_GET, $e->getMessage());
                echo json_encode(["error" => $e->getMessage()]);
                exit();
            }
        }

        if (isset($_GET['type']) && $_GET['type'] === 'my-leaves') {

            try {
                $response = $graph->createRequest(
                    "GET",
                    "/sites/$siteId/lists/$listId/items?" .
                        "\$expand=fields(\$select=Title,EmployeeEmail,StartDate,EndDate,LeaveType,Reason,Status,AdminComments,ApproverName,ApproverEmail)" .
                        "&\$filter=fields/EmployeeEmail eq '$username'" .
                        "&\$orderby=fields/StartDate desc"
                )
                    ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                    ->execute();

                $items = [];

                foreach ($response as $item) {
                    $fields = $item->getFields()->getProperties();

                    $items[] = [
                        "id" => $item->getId(),
                        "title" => $fields['Title'] ?? null,
                        "employeeEmail" => $fields['EmployeeEmail'] ?? null,
                        "startDate" => $fields['StartDate'] ?? null,
                        "endDate" => $fields['EndDate'] ?? null,
                        "leaveType" => $fields['LeaveType'] ?? null,
                        "reason" => $fields['Reason'] ?? null,
                        "status" => $fields['Status'] ?? null,
                        "adminComment" => $fields['AdminComments'] ?? null,
                        "approverName" => $fields['ApproverName'] ?? null,
                        "approverEmail" => $fields['ApproverEmail'] ?? null,
                    ];
                }

                $logger->logRequestAndResponse($_GET, ["data" => $items]);
                echo json_encode(["data" => $items]);
                exit();
            } catch (\Microsoft\Graph\Exception\GraphException $e) {
                http_response_code(500);
                $logger->logRequestAndResponse($_GET, ["error" => $e->getMessage()]);
                echo json_encode(["error" => $e->getMessage()]);
                exit();
            }
        }

        if (isset($_GET['type']) && $_GET['type'] === 'approvals') {

            $status = $_GET['status'] ?? null;

            $filter = "fields/ApproverEmail eq '$username'";

            if (!empty($status)) {
                $filter .= " and fields/Status eq '$status'";
            }

            try {
                $response = $graph->createRequest(
                    "GET",
                    "/sites/$siteId/lists/$listId/items?" .
                        "\$expand=fields(\$select=Title,EmployeeEmail,StartDate,EndDate,LeaveType,Reason,Status,AdminComments,ApproverName,ApproverEmail)" .
                        "&\$filter=$filter" .
                        "&\$orderby=fields/StartDate desc"
                )
                    ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                    ->execute();

                $items = [];

                foreach ($response as $item) {
                    $fields = $item->getFields()->getProperties();

                    $items[] = [
                        "id" => $item->getId(),
                        "title" => $fields['Title'] ?? null,
                        "employeeEmail" => $fields['EmployeeEmail'] ?? null,
                        "startDate" => $fields['StartDate'] ?? null,
                        "endDate" => $fields['EndDate'] ?? null,
                        "leaveType" => $fields['LeaveType'] ?? null,
                        "reason" => $fields['Reason'] ?? null,
                        "status" => $fields['Status'] ?? null,
                        "adminComment" => $fields['AdminComments'] ?? null,
                        "approverName" => $fields['ApproverName'] ?? null,
                        "approverEmail" => $fields['ApproverEmail'] ?? null,
                    ];
                }

                echo json_encode(["data" => $items]);
                $logger->logRequestAndResponse($_GET, ["data" => $items]);
                exit();
            } catch (\Microsoft\Graph\Exception\GraphException $e) {
                http_response_code(500);
                $logger->logRequestAndResponse($_GET, ["error" => $e->getMessage()]);
                echo json_encode(["error" => $e->getMessage()]);
                exit();
            }
        }

        http_response_code(400);
        echo json_encode(["error" => "Invalid type parameter"]);
        break;


    case 'POST':
        // logic for creating new leave request
        $logger->log("Received POST request for SharePoint Leave Requests API", [
            "postData" => $_POST,
            "username" => $username
        ]);
        try {

            if (!isset($input['title']) || empty(trim($input['title']))) {
                http_response_code(400);
                $error = ["error" => "Title is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            if (!isset($input['reason']) || empty(trim($input['reason']))) {
                http_response_code(400);
                $error = ["error" => "Reason is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            if (!isset($input['startDate']) || empty(trim($input['startDate']))) {
                http_response_code(400);
                $error = ["error" => "Start date is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            if (!isset($input['endDate']) || empty(trim($input['endDate']))) {
                http_response_code(400);
                $error = ["error" => "End date is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            if ($input['startDate'] > $input['endDate']) {
                http_response_code(400);
                $error = ["error" => "Start date cannot be after end date"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            // for medical leave, allow past dates but for other leave types, start and end dates cannot be in the past
            // if(($input['startDate'] || $input['endDate'])  < date('Y-m-d')) {
            //     http_response_code(400);
            //     $error = ["error" => "Start date and end date cannot be in the past"];
            //     echo json_encode($error);
            //     $logger->logRequestAndResponse($input, $error);
            //     break;
            // }

            if (!isset($input['leaveType']) || empty(trim($input['leaveType']))) {
                http_response_code(400);
                $error = ["error" => "Leave type is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }


            // if(!in_array($input['leaveType'], ['Casual', 'Sick Leave', 'Emergency Leave', 'Maternity Leave', 'Unpaid Leave'])) {
            if (!in_array($input['leaveType'], ['Casual', 'Medical', 'Emergency'])) {
                http_response_code(400);
                $error = ["error" => "Invalid leave type"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            $title = trim($input['title']);
            $reason = trim($input['reason']);
            $startDate = trim($input['startDate']);
            $endDate = trim($input['endDate']);
            $leaveType = trim($input['leaveType']);


            $requestBody = [
                "fields" => [
                    "Title" => $title,
                    "EmployeeLookupId" => null,
                    "EmployeeEmail" => null,
                    "StartDate" => $startDate,
                    "EndDate" => $endDate,
                    "LeaveType" => $leaveType,
                    "Reason" => $reason,
                    "Status" => "Pending",
                    "AdminComments" => "",
                ]
            ];

            $response = $graph->createRequest(
                "POST",
                "/sites/$siteId/lists/$listId/items"
            )
                ->attachBody($requestBody)
                ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                ->execute();

            $fields = $response->getFields()->getProperties();
            $data = [
                "id" => $response->getId(),
                "title" => $fields['Title'] ?? null,
                "employeeEmail" => $fields['EmployeeEmail'] ?? null,
                "startDate" => $fields['StartDate'] ?? null,
                "endDate" => $fields['EndDate'] ?? null,
                "leaveType" => $fields['LeaveType'] ?? null,
                "reason" => $fields['Reason'] ?? null,
                "status" => $fields['Status'] ?? null,
                "adminComment" => $fields['AdminComments'] ?? null,
                "approverName" => $fields['ApproverName'] ?? null,
                "approverEmail" => $fields['ApproverEmail'] ?? null,
            ];
            if ($response) {
                http_response_code(201);
                echo json_encode(["data" => $data]);
                $logger->logRequestAndResponse($input, ["data" => $data]);
                exit();
            } else {
                http_response_code(500);
                $error = ["error" => "Failed to create leave request"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }
        } catch (\Microsoft\Graph\Exception\GraphException $e) {
            http_response_code(500);
            $logger->logRequestAndResponse($input, ["error" => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
            exit();
        } catch (\Exception $e) {
            http_response_code(500);
            $logger->logRequestAndResponse($input, ["error" => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
            exit();
        }

    case 'PATCH':
        // for updating status and admin comments of leave request by approver
        $logger->log("Received PATCH request for SharePoint Leave Requests API", [
            "postData" => $_POST,
            "username" => $username
        ]);

        // get item details first to check if the approver is valid 
        $itemId = intval($input['id'] ?? 0);
        if (!isset($input['id']) || empty(trim($input['id']))) {
            http_response_code(400);
            $error = ["error" => "Leave request ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        try {
            $item = $graph->createRequest(
                "GET",
                "/sites/$siteId/lists/$listId/items/$itemId?\$expand=fields"
            )
                ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                ->execute();

            // Only runs if item exists
            $fields = $item->getFields() ? $item->getFields()->getProperties() : [];
        } catch (ClientException $e) {

            $response = $e->getResponse();

            if ($response && $response->getStatusCode() == 404) {
                http_response_code(404);
                $error = ["error" => "Leave request not found"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return; // stop further execution
            }

            // log unexpected errors
            error_log($e->getMessage());
            throw $e;
        }

        $fields = $item->getFields()->getProperties();

        if(!isset($fields['ApproverEmail']) || $fields['ApproverEmail'] !== $username) {
            http_response_code(403);
            $error = ["error" => "You are not authorized to approve / reject this leave request"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        try {
            if (!isset($input['id']) || empty(trim($input['id']))) {
                http_response_code(400);
                $error = ["error" => "Leave request ID is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            $itemId = intval($input['id']);

            if (!isset($input['status']) || empty(trim($input['status']))) {
                http_response_code(400);
                $error = ["error" => "Status is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            // request can be only approved by valid approver ()

            if (!in_array($input['status'], ['Approved', 'Rejected'])) {
                http_response_code(400);
                $error = ["error" => "Invalid status value"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }

            // admin comments are required for rejected requests but optional for approved requests
            if ($input['status'] === 'Rejected') {
                if (!isset($input['adminComments']) || empty(trim($input['adminComments']))) {
                    http_response_code(400);
                    $error = ["error" => "Admin comments is required for rejected requests"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    break;
                }
            }

            $status = trim($input['status']);
            $adminComments = isset($input['adminComments']) ? trim($input['adminComments']) : '';

            $requestBody = [
                "Status" => $status,
                "AdminComments" => $adminComments,
            ];

            $response = $graph->createRequest(
                "PATCH",
                "/sites/$siteId/lists/$listId/items/$itemId/fields"
            )
                ->attachBody($requestBody)
                ->setReturnType(\Microsoft\Graph\Model\FieldValueSet::class)
                ->execute();
            // var_dump($response);

            if ($response) {
                $fields = $response->getProperties();
                $data = [
                    "id" => $response->getId(),
                    "title" => $fields['Title'] ?? null,
                    "employeeEmail" => $fields['EmployeeEmail'] ?? null,
                    "startDate" => $fields['StartDate'] ?? null,
                    "endDate" => $fields['EndDate'] ?? null,
                    "leaveType" => $fields['LeaveType'] ?? null,
                    "reason" => $fields['Reason'] ?? null,
                    "status" => $fields['Status'] ?? null,
                    "adminComment" => $fields['AdminComments'] ?? null,
                    "approverName" => $fields['ApproverName'] ?? null,
                    "approverEmail" => $fields['ApproverEmail'] ?? null,
                ];
                http_response_code(200);
                echo json_encode(["data" => $data]);
                $logger->logRequestAndResponse($input, ["data" => $data]);
                exit();
            } else {
                http_response_code(500);
                $error = ["error" => "Failed to update leave request"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break;
            }
        } catch (\Microsoft\Graph\Exception\GraphException $e) {
            http_response_code(500);
            $logger->logRequestAndResponse($input, ["error" => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
            exit();
        } catch (\Exception $e) {
            http_response_code(500);
            $logger->logRequestAndResponse($input, ["error" => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
            exit();
        }
        break;



    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

