<?php
// emouza-map-ajax.php

// --- CORS Configuration ---
$allowed_origins = ['*'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowed_origins) || in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit();
}

header('Content-Type: application/json');

// Correct path to config.php
// Assuming ajax.php is in /api/ and config.php is in the root directory
if (file_exists(__DIR__ . '/../config.php')) {
    include_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) { // If config.php is in the same directory as this ajax file
    include_once __DIR__ . '/config.php';
} else {
    echo json_encode(['status' => 'error', 'message' => 'Configuration file not found. Path: ' . __DIR__]);
    exit;
}


$action = $_GET['action'] ?? '';
$response = ['status' => 'error', 'message' => 'Invalid action.', 'data' => []];

// Helper function
function fetch_all_prepared($mysqli, $sql, $params = [], $types = "") {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error . " SQL: " . $sql);
        return false;
    }
    if (!empty($params) && !empty($types)) {
        if (!$stmt->bind_param($types, ...$params)) {
            error_log("Bind_param failed: (" . $stmt->errno . ") " . $stmt->error);
            $stmt->close();
            return false;
        }
    }
    if (!$stmt->execute()) {
        error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log("Get_result failed: (" . $stmt->errno . ") " . $stmt->error);
        $stmt->close();
        return false;
    }
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

switch ($action) {
    case 'get_divisions':
        // CORRECTED: Removed bbs_code if it doesn't exist
        $sql = "SELECT id, name FROM emouza_divisions ORDER BY name ASC";
        $data = fetch_all_prepared($mysqli, $sql);
        if ($data !== false) {
            $response = ['status' => 'success', 'message' => 'Divisions fetched.', 'data' => $data];
        } else {
            $response['message'] = 'Failed to fetch divisions.';
        }
        break;

    case 'get_districts':
        $division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;
        if ($division_id > 0) {
            // CORRECTED: Removed bbs_code if it doesn't exist
            $sql = "SELECT id, name FROM emouza_districts WHERE division_id = ? ORDER BY name ASC";
            $data = fetch_all_prepared($mysqli, $sql, [$division_id], "i");
            if ($data !== false) {
                $response = ['status' => 'success', 'message' => 'Districts fetched.', 'data' => $data];
            } else {
                $response['message'] = 'Failed to fetch districts for division ID: ' . $division_id;
            }
        } else {
            $response['message'] = 'Division ID is required.';
        }
        break;

    case 'get_upazilas':
        $district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
        if ($district_id > 0) {
            // CORRECTED: Removed bbs_code if it doesn't exist
            $sql = "SELECT id, name FROM emouza_upazilas WHERE district_id = ? ORDER BY name ASC";
            $data = fetch_all_prepared($mysqli, $sql, [$district_id], "i");
            if ($data !== false) {
                $response = ['status' => 'success', 'message' => 'Upazilas fetched.', 'data' => $data];
            } else {
                $response['message'] = 'Failed to fetch upazilas for district ID: ' . $district_id;
            }
        } else {
            $response['message'] = 'District ID is required.';
        }
        break;

    case 'get_mouzas':
        $upazila_id = isset($_GET['upazila_id']) ? (int)$_GET['upazila_id'] : 0;
        if ($upazila_id > 0) {
            $mouzas_sql = "SELECT id, name, jl_number FROM emouza_mouzas WHERE upazila_id = ? ORDER BY CAST(jl_number AS UNSIGNED), name ASC";
            $mouzas_data = fetch_all_prepared($mysqli, $mouzas_sql, [$upazila_id], "i");

            // This query assumes survey types are linked to upazilas via maps existing for them.
            $survey_types_sql = "SELECT DISTINCT st.id, st.folder_name, st.google_drive_folder_id 
                                 FROM emouza_survey_types st
                                 JOIN emouza_map_files mf ON st.id = mf.survey_type_id
                                 JOIN emouza_mouzas m ON mf.mouza_id = m.id
                                 WHERE m.upazila_id = ? 
                                 ORDER BY st.folder_name ASC";
            $survey_types_data = fetch_all_prepared($mysqli, $survey_types_sql, [$upazila_id], "i");

            if ($mouzas_data !== false && $survey_types_data !== false) {
                $response = ['status' => 'success', 'message' => 'Mouzas and Survey Types fetched.', 'data' => ['mouzas' => $mouzas_data, 'survey_types' => $survey_types_data]];
            } else {
                $response['message'] = 'Failed to fetch mouzas or survey types for upazila ID: ' . $upazila_id;
            }
        } else {
            $response['message'] = 'Upazila ID is required.';
        }
        break;

    case 'get_map_files':
        $mouza_id = isset($_GET['mouza_id']) ? (int)$_GET['mouza_id'] : 0;
        $survey_type_id = isset($_GET['survey_type_id']) ? (int)$_GET['survey_type_id'] : 0;
        $upazila_id_for_survey_maps = isset($_GET['upazila_id_for_survey_maps']) ? (int)$_GET['upazila_id_for_survey_maps'] : 0;

        if ($survey_type_id > 0 && ($mouza_id > 0 || $upazila_id_for_survey_maps > 0) ) {
            $sql = "SELECT mf.id, mf.file_name, mf.thumbnail_link, mf.google_drive_file_id, mf.size, m.name as mouza_name, m.jl_number as mouza_jl_number 
                    FROM emouza_map_files mf
                    INNER JOIN emouza_mouzas m ON mf.mouza_id = m.id ";
            $params = [];
            $types = "";

            if ($mouza_id > 0) {
                $sql .= " WHERE mf.mouza_id = ? AND mf.survey_type_id = ? ";
                $params[] = $mouza_id; $params[] = $survey_type_id; $types .= "ii";
            } elseif ($upazila_id_for_survey_maps > 0) {
                $sql .= " WHERE m.upazila_id = ? AND mf.survey_type_id = ? ";
                $params[] = $upazila_id_for_survey_maps; $params[] = $survey_type_id; $types .= "ii";
            }
            $sql .= " ORDER BY m.jl_number ASC, mf.file_name ASC";

            $data = fetch_all_prepared($mysqli, $sql, $params, $types);
            if ($data !== false) {
                $response = ['status' => 'success', 'message' => 'Map files fetched.', 'data' => $data];
            } else {
                $response['message'] = 'Failed to fetch map files. SQL error might have occurred.';
            }
        } else {
            $response['message'] = 'Survey Type ID and either a Mouza ID or an Upazila ID are required.';
        }
        break;

    default:
        $response['message'] = 'Unknown action: ' . $action;
        break;
}

if (isset($mysqli) && $mysqli instanceof mysqli && $mysqli->thread_id) {
    $mysqli->close();
}

echo json_encode($response);
exit;