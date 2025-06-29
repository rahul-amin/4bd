<?php

// --- CORS Configuration ---
// IMPORTANT: In production, replace '*' with your specific frontend domain(s)
$allowed_origins = ['*']; // Example: ['https://yourdomain.com', 'https://www.yourdomain.com']
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowed_origins) || in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*')); // Send back specific origin if matched, or '*' if allowed
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Add any other headers your frontend sends
    // header('Access-Control-Allow-Credentials: true'); // Uncomment if you use cookies/sessions
}

// Handle OPTIONS preflight request (sent before POST/GET with custom headers/credentials)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // CORS headers already sent above if origin is allowed
    http_response_code(204); // No Content
    exit();
}

// --- JSON Response Header ---
// Set AFTER handling CORS/OPTIONS to avoid issues
header('Content-Type: application/json; charset=utf-8');

// --- Database Connection ---
// CRITICAL SECURITY: Use environment variables or a secure config file OUTSIDE web root.
// Example using environment variables (requires a library like phpdotenv):
// require 'vendor/autoload.php';
// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__); // Adjust path as needed
// $dotenv->safeLoad(); // Use safeLoad to avoid errors if .env is missing
// $host = $_ENV['DB_HOST'] ?? "172.188.89.41"; // Fallback only for example
// $username = $_ENV['DB_USER'] ?? "bdland";
// $password = $_ENV['DB_PASS'] ?? "8WF7LLRWHALsFjinvFU4";
// $database = $_ENV['DB_NAME'] ?? "bdland";

// --- !! TEMPORARY HARDCODED CREDENTIALS (REPLACE ASAP) !! ---
$host = "172.188.89.41";
$username = "bdland";
$password = "8WF7LLRWHALsFjinvFU4";
$database = "bdland";
$mysqli = new mysqli($host, $username, $password, $database);

// Check connection
if ($mysqli->connect_errno) {
    error_log("Database connection failed: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    echo json_encode(['success' => false, 'message' => "Database connection error. Please try again later."]); // User-friendly message
    exit();
}

// Set charset AFTER connection
if (!$mysqli->set_charset('utf8mb4')) {
    error_log("Error loading character set utf8mb4: " . $mysqli->error);
    echo json_encode(['success' => false, 'message' => "Database character set error."]);
    $mysqli->close();
    exit();
}
// --- End Database Connection ---

// Consider moving this to a database table or a config file
$SURVEY_types = [
    1 => ['সি এস', 'CS', 4],
    2 => ['আর এস', 'RS', 1],
    3 => ['এস এ', 'SA', 3],
    4 => ['বি এস', 'BS', 2],
    5 => ['দিয়ারা', 'DIARA', 3],
    6 => ['পেটি', 'PETY', 4],
    7 => ['বি আর এস', 'BRS', 2],
    8 => ['বি ডি এস', 'BDS', 6],
];

$action = $_REQUEST['action'] ?? null;
$response = ['success' => false, 'message' => 'Invalid action specified', 'data' => []];

try { // Wrap main logic

    switch ($action) {
        // --- Get Divisions ---
        case 'get_divisions':
            $result = $mysqli->query("SELECT id, name, name_en FROM dlrms_divisions WHERE row_status = 1 ORDER BY name_en");
            if ($result) {
                $response['data'] = $result->fetch_all(MYSQLI_ASSOC);
                $response['success'] = true;
                $response['message'] = 'Divisions fetched successfully';
            } else {
                $response['message'] = 'Error fetching divisions: ' . $mysqli->error;
            }
            break;

        // --- Get Districts ---
        case 'get_districts':
            $division_id = filter_input(INPUT_GET, 'division_id', FILTER_VALIDATE_INT);
            if (!$division_id) {
                $response['message'] = 'Invalid or missing Division ID';
                break;
            }
            $stmt = $mysqli->prepare("SELECT id, name, name_en, bbs_code FROM dlrms_districts WHERE division_id = ? AND row_status = 1 ORDER BY name_en");
            if ($stmt) {
                $stmt->bind_param("i", $division_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $response['data'] = $result->fetch_all(MYSQLI_ASSOC);
                    $response['success'] = true;
                    $response['message'] = 'Districts fetched successfully';
                } else {
                    $response['message'] = 'Error executing district query: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Error preparing district query: ' . $mysqli->error;
            }
            break;

        // --- Get Upazilas/Circles ---
        case 'get_upazilas':
            $district_id = filter_input(INPUT_GET, 'district_id', FILTER_VALIDATE_INT);
            if (!$district_id) {
                $response['message'] = 'Invalid or missing District ID';
                break;
            }
            $stmt = $mysqli->prepare("SELECT id, name, name_en, bbs_code, is_circle FROM dlrms_upazilas WHERE district_id = ? AND row_status = 1 ORDER BY name");
            if ($stmt) {
                $stmt->bind_param("i", $district_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $response['data'] = $result->fetch_all(MYSQLI_ASSOC);
                    $response['success'] = true;
                    $response['message'] = 'Upazilas fetched successfully';
                } else {
                    $response['message'] = 'Error executing upazila query: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Error preparing upazila query: ' . $mysqli->error;
            }
            break;

        // --- Get Surveys Available ---
        case 'get_surveys':
            $district_bbs_code = filter_input(INPUT_GET, 'district_bbs_code', FILTER_SANITIZE_STRING);
            $upazila_bbs_code = filter_input(INPUT_GET, 'upazila_bbs_code', FILTER_SANITIZE_STRING);
            if (empty($district_bbs_code) || empty($upazila_bbs_code)) {
                $response['message'] = 'Invalid or missing BBS codes';
                break;
            }
            // Assuming dlrms_surveys contains the needed info (id, name, order)
            // And dlrms_mouza_jl_numbers links surveys to locations
            $stmt = $mysqli->prepare("
                SELECT DISTINCT s.survey_id, s.local_name, s.survey_order
                FROM dlrms_surveys s
                JOIN dlrms_mouza_jl_numbers mj ON s.survey_id = mj.survey_id
                WHERE mj.district_bbs_code = ? AND mj.upazila_bbs_code = ?
                -- AND s.row_status = 1 -- If surveys table has a status flag
                ORDER BY s.survey_order, s.local_name
             ");
            if ($stmt) {
                $stmt->bind_param("ss", $district_bbs_code, $upazila_bbs_code);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $response['data'] = $result->fetch_all(MYSQLI_ASSOC);
                    $response['success'] = true;
                    $response['message'] = 'Available surveys fetched successfully';
                } else {
                    $response['message'] = 'Error executing survey query: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Error preparing survey query: ' . $mysqli->error;
            }
            break;

        // --- Get Mouzas/JL Numbers ---
        case 'get_mouzas':
            $district_bbs_code = filter_input(INPUT_GET, 'district_bbs_code', FILTER_SANITIZE_STRING);
            $upazila_bbs_code = filter_input(INPUT_GET, 'upazila_bbs_code', FILTER_SANITIZE_STRING);
            $survey_id = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
            if (empty($district_bbs_code) || empty($upazila_bbs_code) || empty($survey_id)) {
                $response['message'] = 'Invalid or missing parameters for mouza';
                break;
            }
            // Validate Survey ID exists in our config/DB before using it if needed elsewhere
            if (!isset($SURVEY_types[$survey_id])) {
                $response['message'] = 'Invalid Survey ID provided for Mouza lookup';
                break;
            }

            $stmt = $mysqli->prepare("
                SELECT id, mouza_name, jl_number
                FROM dlrms_mouza_jl_numbers
                WHERE district_bbs_code = ? AND upazila_bbs_code = ? AND survey_id = ?
                -- AND row_status = 1 -- If mouza table has a status flag
                ORDER BY CAST(jl_number AS UNSIGNED), jl_number, mouza_name
             ");
            if ($stmt) {
                $stmt->bind_param("ssi", $district_bbs_code, $upazila_bbs_code, $survey_id);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $response['data'] = $result->fetch_all(MYSQLI_ASSOC);
                    $response['success'] = true;
                    $response['message'] = 'Mouzas fetched successfully';
                } else {
                    $response['message'] = 'Error executing mouza query: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Error preparing mouza query: ' . $mysqli->error;
            }
            break;

        // --- Get Khatians (Pagination, Server Search) ---
        case 'get_khatians':
            $mouza_jl_id = filter_input(INPUT_GET, 'mouza_jl_id', FILTER_VALIDATE_INT);
            $survey_id = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);

            // Validate Survey ID and get table suffix
            if (!isset($SURVEY_types[$survey_id])) {
                $response['message'] = 'Invalid or unsupported Survey ID';
                break;
            }
            $survey_name = $SURVEY_types[$survey_id][1]; // Get 'CS', 'RS' etc.

            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $pageSize = filter_input(INPUT_GET, 'pageSize', FILTER_VALIDATE_INT, ['options' => ['default' => 2000, 'min_range' => 1, 'max_range' => 2000]]); // Max page size limit
            $khatian_search = filter_input(INPUT_GET, 'khatian_search', FILTER_SANITIZE_STRING);
            $dag_search = filter_input(INPUT_GET, 'dag_search', FILTER_SANITIZE_STRING);

            $khatian_search_term = (!empty($khatian_search)) ? "%" . $mysqli->real_escape_string(trim($khatian_search)) . "%" : null;
            $dag_search_term = (!empty($dag_search)) ? "%" . $mysqli->real_escape_string(trim($dag_search)) . "%" : null;

            $responseDataTemplate = [
                'khatians' => [], 'totalItems' => 0, 'totalPages' => 0, 'currentPage' => $page, 'pageSize' => $pageSize
            ];
            $response['data'] = $responseDataTemplate; // Initialize data structure

            if (!$mouza_jl_id) {
                $response['message'] = 'Invalid or missing Mouza/JL ID';
                break;
            }

            $offset = ($page - 1) * $pageSize;
            $totalItems = 0;
            $totalPages = 0;

            // --- Build Dynamic WHERE Clause and Params ---
            // Base condition
            $whereClause = "jl_number_id = ?";
            $params = [$mouza_jl_id];
            $types = "i";

            // Add search conditions
            if ($khatian_search_term !== null) {
                $whereClause .= " AND khatian_no LIKE ?";
                $params[] = $khatian_search_term;
                $types .= "s";
            }
            if ($dag_search_term !== null) {
                // WARNING: LIKE on comma-separated values is inefficient.
                // Consider schema normalization or FULLTEXT index for performance.
                $whereClause .= " AND dags LIKE ?";
                $params[] = $dag_search_term;
                $types .= "s";
            }

            // --- Get total count ---
            $countSql = "SELECT COUNT(*) as total FROM dlrms_khatians_{$survey_name} WHERE " . $whereClause;
            $countStmt = $mysqli->prepare($countSql);

            if ($countStmt) {
                // Bind params only if there are any (jl_number_id is always present)
                $countStmt->bind_param($types, ...$params);

                if ($countStmt->execute()) {
                    $countResult = $countStmt->get_result();
                    $totalItems = (int) $countResult->fetch_assoc()['total'];
                    $totalPages = $totalItems > 0 ? ceil($totalItems / $pageSize) : 0;
                    // Update response data with counts
                    $response['data']['totalItems'] = $totalItems;
                    $response['data']['totalPages'] = $totalPages;
                } else {
                    $response['message'] = 'Error executing count query: ' . $countStmt->error;
                    $countStmt->close();
                    break; // Stop processing this case
                }
                $countStmt->close();
            } else {
                $response['message'] = 'Error preparing count query: ' . $mysqli->error . ' SQL: ' . $countSql;
                break; // Stop processing this case
            }
            // --- End count ---

            // --- Get paginated data ---
            if ($totalItems > 0 && $offset < $totalItems) {
                // Prepare params for data query (add LIMIT and OFFSET)
                $dataParams = $params; // Start with search params
                $dataParams[] = $pageSize;
                $dataParams[] = $offset;
                $dataTypes = $types . "ii"; // Add types for LIMIT and OFFSET

                $dataSql = "SELECT id, jl_number_id, mouza_id, khatian_no, office_id, khatian_entry_id, dags, owners, guardians, total_land
                             FROM dlrms_khatians_{$survey_name}
                             WHERE " . $whereClause . "
                             ORDER BY CAST(khatian_no AS UNSIGNED), khatian_no, id
                             LIMIT ? OFFSET ?";
                $stmt = $mysqli->prepare($dataSql);

                if ($stmt) {
                    $stmt->bind_param($dataTypes, ...$dataParams);

                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $fetched_khatians = $result->fetch_all(MYSQLI_ASSOC);

                        // Basic validation/filtering (optional, ideally handled by DB constraints)
                        $valid_khatians = array_filter($fetched_khatians, function($k) {
                            return isset($k['id']) && $k['id'] !== null && $k['id'] !== '';
                        });

                        $response['data']['khatians'] = array_values($valid_khatians); // Re-index array
                        $response['success'] = true;
                        $response['message'] = 'Khatians fetched successfully';

                    } else {
                        $response['message'] = 'Error executing khatian query: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $response['message'] = 'Error preparing khatian query: ' . $mysqli->error . ' SQL: ' . $dataSql;
                }
            } else {
                // Handle cases where totalItems is 0 or page is out of bounds based on search
                $searchActive = ($khatian_search_term !== null || $dag_search_term !== null);
                $response['success'] = true; // Not an error, just no data for this page/search
                $response['message'] = $totalItems === 0
                    ? ($searchActive ? 'আপনার অনুসন্ধানে কোন খতিয়ান পাওয়া যায়নি।' : 'এই মৌজার জন্য কোন খতিয়ান পাওয়া যায়নি।')
                    : 'Khatians fetched successfully (empty page)';
                // data['khatians'] is already []
            }
            break; // End case 'get_khatians'

        // --- Default Case ---
        default:
            // Keep default error message: 'Invalid action specified'
            $response['data'] = []; // Ensure data is empty
            break;
    }

} catch (mysqli_sql_exception $e) {
    error_log("Database Error: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $response['success'] = false;
    $response['message'] = 'A database error occurred. Please check logs.';
    $response['data'] = [];
} catch (Exception $e) {
    error_log("General Error in ajax.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $response['success'] = false;
    $response['message'] = 'An unexpected server error occurred.';
    $response['data'] = [];
}

// Close the connection if it's still open
if ($mysqli instanceof mysqli && $mysqli->thread_id) {
    $mysqli->close();
}

// Send the JSON response
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit();

?>