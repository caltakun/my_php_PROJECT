<?php
// update_request.php - Handles approval/rejection of supply requests
require_once 'config.php';
session_start();

// Security: Ensure admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Validate POST data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ids']) || empty($_POST['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$ids = trim($_POST['ids']);
$action = trim($_POST['action']);

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// Convert comma-separated IDs into array
$idArray = array_filter(array_map('intval', explode(',', $ids)));

if (empty($idArray)) {
    echo json_encode(['success' => false, 'error' => 'No valid request IDs']);
    exit;
}

try {
    $conn->begin_transaction();

    if ($action === 'approve') {

        // Prepare placeholders
        $placeholders = implode(',', array_fill(0, count($idArray), '?'));

        // Fetch requests and check stock
        $stmt = $conn->prepare("
            SELECT r.id, r.supply_id, r.quantity_requested, s.quantity_available
            FROM requests r
            JOIN supplies s ON r.supply_id = s.id
            WHERE r.id IN ($placeholders) AND r.status = 'pending'
        ");
        $stmt->bind_param(str_repeat('i', count($idArray)), ...$idArray);
        $stmt->execute();
        $result = $stmt->get_result();

        $requests = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['quantity_requested'] > $row['quantity_available']) {
                throw new Exception("Insufficient stock for request ID {$row['id']}");
            }
            $requests[] = $row;
        }
        $stmt->close();

        // Approve requests & deduct stock
        foreach ($requests as $req) {

            // Update request status
            $stmt = $conn->prepare("UPDATE requests SET status = 'approved' WHERE id = ?");
            $stmt->bind_param("i", $req['id']);
            $stmt->execute();
            $stmt->close();

            // Deduct stock
            $stmt = $conn->prepare("
                UPDATE supplies 
                SET quantity_available = quantity_available - ? 
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $req['quantity_requested'], $req['supply_id']);
            $stmt->execute();
            $stmt->close();
        }

    } else {
        // Reject requests
        $placeholders = implode(',', array_fill(0, count($idArray), '?'));
        $stmt = $conn->prepare("UPDATE requests SET status = 'rejected' WHERE id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($idArray)), ...$idArray);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => ucfirst($action) . 'd successfully']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
