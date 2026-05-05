<?php
// manage_supplies_inventory.php - Dedicated Page for Managing Supplies Inventory
// This script provides a focused interface for admins to add new supplies and update stock levels.
// Features include secure authentication, input validation, error handling, and a responsive UI with Bootstrap.
// Includes CSRF protection, logging, and low-stock warnings.
// Enhanced UI: Modern professional design with clean layouts, subtle animations, improved responsiveness, and corporate color scheme.
// Added: Search functionality for supplies, tooltips, and better form handling.
// Security: CSRF protection added.
// Modifications: Transferred from admin_dashboard.php for better separation of concerns.
// Fixed: Corrected HTML structure in forms, added search filter, improved accessibility.

require_once 'config.php';
session_start();

// Enforce HTTPS (commented out for local dev)
/*
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}
*/

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
    header('Location: manage_supplies_inventory.php?message=' . urlencode($message) . '&error=' . urlencode($error));
    exit;
}

// Handle update stock form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF token mismatch.';
    } else {
        try {
            // Validate and sanitize inputs
            $supply_id = filter_var($_POST['supply_id'], FILTER_VALIDATE_INT);
            $amount = filter_var($_POST['amount'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $action = htmlspecialchars(trim($_POST['action']));
            
            if (!$supply_id || !$amount || !in_array($action, ['add', 'reduce'])) {
                throw new Exception('Invalid input. Please check supply ID, amount, and action.');
            }
            
            // Fetch current quantity
            $stmt = $conn->prepare("SELECT name, quantity_available FROM supplies WHERE id = ?");
            $stmt->bind_param("i", $supply_id);
            $stmt->execute();
            $supply = $stmt->get_result()->fetch_assoc();
            if (!$supply) {
                $stmt->close();
                throw new Exception('Supply not found.');
            }
            $current_qty = $supply['quantity_available'];
            $supply_name = $supply['name'];
            $stmt->close();
            
            // Calculate new quantity
            if ($action === 'add') {
                $new_qty = $current_qty + $amount;
            } elseif ($action === 'reduce') {
                $new_qty = max(0, $current_qty - $amount);  // Prevent negative stock
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            // Update quantity
            $stmt = $conn->prepare("UPDATE supplies SET quantity_available = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_qty, $supply_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update stock.');
            }
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $message = "Stock updated successfully: {$supply_name} - {$action}ed {$amount} units (New total: {$new_qty}).";
            
            // Check if admin_id exists in users before logging
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt_check->bind_param("i", $_SESSION['user_id']);
            $stmt_check->execute();
            $user_exists = $stmt_check->get_result()->num_rows > 0;
            $stmt_check->close();
            
            if ($user_exists) {
                // Log the action
                $details = json_encode([
                    'supply_id' => $supply_id,
                    'name' => $supply_name,
                    'action' => $action,
                    'amount' => $amount,
                    'old_quantity' => $current_qty,
                    'new_quantity' => $new_qty
                ]);
                $stmt = $conn->prepare("INSERT INTO audit_logs (admin_id, action, details) VALUES (?, 'update_stock', ?)");
                $stmt->bind_param("is", $_SESSION['user_id'], $details);
                if (!$stmt->execute()) {
                    error_log('Audit log failed for update_stock: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log('Audit log skipped: admin_id ' . $_SESSION['user_id'] . ' not found in users table.');
            }
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = 'Error: ' . $e->getMessage();
            error_log('Update stock error for admin ' . ($_SESSION['user_id'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }
    
    // Redirect with messages
    header('Location: manage_supplies_inventory.php?message=' . urlencode($message) . '&error=' . urlencode($error));
    exit;
}

// Fetch supplies using prepared statement, with optional search
$search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : '';
$supplies = false;
try {
    $query = "SELECT id, name, description, quantity_available FROM supplies WHERE name LIKE ? ORDER BY name ASC";
    $stmt = $conn->prepare($query);
    $search_param = '%' . $search . '%';
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $supplies = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = 'Failed to fetch supplies: ' . $e->getMessage();
}

// Handle GET messages from redirects
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : $message;
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : $error;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Supplies Inventory - Supplies System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage supplies inventory: add new supplies and update stock levels.">
    
    <!-- Bootstrap CSS and Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom Styles for Professional UI -->
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand {
            font-weight: 600;
            font-size: 1.25rem;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .btn-custom {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .alert {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .low-stock {
            color: #dc3545;
            font-weight: 600;
        }
        .table th {
            background-color: #343a40;
            color: white;
            border: none;
            font-weight: 600;
        }
        .table tbody tr:hover {
            background-color: #f1f3f4;
        }
        .form-control:focus, .form-select:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
        }
        .search-bar {
            max-width: 400px;
        }
        .tooltip-inner {
            background-color: #007bff;
        }
        .tooltip.bs-tooltip-top .tooltip-arrow::before {
            border-top-color: #007bff;
        }
    </style>
</head>
<body class="d-flex flex-column">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-box-seam me-2"></i> Supplies System - Manage Inventory
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar links / Logout -->
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm me-2 btn-custom" href="admin_dashboard.php">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link text-white">Hello, <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm ms-2 btn-custom" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4 flex-grow-1">
        <!-- Display Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in" role="alert">
                <i class="bi bi-check-circle-fill"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Add New Supply Section -->
            <div class="col-lg-4 mb-4">
                <div class="card fade-in">
                    <div class="card-header bg-primary text-white d-flex align-items-center">
                        <i class="bi bi-plus-circle me-2"></i>
                        <h5 class="card-title mb-0">Add New Supply</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="mb-3">
                                <label for="supply_name" class="form-label fw-semibold"><i class="bi bi-tag"></i> Supply Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supply_name" name="name" required placeholder="e.g., Pens, Paper">
                            </div>
                            <div class="mb-3">
                                <label for="supply_description" class="form-label fw-semibold"><i class="bi bi-card-text"></i> Description</label>
                                <textarea class="form-control" id="supply_description" name="description" rows="2" placeholder="Optional description"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="supply_quantity" class="form-label fw-semibold"><i class="bi bi-hash"></i> Initial Quantity <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="supply_quantity" name="quantity" min="0" required placeholder="e.g., 100">
                            </div>
                            <button type="submit" name="add_supply" class="btn btn-primary w-100 btn-custom" data-bs-toggle="tooltip" data-bs-placement="top" title="Add a new supply to the inventory">
                                <i class="bi bi-plus"></i> Add Supply
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Supplies Inventory Table -->
            <div class="col-lg-8">
                <div class="card fade-in">
                    <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-box-seam me-2"></i>
                            <h5 class="card-title mb-0">Current Supplies Inventory</h5>
                        </div>
                        <form method="get" class="d-flex search-bar">
                            <input type="text" name="search" class="form-control me-2" placeholder="Search supplies..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-outline-light btn-custom"><i class="bi bi-search"></i></button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            
                                               <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Stock</th>
                                    <th scope="col">Update Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($supplies && $supplies->num_rows > 0): ?>
                                    <?php while ($s = $supplies->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($s['name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($s['description']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?= $s['quantity_available'] < 10 ? 'bg-danger low-stock' : 'bg-info' ?>">
                                                    <?= $s['quantity_available'] ?> units
                                                </span>
                                            </td>
                                            <td>
                                                <form method="post" class="d-flex flex-column gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="supply_id" value="<?= $s['id'] ?>">
                                                    <input type="number" name="amount" min="1" required class="form-control form-control-sm" placeholder="Qty" data-bs-toggle="tooltip" data-bs-placement="top" title="Enter the quantity to add or reduce">
                                                    <select name="action" class="form-select form-select-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Choose to add or reduce stock">
                                                        <option value="add">Add</option>
                                                        <option value="reduce">Reduce</option>
                                                    </select>
                                                    <button name="update_stock" class="btn btn-warning btn-sm btn-custom" type="submit" data-bs-toggle="tooltip" data-bs-placement="top" title="Update the stock level">
                                                        <i class="bi bi-arrow-repeat"></i> Update
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">
                                            <i class="bi bi-exclamation-triangle"></i> No supplies found.
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

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>
</body> 
</html>