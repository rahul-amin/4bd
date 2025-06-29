<?php

header('Access-Control-Allow-Origin: *'); // Allow all origins for CORS
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // Allow specific methods

header('Content-Type: application/json; charset=utf-8');
$stattime = microtime(1);
$host = "172.188.89.41";
$username = "bdland";
$password = "8WF7LLRWHALsFjinvFU4";
$database = "bdland";

// --- Database Connection ---
$mysqli = new mysqli($host, $username, $password, $database);
if ($mysqli->connect_errno) {
    echo json_encode(['success' => false, 'message' => "DB Connect failed: " . $mysqli->connect_error, 'data' => null]);
    exit();
}
if (!$mysqli->set_charset('utf8mb4')) {
    echo json_encode(['success' => false, 'message' => "Error loading charset utf8mb4: " . $mysqli->error, 'data' => null]);
    $mysqli->close();
    exit();
}

// --- Input Sanitization & Initialization ---
$owner_search = filter_input(INPUT_GET, 'owner_search', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
$guardian_search = filter_input(INPUT_GET, 'guardian_search', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
$selected_survey_id = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$pageSize = filter_input(INPUT_GET, 'pageSize', FILTER_VALIDATE_INT, ['options' => ['default' => 20, 'min_range' => 1]]);
$action = $_GET['action'] ?? null;

$rowid = 0; // For caching

// --- Survey Mapping ---
$SURVEY_MAP = [
    1 => 'CS', 2 => 'RS', 3 => 'SA', 4 => 'BS',
    5 => 'DIARA', 6 => 'PETY', 7 => 'BRS', 8 => 'BDS',
];

// --- Response Template ---
$response = ['success' => false, 'message' => 'Invalid action', 'data' => []];
$searchDataTemplate = [
    'khatians' => [], 'totalItems' => 0, 'totalPages' => 0,
    'currentPage' => $page, 'pageSize' => $pageSize
];
$response['data'] = $searchDataTemplate;

try {
    switch ($action) {
        case 'search_by_name':
            $response['data']['currentPage'] = $page;
            $response['data']['pageSize'] = $pageSize;

            // --- Input Validation ---
            if (empty($selected_survey_id) || !isset($SURVEY_MAP[$selected_survey_id])) {
                $response['message'] = 'অনুগ্রহ করে একটি বৈধ জরিপের ধরণ নির্বাচন করুন।';
                break;
            }

            $trimmed_owner = trim($owner_search ?? '');
            $trimmed_guardian = trim($guardian_search ?? '');
            $has_owner_term = !empty($trimmed_owner);
            $has_guardian_term = !empty($trimmed_guardian);

            if (!$has_owner_term && !$has_guardian_term) {
                $response['success'] = true;
                $response['message'] = "অনুসন্ধানের জন্য মালিক অথবা অভিভাবকের নাম লিখুন।";
                break;
            }

            // --- Caching Logic (Check) ---
            // Caching key remains the same
            $selected_survey_idx_esc = $mysqli->real_escape_string($selected_survey_id);
            $owner_search_esc = $mysqli->real_escape_string($trimmed_owner);
            $guardian_search_esc = $mysqli->real_escape_string($trimmed_guardian);
            $current_time = time();
            $cache_expiry_seconds = 60 * 10; // 10 minutes cache
            $cache_expiry_seconds = 600000010; // 10 minutes cache

            $cacheSql = "SELECT id, resp, time FROM `dlrms_src_names` WHERE `survey_id` = '{$selected_survey_idx_esc}' and `owner` = '{$owner_search_esc}' and  `guardian` = '{$guardian_search_esc}' limit 1";
            $cacheQuery = $mysqli->query($cacheSql);

            if ($cacheQuery && $cacheQuery->num_rows > 0) {
                // ... (cache retrieval logic - unchanged) ...
                $row = $cacheQuery->fetch_assoc();
                $rowid = $row['id'];
                $cacheTime = (int)$row['time'];
                $cachedResponse = $row['resp'];
                if (!empty($cachedResponse) && ($current_time - $cacheTime) < $cache_expiry_seconds) {
                    $decodedCache = json_decode($cachedResponse, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($decodedCache['success'])) {
                        $endtime_cache = microtime(true);
                        $decodedCache['timetaken'] = round($endtime_cache - $stattime, 4);
                        $decodedCache['message'] .= " (Cached)";
                        echo json_encode($decodedCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        if ($mysqli instanceof mysqli && $mysqli->thread_id) { $mysqli->close(); }
                        exit();
                    }
                }
            } else {
                // ... (cache placeholder insertion - unchanged) ...
                $insertCacheSql = "INSERT INTO `dlrms_src_names`(`survey_id`, `owner`, `guardian`, `time`, `resp`) VALUES ('{$selected_survey_idx_esc}','{$owner_search_esc}','{$guardian_search_esc}','{$current_time}', NULL)
                                  ON DUPLICATE KEY UPDATE time = VALUES(time)";
                $mysqli->query($insertCacheSql);
                $rowid = $mysqli->insert_id ?: $rowid;
                if ($rowid == 0) {
                    $refetchCacheSql = "SELECT id FROM `dlrms_src_names` WHERE `survey_id` = '{$selected_survey_idx_esc}' and `owner` = '{$owner_search_esc}' and  `guardian` = '{$guardian_search_esc}' limit 1";
                    $refetchResult = $mysqli->query($refetchCacheSql);
                    if($refetchResult && $refetchResult->num_rows > 0){
                        $rowid = $refetchResult->fetch_assoc()['id'];
                        $refetchResult->free_result();
                    }
                }
            }
            if ($cacheQuery) $cacheQuery->free_result();


            // --- Prepare SQL based on provided terms ---
            $surveyKey = $SURVEY_MAP[$selected_survey_id];
            $khatianTableName = "dlrms_khatians_" . $surveyKey;

            $whereClauses = [];
            $params = [];
            $types = "";

            if ($has_owner_term && $has_guardian_term) {
                // **BOTH terms provided: Use LIKE for strict column matching**
                $whereClauses[] = "k.owners LIKE ?";
                $whereClauses[] = "k.guardians LIKE ?";
                // Prepare parameters for LIKE (add wildcards)
                $params[] = "%" . $mysqli->real_escape_string($trimmed_owner) . "%";
                $params[] = "%" . $mysqli->real_escape_string($trimmed_guardian) . "%";
                $types .= "ss"; // Two string parameters

            } elseif ($has_owner_term) {
                // **ONLY owner term provided: Use FULLTEXT for potential speed**
                $whereClauses[] = "MATCH(k.owners, k.guardians) AGAINST (? IN BOOLEAN MODE)";
                // Prepare parameter for AGAINST (require the term)
                $params[] = "+" . $mysqli->real_escape_string($trimmed_owner);
                $types .= "s"; // One string parameter

            } elseif ($has_guardian_term) {
                // **ONLY guardian term provided: Use FULLTEXT for potential speed**
                $whereClauses[] = "MATCH(k.owners, k.guardians) AGAINST (? IN BOOLEAN MODE)";
                // Prepare parameter for AGAINST (require the term)
                $params[] = "+" . $mysqli->real_escape_string($trimmed_guardian);
                $types .= "s"; // One string parameter
            }

            // Combine WHERE clauses (will be 'AND' if using LIKE, single clause if using MATCH)
            $whereSql = "WHERE " . implode(" AND ", $whereClauses);

            // --- Count Total Items ---
            $totalItems = 0;
            $totalPages = 0;
            $offset = ($page - 1) * $pageSize;

            $countSql = "SELECT COUNT(k.id) as total FROM {$khatianTableName} k {$whereSql}";
            $countStmt = $mysqli->prepare($countSql);
            if ($countStmt) {
                // Bind parameters based on the built $types and $params
                if (!empty($types)) {
                    $countStmt->bind_param($types, ...$params);
                }
                if ($countStmt->execute()) {
                    $countResult = $countStmt->get_result();
                    $totalItems = (int) $countResult->fetch_assoc()['total'];
                    $totalPages = $totalItems > 0 ? ceil($totalItems / $pageSize) : 0;
                    $response['data']['totalItems'] = $totalItems;
                    $response['data']['totalPages'] = $totalPages;
                } else {
                    $response['message'] = 'Error executing count: ' . $countStmt->error;
                    error_log('Error executing count: ' . $countStmt->error . ' SQL: ' . $countSql . ' Params: '. json_encode($params));
                    $countStmt->close();
                    break;
                }
                $countStmt->close();
            } else {
                $response['message'] = 'Error preparing count: ' . $mysqli->error;
                error_log('Error preparing count: ' . $mysqli->error . ' SQL: ' . $countSql);
                break;
            }

            // --- Fetch Khatian Data ---
            $khatiansData = [];
            if ($totalItems > 0 && $offset < $totalItems) {
                // Prepare parameters for the data query (same as count + LIMIT/OFFSET)
                $dataParams = $params; // Parameters for WHERE clause
                $dataTypes = $types;   // Types for WHERE clause
                $dataParams[] = $pageSize;
                $dataTypes .= "i";     // Add type for LIMIT
                $dataParams[] = $offset;
                $dataTypes .= "i";     // Add type for OFFSET

                $dataSql = "SELECT k.id as khatian_id, k.*, mj.mouza_name, mj.jl_number, mj.survey_id
                            FROM {$khatianTableName} k
                            INNER JOIN dlrms_mouza_jl_numbers mj ON k.jl_number_id = mj.id
                            {$whereSql}
                            ORDER BY k.id -- Or other relevant order
                            LIMIT ? OFFSET ?";
                $stmt = $mysqli->prepare($dataSql);
                if ($stmt) {
                    // Bind parameters (WHERE + LIMIT + OFFSET)
                    if (!empty($dataTypes)) {
                        $stmt->bind_param($dataTypes, ...$dataParams);
                    }
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $khatiansData = $result->fetch_all(MYSQLI_ASSOC);
                        $result->free_result();
                    } else {
                        $response['message'] = 'Error executing data query: ' . $stmt->error;
                        error_log('Error executing data query: ' . $stmt->error . ' SQL: ' . $dataSql . ' Params: '. json_encode($dataParams));
                    }
                    $stmt->close();
                } else {
                    $response['message'] = 'Error preparing data query: ' . $mysqli->error;
                    error_log('Error preparing data query: ' . $mysqli->error . ' SQL: ' . $dataSql);
                }
            }

            $response['data']['khatians'] = $khatiansData;
            $response['success'] = true;
            $response['message'] = $totalItems > 0 ? 'Search results fetched.' : 'No matching khatians found.';
            break; // End case 'search_by_name'

        default:
            $response['message'] = 'Invalid action specified';
            $response['data'] = [];
            break;
    }
} catch (mysqli_sql_exception $e) {
    // ... (Exception handling - unchanged) ...
    error_log("Database Error: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $response['success'] = false; $response['message'] = 'Database error occurred.'; $response['data'] = [];
} catch (Exception $e) {
    // ... (Exception handling - unchanged) ...
    error_log("General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $response['success'] = false; $response['message'] = 'Unexpected server error.'; $response['data'] = [];
}

// --- Calculate Time & Update Cache ---
$endtime = microtime(true);
$totaltime = $endtime - $stattime;
$response['timetaken'] = round($totaltime, 4);

$jsonData = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($action === 'search_by_name' && $response['success'] && $rowid > 0) {
    // ... (Cache update logic - unchanged) ...
    $jsonDataEsc = $mysqli->real_escape_string($jsonData);
    $updateCacheTime = time();
    $mysqli->query("UPDATE `dlrms_src_names` SET resp='{$jsonDataEsc}', time='{$updateCacheTime}' WHERE id = '{$rowid}'");
} elseif ($action === 'search_by_name' && $response['success'] && $rowid == 0) {
    // ... (Error log if cache rowid missing - unchanged) ...
    error_log("Successful search but failed to get rowid for caching: survey={$selected_survey_id}, owner={$owner_search_esc}, guardian={$guardian_search_esc}");
}


// --- Close Connection & Output ---
if ($mysqli instanceof mysqli && $mysqli->thread_id) {
    $mysqli->close();
}

echo $jsonData;
exit();
?>