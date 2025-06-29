<?php
// holding-browser-ajax.php

$allowed_origins = ['*'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowed_origins) || in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*')); // Send back specific origin if matched, or '*' if allowed
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Add any other headers your frontend sends
    // header('Access-Control-Allow-Credentials: true'); // Uncomment if you use cookies/sessions
}

header('Content-Type: application/json');
require_once '../config.php'; // Adjust path to your config.php



$action = $_GET['action'] ?? null;
$response = ['success' => false, 'message' => 'Invalid request'];

if ($action === 'get_upazilas') {
    $district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
    if ($district_id > 0) {
        $stmt = $mysqli->prepare("SELECT id, name_bd FROM holdings_upazilas WHERE district_id = ? ORDER BY name_bd ASC");
        $stmt->bind_param("i", $district_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $upazilas = [];
        while ($row = $result->fetch_assoc()) { $upazilas[] = $row; }
        $stmt->close();
        $response = ['success' => true, 'upazilas' => $upazilas];
    } else { $response['message'] = 'District ID required.'; }
    echo json_encode($response);
    exit;
}

if ($action === 'get_moujas') {
    $upazila_id = isset($_GET['upazila_id']) ? (int)$_GET['upazila_id'] : 0;
    if ($upazila_id > 0) {
        $stmt = $mysqli->prepare( "SELECT DISTINCT m.id, m.name_bd FROM holdings_moujas m JOIN holdings_core hc ON hc.mouja_id = m.id WHERE hc.upazila_id = ? ORDER BY m.name_bd ASC" );
        $stmt->bind_param("i", $upazila_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $moujas = [];
        while ($row = $result->fetch_assoc()) { $moujas[] = $row; }
        $stmt->close();
        $response = ['success' => true, 'moujas' => $moujas];
    } else { $response['message'] = 'Upazila ID required.'; }
    echo json_encode($response);
    exit;
}

if (isset($_GET['holding_id'])) {
    $holding_id = (int)$_GET['holding_id'];
    $holdingData = null; $ownersData = []; $schedulesData = [];
    $stmt = $mysqli->prepare(" SELECT hdm.*, hc.khotian_no AS khotian_no_core, hc.is_approve AS is_approve_core, hdm.holding_no as holding_no_main, hdm.district_name as district_name_main, hdm.upazila_name as upazila_name_main, hdm.mouja_name as mouja_name_main, hdm.is_approve as is_approve_main, dist.name_bn as district_name_core_ref, upa.name_bd as upazila_name_core_ref, mou.name_bd as mouja_name_core_ref FROM holdings_details_main hdm LEFT JOIN holdings_core hc ON hdm.core_holding_id = hc.id LEFT JOIN holdings_districts dist ON hc.district_id = dist.id LEFT JOIN holdings_upazilas upa ON hc.upazila_id = upa.id LEFT JOIN holdings_moujas mou ON hc.mouja_id = mou.id WHERE hdm.core_holding_id = ? LIMIT 1 ");
    $stmt->bind_param("i", $holding_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $holdingData = $row;
        $holdingData['district_name_core'] = $row['district_name_core_ref'] ?? $row['district_name_main'];
        $holdingData['upazila_name_core'] = $row['upazila_name_core_ref'] ?? $row['upazila_name_main'];
        $holdingData['mouja_name_core'] = $row['mouja_name_core_ref'] ?? $row['mouja_name_main'];
    }
    $stmt->close();

    if ($holdingData) {
        $stmt = $mysqli->prepare("SELECT id, name, father_name, address, mobile_no, nid, land_portion, tax_clear_year as tax_clear_year_owner FROM holdings_owners WHERE ldtax_holding_id = ?");
        $stmt->bind_param("i", $holding_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $ownersData[] = $row; }
        $stmt->close();

        $stmt = $mysqli->prepare(" SELECT hls.*, hls.khotian_no as khotian_no_schedule, hlut.amount AS amount_tax, hlut.current_demand AS current_demand_tax, hlut.start_year, hlut.end_year FROM holdings_land_schedules hls LEFT JOIN holdings_land_usage_tax hlut ON hls.id = hlut.schedule_id AND hls.holding_id = hlut.holding_id WHERE hls.holding_id = ? ");
        $stmt->bind_param("i", $holding_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tempSchedules = [];
        while ($row = $result->fetch_assoc()) {
            $schedule_main_id = $row['id'];
            if (!isset($tempSchedules[$schedule_main_id])) { $tempSchedules[$schedule_main_id] = [ 'id' => $row['id'], 'holding_id' => $row['holding_id'], 'office_id' => $row['office_id'], 'khotian_no_schedule' => $row['khotian_no_schedule'], 'dag_no' => $row['dag_no'], 'land_type' => $row['land_type'], 'amount_of_land' => $row['amount_of_land'], 'comments' => $row['comments'], 'status' => $row['status'], 'land_type_name' => $row['land_type_name'], 'tax_info' => null ]; }
            if ($row['amount_tax'] !== null && $tempSchedules[$schedule_main_id]['tax_info'] === null) { $tempSchedules[$schedule_main_id]['tax_info'] = [ 'amount_tax' => $row['amount_tax'], 'current_demand_tax' => $row['current_demand_tax'], 'start_year' => $row['start_year'], 'end_year' => $row['end_year'] ]; }
        }
        $schedulesData = array_values($tempSchedules);
        $stmt->close();
        $response = [ 'success' => true, 'holdingData' => $holdingData, 'ownersData' => $ownersData, 'schedulesData' => $schedulesData ];
    } else { $response['message'] = "Holding not found."; }
    echo json_encode($response);
    exit;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $mysqli->real_escape_string(trim($_GET['search'])) : '';
$filterOwnerName = isset($_GET['owner_name']) ? $mysqli->real_escape_string(trim($_GET['owner_name'])) : '';
$filterFatherName = isset($_GET['father_name']) ? $mysqli->real_escape_string(trim($_GET['father_name'])) : '';
$filterKhotianNo = isset($_GET['khotian_no']) ? $mysqli->real_escape_string(trim($_GET['khotian_no'])) : '';
$filterDagNo = isset($_GET['dag_no']) ? $mysqli->real_escape_string(trim($_GET['dag_no'])) : '';
$filterNid = isset($_GET['nid']) ? $mysqli->real_escape_string(trim($_GET['nid'])) : '';
$filterMobile = isset($_GET['mobile_no']) ? $mysqli->real_escape_string(trim($_GET['mobile_no'])) : ''; // New
$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$upazila_id = isset($_GET['upazila_id']) ? (int)$_GET['upazila_id'] : 0;
$mouja_id = isset($_GET['mouja_id']) ? (int)$_GET['mouja_id'] : 0;

$baseWhereClauses = [];
$allParams = [];
$allTypes = "";

if ($district_id > 0) { $baseWhereClauses[] = "hdm.district_id = ?"; $allParams[] = &$district_id; $allTypes .= "i"; }
if ($upazila_id > 0) { $baseWhereClauses[] = "hdm.upazila_id = ?"; $allParams[] = &$upazila_id; $allTypes .= "i"; }
if ($mouja_id > 0) { $baseWhereClauses[] = "hdm.mouja_id = ?"; $allParams[] = &$mouja_id; $allTypes .= "i"; }

$searchRelatedConditions = [];
$ownerSpecificConditions = [];
$landScheduleSpecificConditions = [];
$nidMobileSpecificConditions = [];

$search_term_for_like = "";
if (!empty($search)) {
    $search_term_for_like = "%" . $search . "%";
    $searchRelatedConditions[] = "hdm.holding_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hc.holding_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hdm.district_name LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hdm.upazila_name LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hdm.mouja_name LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "ho_search_generic.name LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "ho_search_generic.father_name LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "ho_search_generic.nid LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "ho_search_generic.mobile_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hc.khotian_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hls_search_generic.khotian_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
    $searchRelatedConditions[] = "hls_search_generic.dag_no LIKE ?"; $allParams[] = &$search_term_for_like; $allTypes .= "s";
}

$filter_owner_name_like = "";
if (!empty($filterOwnerName)) {
    $filter_owner_name_like = "%" . $filterOwnerName . "%";
    $ownerSpecificConditions[] = "ho_filter_name_father.name LIKE ?"; $allParams[] = &$filter_owner_name_like; $allTypes .= "s";
}
$filter_father_name_like = "";
if (!empty($filterFatherName)) {
    $filter_father_name_like = "%" . $filterFatherName . "%";
    $ownerSpecificConditions[] = "ho_filter_name_father.father_name LIKE ?"; $allParams[] = &$filter_father_name_like; $allTypes .= "s";
}
if (!empty($filterNid)) {
    $nidMobileSpecificConditions[] = "ho_filter_nid_mobile.nid = ?"; $allParams[] = &$filterNid; $allTypes .= "s";
}
if (!empty($filterMobile)) {
    $nidMobileSpecificConditions[] = "ho_filter_nid_mobile.mobile_no = ?"; $allParams[] = &$filterMobile; $allTypes .= "s";
}
$filter_khotian_no_like = "";
if (!empty($filterKhotianNo)) {
    $filter_khotian_no_like = "%" . $filterKhotianNo . "%";
    $landScheduleSpecificConditions[] = "(hc.khotian_no LIKE ? OR hls_filter.khotian_no LIKE ?)";
    $allParams[] = &$filter_khotian_no_like; $allTypes .= "s";
    $allParams[] = &$filter_khotian_no_like; $allTypes .= "s";
}
$filter_dag_no_like = "";
if (!empty($filterDagNo)) {
    $filter_dag_no_like = "%" . $filterDagNo . "%";
    $landScheduleSpecificConditions[] = "hls_filter.dag_no LIKE ?";
    $allParams[] = &$filter_dag_no_like; $allTypes .= "s";
}

$sqlFromAndJoins = " FROM holdings_details_main hdm LEFT JOIN holdings_core hc ON hdm.core_holding_id = hc.id LEFT JOIN ( SELECT ldtax_holding_id, GROUP_CONCAT(DISTINCT name SEPARATOR ', ') as owner_names_concatenated FROM holdings_owners GROUP BY ldtax_holding_id ) ho_display ON hdm.core_holding_id = ho_display.ldtax_holding_id LEFT JOIN holdings_districts dist_main ON hdm.district_id = dist_main.id LEFT JOIN holdings_upazilas upa_main ON hdm.upazila_id = upa_main.id LEFT JOIN holdings_moujas mou_main ON hdm.mouja_id = mou_main.id LEFT JOIN holdings_districts dist_core ON hc.district_id = dist_core.id LEFT JOIN holdings_upazilas upa_core ON hc.upazila_id = upa_core.id LEFT JOIN holdings_moujas mou_core ON hc.mouja_id = mou_core.id ";

if (!empty($search)) {
    $sqlFromAndJoins .= " LEFT JOIN holdings_owners ho_search_generic ON hdm.core_holding_id = ho_search_generic.ldtax_holding_id ";
    $sqlFromAndJoins .= " LEFT JOIN holdings_land_schedules hls_search_generic ON hdm.core_holding_id = hls_search_generic.holding_id ";
}
if (!empty($filterOwnerName) || !empty($filterFatherName)) {
    $sqlFromAndJoins .= " JOIN holdings_owners ho_filter_name_father ON hdm.core_holding_id = ho_filter_name_father.ldtax_holding_id ";
}
if (!empty($filterNid) || !empty($filterMobile)) {
    $sqlFromAndJoins .= " JOIN holdings_owners ho_filter_nid_mobile ON hdm.core_holding_id = ho_filter_nid_mobile.ldtax_holding_id ";
}
if (!empty($filterKhotianNo) || !empty($filterDagNo)) {
    $sqlFromAndJoins .= " JOIN holdings_land_schedules hls_filter ON hdm.core_holding_id = hls_filter.holding_id ";
}

$finalWhereClauses = $baseWhereClauses;
if (!empty($searchRelatedConditions)) { $finalWhereClauses[] = "(" . implode(" OR ", $searchRelatedConditions) . ")"; }
if (!empty($ownerSpecificConditions)) { foreach ($ownerSpecificConditions as $cond) { $finalWhereClauses[] = $cond; } }
if (!empty($nidMobileSpecificConditions)) { foreach ($nidMobileSpecificConditions as $cond) { $finalWhereClauses[] = $cond; } }
if (!empty($landScheduleSpecificConditions)) { foreach ($landScheduleSpecificConditions as $cond) { $finalWhereClauses[] = $cond; } }

$sqlWhere = "";
if (!empty($finalWhereClauses)) { $sqlWhere = "WHERE " . implode(" AND ", $finalWhereClauses); }

$countQuery = "SELECT COUNT(DISTINCT hdm.auto_id) as total " . $sqlFromAndJoins . $sqlWhere;
$stmt = $mysqli->prepare($countQuery);
if ($stmt === false) { $response = ['success' => false, 'message' => 'Error preparing count query: ' . $mysqli->error, 'query_debug' => $countQuery, 'params_debug' => $allParams, 'types_debug' => $allTypes]; echo json_encode($response); exit; }
if (!empty($allParams)) { $stmt->bind_param($allTypes, ...$allParams); }
$stmt->execute();
$totalRecordsResult = $stmt->get_result()->fetch_assoc();
$totalRecords = $totalRecordsResult['total'] ?? 0;
$totalPages = ceil($totalRecords / $limit);
$stmt->close();

$dataParams = $allParams;
$dataTypes = $allTypes;
$limit_param_data = $limit; $offset_param_data = $offset;
$dataParams[] = &$limit_param_data; $dataTypes .= "i";
$dataParams[] = &$offset_param_data; $dataTypes .= "i";

$dataQuery = " SELECT DISTINCT hdm.auto_id, hdm.core_holding_id, hdm.holding_no AS holding_no_main, hc.holding_no AS holding_no_core, ho_display.owner_names_concatenated as owner_name, COALESCE(dist_main.name_bn, dist_core.name_bn) as district_name, COALESCE(upa_main.name_bd, upa_core.name_bd) as upazila_name, COALESCE(mou_main.name_bd, mou_core.name_bd) as mouja_name, hdm.tax_clear_year, hdm.total_demand " . $sqlFromAndJoins . $sqlWhere . " ORDER BY hdm.auto_id DESC LIMIT ? OFFSET ? ";
$stmt = $mysqli->prepare($dataQuery);
if ($stmt === false) { $response = ['success' => false, 'message' => 'Error preparing data query: ' . $mysqli->error, 'query_debug' => $dataQuery, 'params_debug' => $dataParams, 'types_debug' => $dataTypes]; echo json_encode($response); exit; }
if (!empty($dataParams)) { $stmt->bind_param($dataTypes, ...$dataParams); }
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) { $data[] = $row; }
$stmt->close();

$response = [ 'success' => true, 'data' => $data, 'currentPage' => $page, 'totalPages' => $totalPages, 'totalRecords' => $totalRecords, 'limit' => $limit ];
echo json_encode($response);
exit;
?>

