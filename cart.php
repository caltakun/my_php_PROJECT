<?php
include 'config.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'client') {
    header("Location: login.php");
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle remove from cart
if (isset($_GET['remove'])) {
    $supply_id = (int)$_GET['remove'];
    if (isset($_SESSION['cart'][$supply_id])) {
        unset($_SESSION['cart'][$supply_id]);
        $message = 'Item removed from cart.';
    }
    header("Location: cart.php");
    exit;
}

// Handle update quantity
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_cart'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF token mismatch.';
    } else {
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'quantity_') === 0) {
                $supply_id = (int)str_replace('quantity_', '', $key);
                $quantity = (int)$value;
                if ($quantity > 0 && isset($_SESSION['cart'][$supply_id])) {
                    $_SESSION['cart'][$supply_id] = $quantity;
                } elseif ($quantity <= 0) {
                    unset($_SESSION['cart'][$supply_id]);
                }
            }
        }
        $message = 'Cart updated!';
    }
    header("Location: cart.php");
    exit;
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF token mismatch.';
    } elseif (empty($_SESSION['cart'])) {
        $error = 'Your cart is empty.';
    } else {
        $hasError = false;
        $errorItems = [];
        foreach ($_SESSION['cart'] as $supply_id => $quantity) {
            $stmt = $conn->prepare("SELECT name, quantity_available FROM supplies WHERE id = ?");
            $stmt->bind_param("i", $supply_id);
            $stmt->execute();
            $supply = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$supply || $quantity > $supply['quantity_available']) {
                $hasError = true;
                $errorItems[] = $supply['name'] ?? 'Unknown item';
            }
        }
        if (!$hasError) {
            $conn->begin_transaction();
            try {
                foreach ($_SESSION['cart'] as $supply_id => $quantity) {
                    $stmt = $conn->prepare("INSERT INTO requests (user_id, supply_id, quantity_requested, status) VALUES (?, ?, ?, 'pending')");
                    $stmt->bind_param("iii", $_SESSION['user_id'], $supply_id, $quantity);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                $_SESSION['cart'] = []; // Clear cart
                $message = 'Requests submitted successfully! Awaiting admin approval. Check your request history for updates.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to submit requests. Please try again.';
                error_log('Checkout error for user ' . $_SESSION['user_id'] . ': ' . $e->getMessage());
            }
        } else {
            $error = 'Insufficient stock for: ' . implode(', ', $errorItems) . '. Please update quantities.';
        }
    }
    header("Location: cart.php");
    exit;
}

// Fetch cart details securely
$cart_items = [];
if (!empty($_SESSION['cart'])) {
    // Secure IN query with prepared statements
    $placeholders = str_repeat('?,', count($_SESSION['cart']) - 1) . '?';
    $stmt = $conn->prepare("SELECT id, name, description, quantity_available FROM supplies WHERE id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($_SESSION['cart'])), ...array_keys($_SESSION['cart']));
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['cart_quantity'] = $_SESSION['cart'][$row['id']] ?? 0;
        $cart_items[] = $row;
    }
    $stmt->close();
}

// Fetch request history for the client
$user_id = $_SESSION['user_id'];
$history_stmt = $conn->prepare("
    SELECT r.quantity_requested, r.status, r.request_date, s.name AS supply_name
    FROM requests r
    JOIN supplies s ON r.supply_id = s.id
    WHERE r.user_id = ?
    ORDER BY r.request_date DESC
    LIMIT 50
");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$history_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .history-table th, .history-table td {
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="images/RWO7.png" alt="Supplies System Logo" width="50" height="50" class="me-2">
                Supplies System
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">Welcome, Client!</span>
                <a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Dashboard</a>
                <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Cart Section -->
        <div class="card mb-5">
            <div class="card-header">
                <h3><i class="bi bi-cart"></i> Your Cart</h3>
                <small>Review your selected supplies, update quantities, or proceed to checkout.</small>
            </div>
            <div class="card-body">
                <?php if (empty($cart_items)): ?>
                    <p class="text-center text-muted">Your cart is empty. <a href="dashboard.php">Add some supplies!</a></p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Available</th>
                                        <th>Quantity</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart_items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                        <td><?php echo $item['quantity_available']; ?></td>
                                        <td>
                                            <input type="number" name="quantity_<?php echo $item['id']; ?>" value="<?php echo $item['cart_quantity']; ?>" min="1" max="<?php echo $item['quantity_available']; ?>" class="form-control w-50" required>
                                        </td>
                                        <td>
                                            <a href="cart.php?remove=<?php echo $item['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove this item?');"><i class="bi bi-trash"></i> Remove</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between mt-3">
                            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Continue Shopping</a>
                            <div>
                                <button type="submit" name="update_cart" class="btn btn-warning me-2"><i class="bi bi-pencil"></i> Update Cart</button>
                                <button type="submit" name="checkout" class="btn btn-success" onclick="return confirm('Submit all items for approval? This will clear your cart.');"><i class="bi bi-check-circle"></i> Checkout</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Request History Section -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="bi bi-clock-history"></i> Your Request History</h3>
                <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#historyCollapse" aria-expanded="false" aria-controls="historyCollapse">
                    <i class="bi bi-chevron-down"></i> Toggle History
                </button>
            </div>
            <div class="collapse" id="historyCollapse">
                <div class="card-body">
                    <?php if ($history_result->num_rows == 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> You haven't made any requests yet. Start by selecting supplies above!
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped history-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="bi bi-box"></i> Supply Name</th>
                                        <th><i class="bi bi-hash"></i> Quantity Requested</th>
                                        <th><i class="bi bi-flag"></i> Status</th>
                                        <th><i class="bi bi-calendar"></i> Request Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($history = $history_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($history['supply_name']); ?></td>
                                            <td><?php echo htmlspecialchars($history['quantity_requested']); ?></td>
                                            <td>
                                                <?php
                                                $status = $history['status'];
                                                $badge_class = ($status == 'approved') ? 'bg-success' : (($status == 'rejected') ? 'bg-danger' : 'bg-warning');
                                                $icon = ($status == 'approved') ? 'bi-check-circle' : (($status == 'rejected') ? 'bi-x-circle' : 'bi-clock');
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <i class="bi <?php echo $icon; ?>"></i> <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($history['request_date']))); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">Showing up to 50 recent requests. Contact admin for older history.</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>