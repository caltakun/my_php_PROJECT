<?php
include 'config.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'client') {
    header("Location: login.php");
}

// Initialize cart if not set
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$message = '';
$error = '';

// Handle add selected to cart
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_selected_to_cart'])) {
    $selected_supplies = $_POST['selected_supplies'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    
    if (empty($selected_supplies)) {
        $error = 'Please select at least one supply.';
    } else {
        $added_items = [];
        foreach ($selected_supplies as $supply_id) {
            $quantity = (int)($quantities[$supply_id] ?? 0);
            
            // Fetch supply to check availability
            $stmt = $conn->prepare("SELECT name, quantity_available FROM supplies WHERE id = ?");
            $stmt->bind_param("i", $supply_id);
            $stmt->execute();
            $supply = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($supply && $quantity > 0 && $quantity <= $supply['quantity_available']) {
                $_SESSION['cart'][$supply_id] = $quantity;
                $added_items[] = $supply['name'];
            } else {
                $error = "Invalid quantity or insufficient stock for {$supply['name']}.";
                break;
            }
        }
        if (!$error && !empty($added_items)) {
            $message = implode(', ', $added_items) . ' added to cart!';
        }
    }
    header("Location: dashboard.php");
    exit;
}

// Fetch supplies (without category/price to match current DB schema)
$supplies = $conn->query("SELECT id, name, description, quantity_available FROM supplies ORDER BY name");

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

$cart_count = count($_SESSION['cart']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .supply-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .supply-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .stock-badge {
            font-size: 0.9em;
        }
        .search-bar {
            margin-bottom: 20px;
        }
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
                <a class="nav-link position-relative" href="cart.php">
                    <i class="bi bi-cart"></i> Cart
                    <?php if ($cart_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $cart_count; ?></span>
                    <?php endif; ?>
                </a>
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

        <!-- Existing Supplies Section -->
        <div class="card mb-5">
            <div class="card-header bg-light">
                <h3 class="mb-0"><i class="bi bi-list-check"></i> Available Supplies</h3>
                <small>Select supplies, enter quantities, and add them to your cart. Use the search bar to find items quickly.</small>
            </div>
            <div class="card-body">
                <!-- Search Bar -->
                <div class="search-bar">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search supplies by name or description...">
                </div>

                <form method="POST" id="suppliesForm">
                    <div class="mb-3">
                        <button type="submit" name="add_selected_to_cart" class="btn btn-success btn-lg">
                            <i class="bi bi-cart-plus"></i> Add Selected to Cart
                        </button>
                    </div>

                    <?php if ($supplies->num_rows == 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No supplies available at the moment.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php while ($row = $supplies->fetch_assoc()): ?>
                                <div class="col-md-6 col-lg-4 supply-item">
                                    <div class="card h-100 supply-card">
                                        <div class="card-body d-flex flex-column">
                                            <div class="form-check mb-2">
                                                <input type="checkbox" name="selected_supplies[]" value="<?php echo $row['id']; ?>" class="form-check-input supply-checkbox" id="supply-<?php echo $row['id']; ?>">
                                                <label class="form-check-label fw-bold" for="supply-<?php echo $row['id']; ?>">
                                                    <?php echo htmlspecialchars($row['name']); ?>
                                                </label>
                                            </div>
                                            <p class="card-text text-muted small"><?php echo htmlspecialchars($row['description']); ?></p>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge bg-info stock-badge">
                                                        <i class="bi bi-box-seam"></i> Stock: <?php echo $row['quantity_available']; ?>
                                                    </span>
                                                </div>
                                                <div class="input-group">
                                                    <span class="input-group-text">Qty</span>
                                                    <input type="number" name="quantities[<?php echo $row['id']; ?>]" class="form-control quantity-input" min="1" max="<?php echo $row['quantity_available']; ?>" disabled placeholder="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle quantity input for individual checkboxes
        document.querySelectorAll('.supply-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                const card = this.closest('.supply-card');
                const input = card.querySelector('.quantity-input');
                input.disabled = !this.checked;
                if (!this.checked) {
                    input.value = '';
                }
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('.supply-item').forEach(item => {
                const name = item.querySelector('.form-check-label').textContent.toLowerCase();
                const description = item.querySelector('.card-text').textContent.toLowerCase();
                if (name.includes(searchTerm) || description.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>