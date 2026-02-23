<?php
// api.php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'SearchEngine.php';

try {
    // Get POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid JSON input");
    }

    $page = isset($data['page']) ? (int)$data['page'] : 1;
    
    // Instantiate Search Engine
    $engine = new SearchEngine($pdo);
    
    // Check if new grouped format or legacy format
    if (isset($data['query_groups'])) {
        // New grouped format
        $response = $engine->searchGroups($data['query_groups'], $page);
    } else {
        // Legacy format (single criteria list)
        $criteria = $data['criteria'] ?? [];
        $response = $engine->search($criteria, $page);
    }
    
    echo json_encode(['success' => true, 'data' => $response]);

} catch (Exception $e) {
    // Log error for debugging
    error_log("Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
