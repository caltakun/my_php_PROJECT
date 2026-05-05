<?php
require_once 'config.php';
session_start();

// Enforce HTTPS
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

// Security: Regenerate session ID to prevent session fixation (optional for GET, but good practice)
session_regenerate_id(true);

// Check authorization
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    error_log('Unauthorized access attempt to get_user_requests.php by user ID: ' . ($_SESSION['user_id'] ?? 'unknown'));
    exit;
}

// Validate and sanitize inputs
$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id || $user_id <= 0) {
    echo json_encode(['error' => 'Invalid or missing user ID']);
    exit;
}

$status = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$allowed_statuses = ['pending', 'approved', 'rejected'];
if ($status && !in_array($status, $allowed_statuses)) {
    echo json_encode(['error' => 'Invalid status filter']);
    exit;
}

// Fetch user details (to confirm existence and for potential future use)
$userStmt = $conn->prepare("SELECT id, username FROM users WHERE id = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

if (!$user) {
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Build query with optional status filter and LIMIT for performance
$statusCondition = $status ? "AND r.status = ?" : "";
$query = "
    SELECT s.name, r.quantity_requested, r.status
    FROM requests r
    JOIN supplies s ON r.supply_id = s.id
    WHERE r.user_id = ?
    $statusCondition
    ORDER BY r.request_date DESC
    LIMIT 100
";  // Limit to 100 requests per user to prevent large responses

$stmt = $conn->prepare($query);
if ($status) {
    $stmt->bind_param("is", $user_id, $status);
} else {
    $stmt->bind_param("i", $user_id);
}

if (!$stmt->execute()) {
    echo json_encode(['error' => 'Database query failed']);
    error_log('Database error in get_user_requests.php for user ID: ' . $user_id);
    $stmt->close();
    exit;
}

$result = $stmt->get_result();
$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

// Return JSON response
echo json_encode(['user' => $user, 'requests' => $requests]);
?>