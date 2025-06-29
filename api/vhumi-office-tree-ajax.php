<?php
header('Access-Control-Allow-Origin: *'); // Allow all origins for CORS (Adjust in production if needed)
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // Allow specific methods
header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Allow specific headers if needed
header('Content-Type: application/json; charset=utf-8'); // Set content type to JSON with UTF-8

// --- Database Configuration ---
$host = "172.188.89.41";
$username = "bdland";
$password = "8WF7LLRWHALsFjinvFU4";
$database = "bdland";
// --- Response Array ---
$response = [
    'success' => false,
    'message' => '',
    'data' => null, // Can be an array or object or null
    'query' => '', // For debugging - remove in production
    'params' => [], // For debugging - remove in production
    'error' => null // For debugging - remove in production
];

// --- Input Validation ---
$type = isset($_GET['type']) ? trim($_GET['type']) : 'root'; // Default to 'root'
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0; // Parent ID or Office ID

$valid_types = ['root', 'division', 'district', 'upazila', 'office', 'office_detail'];
if (!in_array($type, $valid_types)) {
    $response['message'] = 'Invalid type parameter.';
    $response['success'] = false; // Ensure success is false
    echo json_encode($response);
    exit;
}

// --- Database Connection ---
@$mysqli = new mysqli($host, $username, $password, $database);

// Check connection
if ($mysqli->connect_error) {
    $response['message'] = 'Database connection failed.';
    $response['success'] = false;
    // Avoid exposing detailed internal errors in production environments
    $response['error'] = (strpos($host, '127.0.0.1') !== false || strpos($host, 'localhost') !== false)
        ? $mysqli->connect_error : 'DB Connect Error';
    echo json_encode($response);
    exit;
}

// Set character set to UTF-8 for proper Bangla display
if (!$mysqli->set_charset("utf8mb4")) {
    $response['message'] = 'Error loading character set utf8mb4.';
    $response['success'] = false;
    $response['error'] = $mysqli->error;
    echo json_encode($response);
    $mysqli->close();
    exit;
}

// --- Query Building ---
$sql = "";
$params = [];
$param_types = ""; // s for string, i for integer
$is_single_result = false; // Flag for details request

try {
    switch ($type) {
        case 'root': // Fetch Divisions
            $sql = "SELECT division_id AS id, name_bn AS name, 'division' AS type FROM ldtax_divisions WHERE stat = 1 ORDER BY name_bn";
            break;

        case 'division': // Fetch Districts for a Division
            $sql = "SELECT district_id AS id, name_bn AS name, 'district' AS type FROM ldtax_districts WHERE division_id = ? AND stat = 1 ORDER BY name_bn";
            $params = [$id];
            $param_types = "i";
            break;

        case 'district': // Fetch Upazilas for a District
            $sql = "SELECT upazila_id AS id, name_bn AS name, 'upazila' AS type FROM ldtax_upazilas WHERE district_id = ? AND stat = 1 ORDER BY name_bn";
            $params = [$id];
            $param_types = "i";
            break;

        case 'upazila': // Fetch Union/Municipality Land Offices for an Upazila
            // Assuming base_office_id = 6 identifies these based on your data
            $sql = "SELECT office_id AS id, title_bn AS name, 'office' AS type
                    FROM ldtax_offices
                    WHERE upazila_id = ? AND base_office_id = 6 AND status = 1 AND stat = 1
                    ORDER BY title_bn";
            $params = [$id];
            $param_types = "i";
            break;

        case 'office': // Fetch Moujas for an Office (for tree expansion)
            $sql = "SELECT mouja_id AS id, CONCAT(name_bn, IFNULL(CONCAT(' (JL: ', jl_no, ')'), '')) AS name, 'mouja' AS type
                    FROM ldtax_moujas
                    WHERE office_id = ? AND stat = 0 -- Check if stat=0 is correct for active moujas
                    ORDER BY name_bn";
            $params = [$id];
            $param_types = "i";
            break;

        case 'office_detail': // Fetch specific office details
            $sql = "SELECT
                        o.*, -- Select all columns from ldtax_offices
                        up.name_bn AS upazila_name_bn,
                        d.name_bn AS district_name_bn,
                        dv.name_bn AS division_name_bn
                     FROM ldtax_offices o
                     LEFT JOIN ldtax_upazilas up ON o.upazila_id = up.upazila_id AND up.stat = 1
                     LEFT JOIN ldtax_districts d ON o.district_id = d.district_id AND d.stat = 1
                     LEFT JOIN ldtax_divisions dv ON o.division_id = dv.division_id AND dv.stat = 1
                     WHERE o.office_id = ? AND o.stat = 1 -- Ensure office itself is valid
                     LIMIT 1";
            $params = [$id]; // Here, $id is the office_id
            $param_types = "i";
            $is_single_result = true; // Expecting one row
            break;
    }

    $response['query'] = $sql; // Debugging
    $response['params'] = $params; // Debugging

    if (!empty($sql)) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            $response['message'] = 'Database query preparation failed.';
            $response['error'] = $mysqli->error;
            $response['success'] = false;
        } else {
            if (!empty($params)) {
                // Dynamically bind parameters
                if (!$stmt->bind_param($param_types, ...$params)) {
                    $response['message'] = 'Binding parameters failed.';
                    $response['error'] = $stmt->error;
                    $response['success'] = false;
                    $stmt->close(); // Close stmt before exiting
                    echo json_encode($response);
                    exit;
                }
            }

            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $fetched_data = null;

                if ($is_single_result) {
                    // Fetch single row for details
                    $fetched_data = $result->fetch_assoc();
                    if ($fetched_data === null) {
                        $response['message'] = 'Office details not found for the given ID.';
                        // Keep success false, data null
                    } else {
                        $response['success'] = true;
                        $response['message'] = 'Office details fetched successfully.';
                    }
                } else {
                    // Fetch multiple rows for tree nodes
                    $fetched_data = [];
                    while ($row = $result->fetch_assoc()) {
                        // Determine if node has children (only for tree nodes)
                        if ($type !== 'office_detail') {
                            // Simplified: Assume non-moujas might have children.
                            // Offices need hasChildren=true to allow Mouja expansion.
                            $row['hasChildren'] = ($type !== 'mouja');
                        }
                        $fetched_data[] = $row;
                    }
                    // Even if array is empty, the fetch itself was successful
                    $response['success'] = true;
                    $response['message'] = 'Data fetched successfully.';
                    if (empty($fetched_data)) {
                        $response['message'] = 'No data found for this level.';
                    }
                }

                $response['data'] = $fetched_data;

            } else {
                $response['message'] = 'Database query execution failed.';
                $response['error'] = $stmt->error;
                $response['success'] = false;
            }
            $stmt->close();
        }
    } else {
        // This case should not be hit due to initial validation, but as a fallback:
        $response['message'] = 'No query defined for the given type.';
        $response['success'] = false;
    }

} catch (Exception $e) {
    $response['message'] = 'An unexpected error occurred.';
    $response['error'] = $e->getMessage();
    $response['success'] = false;
    // Avoid exposing detailed exception messages in production
    if (strpos($host, '127.0.0.1') === false && strpos($host, 'localhost') === false) {
        $response['error'] = 'Server Processing Error';
    }
}

// --- Close Connection ---
$mysqli->close();

// Optionally remove debug info for production
// unset($response['query']);
// unset($response['params']);
// unset($response['error']);

echo json_encode($response);
exit;
?>