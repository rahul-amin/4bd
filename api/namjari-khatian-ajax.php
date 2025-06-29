<?php
/* ================================================================ */
/* PHP API Backend Logic                                            */
/* ================================================================ */

// --- Error Reporting & Headers (Keep as before) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit(); }

// --- Database Configuration (REMINDER: Move credentials out) ---
$host = "172.188.89.41"; $username = "bdland"; $password = "8WF7LLRWHALsFjinvFU4"; $database = "bdland";

// --- Database Connection (Keep as before) ---
$mysqli = @new mysqli($host, $username, $password, $database);
if ($mysqli->connect_errno) { http_response_code(500); error_log("DB Connect failed: #" . $mysqli->connect_errno . " - " . $mysqli->connect_error); echo json_encode(['success' => false, 'message' => "System Error: DB Connect.", 'data' => null], JSON_UNESCAPED_UNICODE); exit(); }
if (!$mysqli->set_charset('utf8mb4')) { http_response_code(500); error_log("Error loading charset utf8mb4: " . $mysqli->error); echo json_encode(['success' => false, 'message' => "System Error: DB Charset.", 'data' => null], JSON_UNESCAPED_UNICODE); $mysqli->close(); exit(); }

// Sanitize output function
function sanitize_output($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }

// --- Input Handling ---
$action = $_GET['action'] ?? null;
$response = ['success' => false, 'message' => 'Invalid action.', 'data' => []];

// --- Action Routing ---
if ($action) {
    try {
        switch ($action) {

            // --- get_divisions case (Keep as before with duplicate handling) ---
            case 'get_divisions':
                $sql = "SELECT DISTINCT division_name, division_bbs_code FROM namjari_mouzas WHERE division_bbs_code IS NOT NULL AND division_name IS NOT NULL AND division_name != '' ORDER BY division_bbs_code, division_name ASC";
                $result = $mysqli->query($sql); if (!$result) throw new Exception("DB Error divisions: " . $mysqli->error);
                $divisions_raw = []; while ($row = $result->fetch_assoc()) { $divisions_raw[] = $row; } $result->free();
                $divisions_unique_map = []; foreach ($divisions_raw as $row) { $bbs_code = $row['division_bbs_code']; $name = trim($row['division_name']); if (!isset($divisions_unique_map[$bbs_code])) { $divisions_unique_map[$bbs_code] = sanitize_output($name); } }
                $divisions_final = []; foreach ($divisions_unique_map as $bbs_code => $name) { $divisions_final[] = ['bbs_code' => $bbs_code, 'name' => $name]; }
                usort($divisions_final, fn($a, $b) => strcoll($a['name'], $b['name']));
                $response = ['success' => true, 'message' => 'Divisions fetched.', 'data' => $divisions_final];
                break;

            // --- get_districts case (Keep as before with duplicate handling) ---
            case 'get_districts':
                $divisionBbs = isset($_GET['division_bbs']) ? intval($_GET['division_bbs']) : 0; if (!$divisionBbs) throw new Exception("Division BBS code is required.");
                $sql = "SELECT DISTINCT district_name, district_bbs_code FROM namjari_mouzas WHERE division_bbs_code = ? AND district_bbs_code IS NOT NULL AND district_name IS NOT NULL AND district_name != '' ORDER BY district_bbs_code, district_name ASC";
                $stmt = $mysqli->prepare($sql); if (!$stmt) throw new Exception("DB Prepare Error (Districts): " . $mysqli->error);
                $stmt->bind_param("i", $divisionBbs); if (!$stmt->execute()) throw new Exception("DB Execute Error (Districts): " . $stmt->error);
                $result = $stmt->get_result(); $districts_raw = []; while ($row = $result->fetch_assoc()) { $districts_raw[] = $row; } $stmt->close();
                $districts_unique_map = []; foreach ($districts_raw as $row) { $bbs_code = $row['district_bbs_code']; $name = trim($row['district_name']); if (!isset($districts_unique_map[$bbs_code])) { $districts_unique_map[$bbs_code] = sanitize_output($name); } }
                $districts_final = []; foreach ($districts_unique_map as $bbs_code => $name) { $districts_final[] = ['bbs_code' => $bbs_code, 'name' => $name]; }
                usort($districts_final, fn($a, $b) => strcoll($a['name'], $b['name']));
                $response = ['success' => true, 'message' => 'Districts fetched.', 'data' => $districts_final];
                break;

            // --- get_upazilas case (Keep as before, add duplicate check if needed) ---
            case 'get_upazilas':
                $divisionBbs = isset($_GET['division_bbs']) ? intval($_GET['division_bbs']) : 0; $districtBbs = isset($_GET['district_bbs']) ? intval($_GET['district_bbs']) : 0; if (!$divisionBbs || !$districtBbs) throw new Exception("Division and District BBS codes are required.");
                $sql = "SELECT DISTINCT upazila_name, upazila_bbs_code FROM namjari_mouzas WHERE division_bbs_code = ? AND district_bbs_code = ? AND upazila_bbs_code IS NOT NULL AND upazila_name IS NOT NULL AND upazila_name != '' ORDER BY upazila_name ASC";
                $stmt = $mysqli->prepare($sql); if (!$stmt) throw new Exception("DB Prepare Error (Upazilas): " . $mysqli->error);
                $stmt->bind_param("ii", $divisionBbs, $districtBbs); if (!$stmt->execute()) throw new Exception("DB Execute Error (Upazilas): " . $stmt->error);

                $bbscodes = [];
                $result = $stmt->get_result(); $upazilas = [];

                while ($row = $result->fetch_assoc()) {
                    if(in_array($row['upazila_bbs_code'],$bbscodes))
                    {
                        continue;
                    }
                    $bbscodes[$row['upazila_bbs_code']] = $row['upazila_bbs_code'];


                    $upazilas[] = ['bbs_code' => $row['upazila_bbs_code'], 'name' => sanitize_output(trim($row['upazila_name']))];
                } $stmt->close();
                $response = ['success' => true, 'message' => 'Upazilas fetched.', 'data' => $upazilas];
                break;

            // --- get_mouzas case (Keep as before) ---
            case 'get_mouzas':
                $districtBbs = isset($_GET['district_bbs']) ? intval($_GET['district_bbs']) : 0; $upazilaBbs = isset($_GET['upazila_bbs']) ? intval($_GET['upazila_bbs']) : 0; $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
                if (!$districtBbs || !$upazilaBbs) throw new Exception("District and Upazila BBS codes are required.");
                $params = [$districtBbs, $upazilaBbs]; $types = "ii";
                $sql = "SELECT source_id, mouza_name, jl_number FROM namjari_mouzas WHERE district_bbs_code = ? AND upazila_bbs_code = ? ";
                if (!empty($searchTerm)) { $searchTermWild = '%' . $searchTerm . '%'; $sql .= " AND (jl_number LIKE ? OR mouza_name LIKE ?) "; $params[] = $searchTermWild; $params[] = $searchTermWild; $types .= "ss"; }
                $sql .= " ORDER BY CAST(jl_number AS UNSIGNED), jl_number, mouza_name ASC LIMIT 300";
                $stmt = $mysqli->prepare($sql); if (!$stmt) throw new Exception("DB Prepare Error (Mouzas): " . $mysqli->error);
                $stmt->bind_param($types, ...$params); if (!$stmt->execute()) throw new Exception("DB Execute Error (Mouzas): " . $stmt->error);
                $result = $stmt->get_result(); $mouzas = [];
                while ($row = $result->fetch_assoc()) { $jl = $row['jl_number'] ?? '?'; $name = trim($row['mouza_name'] ?? 'Unknown'); $mouzas[] = ['id' => $row['source_id'], 'name' => sanitize_output($jl) . ' - ' . sanitize_output($name)]; }
                $stmt->close();
                $response = ['success' => true, 'message' => 'Mouzas fetched.', 'data' => $mouzas];
                break;


            // --- get_khatians case (MODIFIED) ---
            case 'get_khatians':
                $mouzaJlId = isset($_GET['mouza_jl_id']) ? intval($_GET['mouza_jl_id']) : 0;
                $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
                // $surveyType = isset($_GET['survey_type']) ? trim($_GET['survey_type']) : 'MUTATION'; // No longer needed

                if (!$mouzaJlId) {
                    throw new Exception("Mouza JL ID (source_id) is required.");
                }
                // if (empty($surveyType)) { throw new Exception("Survey type is required."); } // No longer needed

                // Parameters only include mouza ID now
                $params = [$mouzaJlId];
                $types = "i";

                // Select fields
                $sql = "SELECT source_khatian_id, khatian_no, owners, dags, total_land, guardians, canonical_khatian_no, survey_type, mouza_id
                        FROM namjari_khatians
                        WHERE jl_number_id = ? "; // REMOVED: AND survey_type = ?

                // Add search condition if present
                if (!empty($searchTerm)) {
                    $searchTermWild = '%' . $searchTerm . '%';
                    $sql .= " AND (khatian_no LIKE ? OR canonical_khatian_no LIKE ? OR owners LIKE ?) ";
                    $params[] = $searchTermWild;
                    $params[] = $searchTermWild;
                    $params[] = $searchTermWild;
                    $types .= "sss"; // Types for the search parameters
                }

                $sql .= " ORDER BY CAST(khatian_no AS UNSIGNED), khatian_no ASC LIMIT 5000"; // Limit results

                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("DB Prepare Error (Khatians): " . $mysqli->error);

                $stmt->bind_param($types, ...$params); // Bind parameters
                if (!$stmt->execute()) throw new Exception("DB Execute Error (Khatians): " . $stmt->error);
                $result = $stmt->get_result();

                $khatians = [];
                while ($row = $result->fetch_assoc()) {
                    $khatians[] = [
                        'id' => $row['source_khatian_id'],
                        'khatian_no' => sanitize_output($row['khatian_no']),
                        'canonical_khatian_no' => sanitize_output($row['canonical_khatian_no']),
                        'owners' => sanitize_output($row['owners']),
                        'guardians' => sanitize_output($row['guardians']),
                        'dags' => sanitize_output($row['dags']),
                        'total_land' => $row['total_land'],
                        'mouza_id' => $row['mouza_id'],
                        // Optional: Include survey_type in response if you want to display it
                         'survey_type' => sanitize_output($row['survey_type'])
                    ];
                }
                $stmt->close();
                $response = ['success' => true, 'message' => 'Khatians fetched (all survey types).', 'data' => $khatians];
                break; // End case 'get_khatians'


            default:
                http_response_code(400);
                throw new Exception("Invalid action specified.");
        }
    } catch (Exception $e) {
        error_log("AJAX Action Error (" . ($action ?? 'N/A') . "): " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Server Error: ' . sanitize_output($e->getMessage()), 'data' => []];
        if (http_response_code() == 200) { http_response_code(500); }
    }
} else {
    http_response_code(400);
    $response['message'] = 'No action specified.';
}

// --- Close Connection ---
if ($mysqli) {
    $mysqli->close();
}

// --- Output JSON ---
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
?>