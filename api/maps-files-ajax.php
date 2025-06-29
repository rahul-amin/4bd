<?php
// maps-files-ajax.php
// ... (database connection and ROOT_FOLDER_ID definition remain the same) ...
$dbHost = "172.188.89.41";
$dbUsername = "bdland";
$dbPassword = "8WF7LLRWHALsFjinvFU4";
$dbDatabase = "bdland";
$dbTableName = "maps_files_list";
define('ROOT_FOLDER_ID', '1--fN7r2cZFDYTjyH3EYOJfNDCpsK1yV2');
$ROOT_FOLDER_ID = '1--fN7r2cZFDYTjyH3EYOJfNDCpsK1yV2';
define('DB_SERVER', $dbHost); define('DB_USERNAME', $dbUsername); define('DB_PASSWORD', $dbPassword); define('DB_NAME', $dbDatabase);
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($mysqli === false) { die("ERROR: Could not connect. " . $mysqli->connect_error); }
if (!$mysqli->set_charset("utf8mb4")) { die("ERROR: Error loading character set utf8mb4: %s\n" . $mysqli->error); }

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : null;
$response = [ 'success' => false, 'message' => 'Invalid action', 'data' => null ]; // Default response

// --- Function to recursively get breadcrumbs for an item ---
function getBreadcrumbsForItem($mysqli, $itemId, $maxDepth = 10) {
    global $ROOT_FOLDER_ID; // Access the global root ID
    $breadcrumbs = [];
    $currentId = $itemId;
    $depth = 0;

    // Fetch the item itself first to add to the end
    $sql_self = "SELECT google_file_id, file_name, parent_folder_id FROM maps_files_list WHERE google_file_id = ?";
    $stmt_self = $mysqli->prepare($sql_self);
    if(!$stmt_self) return []; // Return empty on prepare error
    $stmt_self->bind_param("s", $currentId);
    $stmt_self->execute();
    $result_self = $stmt_self->get_result();
    $self_data = $result_self->fetch_assoc();
    $stmt_self->close();

    if (!$self_data) return []; // Item not found

    // Add self to breadcrumbs (will be reversed later)
    $breadcrumbs[] = ['id' => $self_data['google_file_id'], 'name' => $self_data['file_name']];
    $currentId = $self_data['parent_folder_id']; // Start recursion from parent

    while ($currentId !== null && $currentId !== $ROOT_FOLDER_ID && $depth < $maxDepth) {
        $sql_bc = "SELECT google_file_id, file_name, parent_folder_id FROM maps_files_list WHERE google_file_id = ?";
        $stmt_bc = $mysqli->prepare($sql_bc);
         if(!$stmt_bc) break; // Stop if prepare fails
        $stmt_bc->bind_param("s", $currentId);
        $stmt_bc->execute();
        $result_bc = $stmt_bc->get_result();
        $folder_data = $result_bc->fetch_assoc();
        $stmt_bc->close();

        if ($folder_data) {
            $breadcrumbs[] = ['id' => $folder_data['google_file_id'], 'name' => $folder_data['file_name']];
            $currentId = $folder_data['parent_folder_id'];
        } else {
            $currentId = null; // Stop if parent not found
        }
        $depth++;
    }

    // Add root if not already there (and we didn't hit max depth prematurely)
    if ($depth < $maxDepth) {
        $sql_root = "SELECT google_file_id, file_name FROM maps_files_list WHERE google_file_id = ?";
        $stmt_root = $mysqli->prepare($sql_root);
        if ($stmt_root) {
            $stmt_root->bind_param("s", $ROOT_FOLDER_ID);
            $stmt_root->execute();
            $result_root = $stmt_root->get_result();
            $root_data = $result_root->fetch_assoc();
            $stmt_root->close();
            if ($root_data) {
                 $breadcrumbs[] = ['id' => $root_data['google_file_id'], 'name' => $root_data['file_name'] . ' (Root)'];
            } else {
                 $breadcrumbs[] = ['id' => $ROOT_FOLDER_ID, 'name' => 'Main Root']; // Fallback
            }
        } else {
             $breadcrumbs[] = ['id' => $ROOT_FOLDER_ID, 'name' => 'Main Root']; // Fallback on prepare error
        }
    }


    return array_reverse($breadcrumbs); // Reverse to get root -> ... -> item
}


// --- Main Logic ---
try {
    if ($action === 'search_files') {
        $searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';
        $limit = 50; // Limit results for performance

        if (strlen($searchTerm) < 3) {
            throw new Exception("অনুসন্ধান করতে কমপক্ষে ৩ অক্ষর প্রয়োজন।");
        }

        $searchResults = [];
        $searchTermWild = '%' . $searchTerm . '%';

        // Query to find matching files/folders
        $sql_search = "SELECT google_file_id, file_name, is_folder, mime_type, parent_folder_id, size_bytes, google_modified_time, totalfiles, totalsize
                       FROM maps_files_list
                       WHERE file_name LIKE ?
                       ORDER BY is_folder DESC, file_name ASC
                       LIMIT ?";

        $stmt_search = $mysqli->prepare($sql_search);
        if ($stmt_search === false) {
            throw new Exception("Prepare failed (search): " . $mysqli->error);
        }
        $stmt_search->bind_param("si", $searchTermWild, $limit);
        $stmt_search->execute();
        $result_search = $stmt_search->get_result();

        while ($row = $result_search->fetch_assoc()) {
            // For each result, get its breadcrumb path
            $itemBreadcrumbs = getBreadcrumbsForItem($mysqli, $row['google_file_id']);

            $searchResults[] = [
                'id' => $row['google_file_id'],
                'name' => $row['file_name'],
                'isFolder' => (bool)$row['is_folder'],
                'size' => $row['size_bytes'],
                'modified' => $row['google_modified_time'] ? date("Y-m-d H:i", strtotime($row['google_modified_time'])) : null,
                'mimeType' => $row['mime_type'],
                'totalfiles' => $row['totalfiles'],
                'totalsize' => $row['totalsize'],
                'breadcrumbs' => $itemBreadcrumbs // Add breadcrumbs to each result
            ];
        }
        $stmt_search->close();

        $response['success'] = true;
        $response['message'] = 'Search successful';
        $response['data'] = $searchResults;

    } elseif ($action === null || $action === 'get_folder_contents') { // Default action or explicit fetch
        $requestedFolderId = isset($_GET['folderId']) ? $_GET['folderId'] : null;
        // Treat 'root', null, or empty string as the defined ROOT_FOLDER_ID
        $folderIdToQuery = ($requestedFolderId === null || $requestedFolderId === 'root' || $requestedFolderId === '') ? ROOT_FOLDER_ID : $requestedFolderId;

        $items = [];
        $sql_items = "SELECT google_file_id, file_name, is_folder, size_bytes, google_modified_time, mime_type, totalfiles, totalsize
                      FROM maps_files_list
                      WHERE parent_folder_id <=> ? " . // Use <=> for NULL-safe comparison if root parent is NULL
                     "ORDER BY is_folder DESC, file_name ASC";
        $stmt_items = $mysqli->prepare($sql_items);
        if ($stmt_items === false) throw new Exception("Prepare failed (items): " . $mysqli->error);

        // Special handling if the ROOT_FOLDER_ID parent is actually NULL in DB
        // Check your DB schema. If parent_folder_id for root's children is NULL:
        if ($folderIdToQuery === ROOT_FOLDER_ID) {
             // Adjust query or binding if needed. Assuming parent_folder_id stores the *actual* root ID string:
             $parentMatchValue = $folderIdToQuery;
        } else {
             $parentMatchValue = $folderIdToQuery;
        }
        // If parent_folder_id IS NULL for root items:
        // $sql_items = "SELECT ... WHERE parent_folder_id IS NULL ORDER BY ..."; $stmt_items = $mysqli->prepare(...); $stmt_items->execute();
        // ELSE (parent_folder_id always stores an ID string, like '1--fN7r...'):
        $stmt_items->bind_param("s", $parentMatchValue);
        $stmt_items->execute();

        $result_items = $stmt_items->get_result();
        while ($row = $result_items->fetch_assoc()) {
            $items[] = [ /* ... map fields same as before ... */
                'id' => $row['google_file_id'], 'name' => $row['file_name'],
                'isFolder' => (bool)$row['is_folder'], 'size' => $row['size_bytes'],
                'modified' => $row['google_modified_time'] ? date("Y-m-d H:i", strtotime($row['google_modified_time'])) : null,
                'mimeType' => $row['mime_type'], 'totalfiles' => $row['totalfiles'], 'totalsize' => $row['totalsize']
            ];
        }
        $stmt_items->close();

        // Fetch current folder details and breadcrumbs (using getBreadcrumbsForItem for consistency)
        $sql_current = "SELECT google_file_id, file_name, parent_folder_id FROM maps_files_list WHERE google_file_id = ?";
        $stmt_current = $mysqli->prepare($sql_current);
        if ($stmt_current === false) throw new Exception("Prepare failed (current): " . $mysqli->error);
        $stmt_current->bind_param("s", $folderIdToQuery);
        $stmt_current->execute();
        $result_current = $stmt_current->get_result();
        $currentFolderData = $result_current->fetch_assoc();
        $stmt_current->close();

        $currentFolderResponse = null;
        $parentFolderIdResponse = null;
        $breadcrumbsResponse = [];

        if ($currentFolderData) {
            $currentFolderResponse = ['id' => $currentFolderData['google_file_id'], 'name' => $currentFolderData['file_name']];
            $parentFolderIdResponse = ($currentFolderData['parent_folder_id'] === ROOT_FOLDER_ID || $currentFolderData['google_file_id'] === ROOT_FOLDER_ID) ? null : $currentFolderData['parent_folder_id'];
            $breadcrumbsResponse = getBreadcrumbsForItem($mysqli, $folderIdToQuery);
        } else if ($folderIdToQuery === ROOT_FOLDER_ID) { // Handle case where root itself might not be in DB (e.g., virtual root)
             $currentFolderResponse = ['id' => ROOT_FOLDER_ID, 'name' => 'Main Root'];
             $parentFolderIdResponse = null;
             $breadcrumbsResponse = [['id' => ROOT_FOLDER_ID, 'name' => 'Main Root']];
        } else {
            // Folder ID requested doesn't exist
             $currentFolderResponse = ['id' => ROOT_FOLDER_ID, 'name' => 'Error: Folder Not Found'];
             $parentFolderIdResponse = null;
             $breadcrumbsResponse = [['id' => ROOT_FOLDER_ID, 'name' => 'Main Root']]; // Basic breadcrumb
        }

        $response['success'] = true;
        $response['message'] = 'Folder contents retrieved';
        $response['data'] = [
            'items' => $items,
            'currentFolder' => $currentFolderResponse,
            'parentFolderId' => $parentFolderIdResponse,
            'breadcrumbs' => $breadcrumbsResponse
        ];
    } else {
         // Keep default invalid action message
    }

} catch (Exception $e) {
    error_log("maps-files-ajax Error: " . $e->getMessage());
    $response['success'] = false; // Ensure success is false
    $response['message'] = "An error occurred: " . $e->getMessage(); // Provide error message
    $response['data'] = null; // Clear data on error
}

$mysqli->close();
echo json_encode($response, JSON_UNESCAPED_UNICODE); // Ensure Unicode characters are preserved
?>