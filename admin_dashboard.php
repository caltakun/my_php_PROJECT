<?php
// admin_dashboard.php - Enhanced Admin Dashboard for Supplies System
// This script provides a professional interface for admins to manage pending requests and supplies stock.
// Features include secure authentication, input validation, error handling, and a responsive UI with Bootstrap.
// Includes integrated JavaScript for handling approvals, rejections, printing, and showing request details.
// Added: History sections for approved and rejected requests, "Show" button for details, and add new supplies feature.
// Enhanced UI: Modern design with gradients, animations, stats overview, and improved responsiveness.
// Security: CSRF protection added.
// Modifications: Improved code structure, added better error handling, fixed minor JS syntax issues, enhanced readability.
// Added: Request History section for viewing all supply request history.
// Fixed: Added handler for updating supply stock (add/reduce quantity).
// Fixed: Added check to ensure admin_id exists in users table before logging to audit_logs to prevent FK constraint errors.
// Updated: Moved "Manage Supplies Inventory" section to separate manage_supplies_inventory.php for better UI separation.

require_once 'config.php';
session_start();

// Enforce HTTPS (commented out for local dev)

if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}


// Security: Regenerate session ID to prevent session fixation
session_regenerate_id(true);

// Authentication check: Ensure user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize message variables
$message = '';
$error = '';

// Handle add new supply form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supply'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF token mismatch.';
    } else {
        try {
            // Validate and sanitize inputs
            $name = htmlspecialchars(trim($_POST['name']));
            $description = htmlspecialchars(trim($_POST['description']));
            $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            
            if (empty($name) || $quantity === false) {
                throw new Exception('Supply name and a valid initial quantity (0 or more) are required.');
            }
            
            // Check for duplicate supply name (case-insensitive)
            $stmt = $conn->prepare("SELECT id FROM supplies WHERE LOWER(name) = LOWER(?)");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $stmt->close();
                throw new Exception('A supply with this name already exists. Please choose a different name.');
            }
            $stmt->close();
            
            // Start transaction for atomicity
            $conn->begin_transaction();
            
            // Insert new supply using prepared statement
            $stmt = $conn->prepare("INSERT INTO supplies (name, description, quantity_available) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $description, $quantity);
            if (!$stmt->execute()) {
                throw new Exception('Failed to add new supply. Please try again.');
            }
            $supply_id = $conn->insert_id;  // Get the new supply ID
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $message = 'New supply added successfully.';
            
            // Check if admin_id exists in users before logging
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt_check->bind_param("i", $_SESSION['user_id']);
            $stmt_check->execute();
            $user_exists = $stmt_check->get_result()->num_rows > 0;
            $stmt_check->close();
            
            if ($user_exists) {
                // Automatically log the action in audit_logs
                $details = json_encode([
                    'supply_id' => $supply_id,
                    'name' => $name,
                    'description' => $description,
                    'quantity' => $quantity
                ]);
                $stmt = $conn->prepare("INSERT INTO audit_logs (admin_id, action, details) VALUES (?, 'add_supply', ?)");
                $stmt->bind_param("is", $_SESSION['user_id'], $details);
                if (!$stmt->execute()) {
                    error_log('Audit log failed for add_supply: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log('Audit log skipped: admin_id ' . $_SESSION['user_id'] . ' not found in users table.');
            }
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = 'Error: ' . $e->getMessage();
            error_log('Add supply error for admin ' . ($_SESSION['user_id'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }
    
    // Redirect with messages to avoid form resubmission
    header('Location: admin_dashboard.php?message=' . urlencode($message) . '&error=' . urlencode($error));
    exit;
}

// Fetch stats for overview
$stats = [];
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total_pending FROM requests WHERE status = 'pending'");
    $stmt->execute();
    $stats['pending'] = $stmt->get_result()->fetch_assoc()['total_pending'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS total_supplies FROM supplies");
    $stmt->execute();
    $stats['supplies'] = $stmt->get_result()->fetch_assoc()['total_supplies'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS total_approved FROM requests WHERE status = 'approved'");
    $stmt->execute();
    $stats['approved'] = $stmt->get_result()->fetch_assoc()['total_approved'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS total_rejected FROM requests WHERE status = 'rejected'");
    $stmt->execute();
    $stats['rejected'] = $stmt->get_result()->fetch_assoc()['total_rejected'];
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch stats: ' . $e->getMessage();
}

// Fetch pending requests grouped by user using prepared statement (with LIMIT for performance)
$pending_requests = false;
try {
    $stmt = $conn->prepare("
        SELECT r.user_id, u.username, GROUP_CONCAT(CONCAT(s.name, ' (Qty: ', r.quantity_requested, ')') SEPARATOR ', ') AS supplies_list, GROUP_CONCAT(r.id) AS request_ids
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        JOIN supplies s ON r.supply_id = s.id 
        WHERE r.status = 'pending' 
        GROUP BY r.user_id, u.username
        LIMIT 50
    ");
    $stmt->execute();
    $pending_requests = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch pending requests: ' . $e->getMessage();
}

// Fetch approved requests grouped by user
$approved_requests = false;
try {
    $stmt = $conn->prepare("
        SELECT r.user_id, u.username, GROUP_CONCAT(CONCAT(s.name, ' (Qty: ', r.quantity_requested, ')') SEPARATOR ', ') AS supplies_list, GROUP_CONCAT(r.id) AS request_ids
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        JOIN supplies s ON r.supply_id = s.id 
        WHERE r.status = 'approved' 
        GROUP BY r.user_id, u.username
        LIMIT 50
    ");
    $stmt->execute();
    $approved_requests = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch approved requests: ' . $e->getMessage();
}

// Fetch rejected requests grouped by user
$rejected_requests = false;
try {
    $stmt = $conn->prepare("
        SELECT r.user_id, u.username, GROUP_CONCAT(CONCAT(s.name, ' (Qty: ', r.quantity_requested, ')') SEPARATOR ', ') AS supplies_list, GROUP_CONCAT(r.id) AS request_ids
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        JOIN supplies s ON r.supply_id = s.id 
        WHERE r.status = 'rejected' 
        GROUP BY r.user_id, u.username
        LIMIT 50
    ");
    $stmt->execute();
    $rejected_requests = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch rejected requests: ' . $e->getMessage();
}

// Fetch request history for all users (full history)
$history_requests = false;
try {
    $stmt = $conn->prepare("
        SELECT r.quantity_requested, r.status, r.request_date, s.name AS supply_name, u.username AS user_name
        FROM requests r
        JOIN supplies s ON r.supply_id = s.id
        JOIN users u ON r.user_id = u.id
        ORDER BY r.request_date DESC
        LIMIT 50
    ");
    $stmt->execute();
    $history_requests = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch request history: ' . $e->getMessage();
}

// Handle GET messages from redirects
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : $message;
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : $error;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Supplies System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Admin dashboard for managing supplies and requests.">
    
    <!-- Bootstrap CSS and Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .navbar-brand {
            font-weight: bold;
            font-size: 1.2rem;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .table th {
            background: linear-gradient(135deg, #343a40 0%, #495057 100%);
            color: white;
            border: none;
        }
        .table tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.1);
        }
        .btn-custom {
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: scale(1.05);
        }
        .alert {
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .fade-in {
            animation: fadeIn 0.8s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .low-stock {
            color: #dc3545;
            font-weight: bold;
        }
        .modal-content {
            border-radius: 15px;
        }
        footer {
            background: #343a40;
            color: #adb5bd;
            text-align: center;
            padding: 10px 0;
            margin-top: auto;
        }
        /* Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            #print-form, #print-form * {
                visibility: visible;
            }
            @page {
                size: A4 landscape;
                margin: 0.3in;
            }
            body {
                margin: 0;
                padding: 0;
            }
            #print-form {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                font-family: Arial, sans-serif;
                font-size: 9.5px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                table-layout: auto;
            }
            th, td {
                border: 1px solid #000;
                padding: 3px 4px;
                word-wrap: break-word;
                vertical-align: middle;
            }
            th {
                font-weight: bold;
                text-align: center;
            }
            .signature-table td {
                border: none;
                padding: 18px 5px;
                text-align: center;
            }
            h3 {
                margin: 0;
                font-size: 14px;
            }
        }
        .history-table th, .history-table td {
            vertical-align: middle;
        }
    </style>
</head>
<body class="d-flex flex-column">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-lg">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-house-door-fill me-2"></i> Supplies System - Admin Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar links / Logout -->
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link text-white">Hello, <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm ms-2" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4 flex-grow-1">
        <!-- Dashboard Overview -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card fade-in">
                    <div class="card-body text-center">
                        <i class="bi bi-clock-history display-4 mb-2"></i>
                        <h5 class="card-title">Pending Requests</h5>
                        <h2 class="mb-0"><?= $stats['pending'] ?? 0 ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card fade-in" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="card-body text-center">
                        <i class="bi bi-box-seam display-4 mb-2"></i>
                        <h5 class="card-title">Total Supplies</h5>
                        <h2 class="mb-0"><?= $stats['supplies'] ?? 0 ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card fade-in" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle display-4 mb-2"></i>
                                   <h5 class="card-title">Approved Requests</h5>
            <h2 class="mb-0"><?= $stats['approved'] ?? 0 ?></h2>
        </div>
    </div>
</div>
<div class="col-md-3 mb-3">
    <div class="card stat-card fade-in" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
        <div class="card-body text-center">
            <i class="bi bi-x-circle display-4 mb-2"></i>
            <h5 class="card-title">Rejected Requests</h5>
            <h2 class="mb-0"><?= $stats['rejected'] ?? 0 ?></h2>
        </div>
    </div>
</div>
</div>

<!-- Display Messages -->
<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill"></i> <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Requests Section with Tabs -->
    <div class="col-lg-8 mb-4">
        <div class="card fade-in">
            <div class="card-header bg-primary text-white d-flex align-items-center">
                <i class="bi bi-list-check me-2"></i>
                <h5 class="card-title mb-0">Requests Management</h5>
            </div>
            <div class="card-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs" id="requestTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">Pending</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab" aria-controls="approved" aria-selected="false">Approved</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected" type="button" role="tab" aria-controls="rejected" aria-selected="false">Rejected</button>
                    </li>
                </ul>
                <div class="tab-content mt-3" id="requestTabsContent">
                    <!-- Pending Tab -->
                    <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th scope="col">User</th>
                                        <th scope="col">Requested Items</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($pending_requests && $pending_requests->num_rows > 0): ?>
                                        <?php while ($r = $pending_requests->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($r['supplies_list']) ?>
                                                    <button class="btn btn-link btn-sm" onclick="showDetails('<?= $r['user_id'] ?>', '<?= htmlspecialchars($r['username']) ?>', 'pending')" data-bs-toggle="tooltip" title="View Details">
                                                        <i class="bi bi-eye"></i> Show
                                                    </button>
                                                </td>
                                                <td><span class="badge bg-warning"><i class="bi bi-clock"></i> Pending</span></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-success btn-sm btn-custom" onclick="approveAll('<?= $r['request_ids'] ?>')" data-bs-toggle="tooltip" title="Approve All">
                                                            <i class="bi bi-check-circle"></i> Approve
                                                        </button>
                                                        <button class="btn btn-danger btn-sm btn-custom" onclick="rejectAll('<?= $r['request_ids'] ?>')" data-bs-toggle="tooltip" title="Reject All">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </button>
                                                        <button class="btn btn-info btn-sm btn-custom" onclick="printUserRequests(<?= (int)$r['user_id'] ?>, '<?= htmlspecialchars($r['username']) ?>', 'pending')" data-bs-toggle="tooltip" title="Print Requests">
                                                            <i class="bi bi-printer"></i> Print
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No pending requests at this time.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Approved Tab -->
                    <div class="tab-pane fade" id="approved" role="tabpanel" aria-labelledby="approved-tab">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th scope="col">User</th>
                                        <th scope="col">Requested Items</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($approved_requests && $approved_requests->num_rows > 0): ?>
                                        <?php while ($r = $approved_requests->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($r['supplies_list']) ?>
                                                    <button class="btn btn-link btn-sm" onclick="showDetails('<?= $r['user_id'] ?>', '<?= htmlspecialchars($r['username']) ?>', 'approved')" data-bs-toggle="tooltip" title="View Details">
                                                        <i class="bi bi-eye"></i> Show
                                                    </button>
                                                </td>
                                                <td><span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span></td>
                                                <td>
                                                    <button class="btn btn-info btn-sm btn-custom" onclick="printUserRequests(<?= (int)$r['user_id'] ?>, '<?= htmlspecialchars($r['username']) ?>', 'approved')" data-bs-toggle="tooltip" title="Print Requests">
                                                        <i class="bi bi-printer"></i> Print
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No approved requests.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Rejected Tab -->
                    <div class="tab-pane fade" id="rejected" role="tabpanel" aria-labelledby="rejected-tab">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th scope="col">User</th>
                                        <th scope="col">Requested Items</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rejected_requests && $rejected_requests->num_rows > 0): ?>
                                        <?php while ($r = $rejected_requests->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($r['supplies_list']) ?>
                                                    <button class="btn btn-link btn-sm" onclick="showDetails('<?= $r['user_id'] ?>', '<?= htmlspecialchars($r['username']) ?>', 'rejected')" data-bs-toggle="tooltip" title="View Details">
                                                        <i class="bi bi-eye"></i> Show
                                                    </button>
                                                </td>
                                                <td><span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span></td>
                                                <td>
                                                    <!-- No actions for rejected, or add re-approve if needed -->
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No rejected requests.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Navigation to Manage Supplies Inventory -->
    <div class="col-lg-4">
        <div class="card fade-in">
            <div class="card-header bg-dark text-white d-flex align-items-center">
                <i class="bi bi-box-seam me-2"></i>
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body text-center">
                <a href="manage_supplies_inventory.php" class="btn btn-primary btn-custom w-100">
                    <i class="bi bi-gear"></i> Manage Supplies Inventory
                </a>
                <p class="mt-2 text-muted">Add new supplies, update stock levels, and view inventory details.</p>
            </div>
        </div>
    </div>
</div>



<!-- Modal for Showing Request Details -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="detailsModalLabel"><i class="bi bi-eye me-2"></i> Requested Items for <span id="modal-username"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul id="modal-supplies-list" class="list-group list-group-flush">
                    <!-- Items will be populated here -->
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-custom" data-bs-dismiss="modal"><i class="bi bi-x"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Print Form (Included from separate file) -->
<?php include 'print_template.php'; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

<!-- Custom JavaScript -->
<script>
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    function getCurrentDateFormatted() {
        const now = new Date();
        const day = String(now.getDate()).padStart(2, '0');
        const month = now.toLocaleString('en-US', { month: 'short' });
        const year = String(now.getFullYear()).slice(-2);
        return `${day}-${month}-${year}`;
    }

    async function printUserRequests(userId, username, status) {
        const btn = event.target.closest('button');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
        btn.disabled = true;

        try {
            const res = await fetch(`get_user_requests.php?user_id=${userId}&status=${status}`);
            if (!res.ok) throw new Error('Server error');

            const data = await res.json();
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }

            // Populate the print template
            document.getElementById('p-user').textContent = username;
            
            const tbody = document.getElementById('print-table-body');
            tbody.innerHTML = '';
            
            data.requests.forEach(request => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td></td>
                    <td>pcs</td>
                    <td>${request.name}</td>
                    <td>${request.quantity_requested}</td>
                    <td>✔</td>
                    <td></td>
                    <td>${request.quantity_requested}</td>
                    <td>For office use</td>
                `;
                tbody.appendChild(row);
            });
            
            // Add empty rows if needed (to fill up to 6 rows for consistency)
            for (let i = data.requests.length; i < 6; i++) {
                const emptyRow = document.createElement('tr');
                emptyRow.innerHTML = '<td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
                tbody.appendChild(emptyRow);
            }
            
            // Set current date for all signature dates
            const currentDate = getCurrentDateFormatted();
            document.getElementById('date-requested').textContent = currentDate;
            document.getElementById('date-approved').textContent = currentDate;
            document.getElementById('date-issued').textContent = currentDate;
            document.getElementById('date-received').textContent = currentDate;
            
            // Show and print
            document.getElementById('print-form').style.display = 'block';
            window.print();
            document.getElementById('print-form').style.display = 'none';
        } catch (e) {
            alert('Failed to load print data: ' + e.message);
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    async function approveAll(ids) {
        if (confirm('Approve all requests?')) {
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            btn.disabled = true;

            try {
                const response = await fetch('update_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ids=${encodeURIComponent(ids)}&action=approve&csrf_token=${encodeURIComponent('<?= $_SESSION['csrf_token'] ?>')}`
                });
                const result = await response.json();
                if (result.success) {
                    alert('Requests approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (e) {
                alert('Failed to approve requests: ' + e.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                }
            }
        }

        async function rejectAll(ids) {
            if (confirm('Reject all requests?')) {
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                btn.disabled = true;

                try {
                    const response = await fetch('update_request.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `ids=${encodeURIComponent(ids)}&action=reject&csrf_token=${encodeURIComponent('<?= $_SESSION['csrf_token'] ?>')}`
                    });
                    const result = await response.json();
                    if (result.success) {
                        alert('Requests rejected successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + result.error);
                    }
                } catch (e) {
                    alert('Failed to reject requests: ' + e.message);
                } finally {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
            }
        }
    }

    async function rejectAll(ids) {
        if (confirm('Reject all requests?')) {
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            btn.disabled = true;

            try {
                const response = await fetch('update_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ids=${encodeURIComponent(ids)}&action=reject&csrf_token=${encodeURIComponent('<?= $_SESSION['csrf_token'] ?>')}`
                });
                const result = await response.json();
                if (result.success) {
                    alert('Requests rejected successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (e) {
                alert('Failed to reject requests: ' + e.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    }

    async function showDetails(userId, username, status) {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';

        try {
            const res = await fetch(`get_user_requests.php?user_id=${userId}&status=${status}`);
            if (!res.ok) throw new Error('Server error');

            const data = await res.json();
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }

            const listElement = document.getElementById('modal-supplies-list');
            listElement.innerHTML = '';

            data.requests.forEach(request => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                li.innerHTML = `
                    <span><strong>${request.name}</strong> - Qty: ${request.quantity_requested}</span>
                    <span class="badge bg-primary">${request.status}</span>
                `;
                listElement.appendChild(li);
            });

            document.getElementById('modal-username').textContent = username;
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        } catch (e) {
            alert('Failed to load details: ' + e.message);
        } finally {
            btn.innerHTML = originalText;
        }
    }
</script>
</body>
</html>