<?php
include 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
}

$message = '';
$redirect = false;
$successCount = 0;
$errorMessages = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Fetch all supplies to validate against
    $supplies = $conn->query("SELECT id, name FROM supplies");
    $supplyMap = [];
    while ($row = $supplies->fetch_assoc()) {
        $supplyMap[$row['id']] = $row['name'];
    }

    // Process each submitted quantity
    $hasValidRequest = false;
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'quantity_') === 0) {
            $supply_id = str_replace('quantity_', '', $key);
            $quantity = intval($value);
            if ($quantity > 0 && isset($supplyMap[$supply_id])) {
                $hasValidRequest = true;
                $stmt = $conn->prepare("INSERT INTO requests (user_id, supply_id, quantity_requested) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $_SESSION['user_id'], $supply_id, $quantity);
                if ($stmt->execute()) {
                    $successCount++;
                } else {
                    $errorMessages[] = "Error for {$supplyMap[$supply_id]}: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }

    if ($hasValidRequest) {
        if ($successCount > 0) {
            $message .= '<div class="alert alert-success">Successfully submitted ' . $successCount . ' request(s)!</div>';
        }
        if (!empty($errorMessages)) {
            $message .= '<div class="alert alert-warning"><strong>Warnings:</strong><ul>';
            foreach ($errorMessages as $err) {
                $message .= '<li>' . htmlspecialchars($err) . '</li>';
            }
            $message .= '</ul></div>';
        }
        $redirect = true;
    } else {
        $message = '<div class="alert alert-danger">Please select at least one supply and enter a valid quantity.</div>';
    }
}

// Fetch all supplies for display
$supplies = $conn->query("SELECT id, name, description FROM supplies ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Supplies</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .supply-row { transition: background-color 0.3s; }
        .supply-row:hover { background-color: #f8f9fa; }
        .quantity-input { width: 100px; }
    </style>
    <?php if ($redirect): ?>
    <meta http-equiv="refresh" content="3;url=dashboard.php">
    <?php endif; ?>
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
                <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><i class="bi bi-plus-circle"></i> Request Supplies</h3>
                        <small>Select one or more supplies and enter quantities to request them.</small>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        
                        <?php if (!$redirect): ?>
                            <form method="POST" id="requestForm">
                                <div class="mb-3 d-flex justify-content-between">
                                    <div>
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                        <label for="selectAll" class="form-check-label ms-2">Select All Supplies</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="clearAll"><i class="bi bi-x-circle"></i> Clear All</button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th scope="col"><input type="checkbox" id="headerCheckbox"></th>
                                                <th scope="col">Supply Name</th>
                                                <th scope="col">Description</th>
                                                <th scope="col">Quantity Requested</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($supplies->num_rows > 0): ?>
                                                <?php while ($supply = $supplies->fetch_assoc()): ?>
                                                    <tr class="supply-row">
                                                        <td>
                                                            <input type="checkbox" class="supply-checkbox" data-supply-id="<?php echo $supply['id']; ?>">
                                                        </td>
                                                        <td><?php echo htmlspecialchars($supply['name']); ?></td>
                                                        <td><?php echo htmlspecialchars($supply['description']); ?></td>
                                                        <td>
                                                            <input type="number" class="form-control quantity-input" id="quantity_<?php echo $supply['id']; ?>" name="quantity_<?php echo $supply['id']; ?>" min="0" disabled placeholder="0" aria-label="Quantity for <?php echo htmlspecialchars($supply['name']); ?>">
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No supplies available.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send"></i> Submit Requests</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="text-center">You will be redirected to the dashboard in 3 seconds...</p>
                            <div class="d-grid gap-2">
                                <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary"><i class="bi bi-plus-circle"></i> Make Another Request</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.supply-checkbox');
            const isChecked = this.checked;
            checkboxes.forEach(cb => {
                cb.checked = isChecked;
                toggleQuantityInput(cb);
            });
        });

        // Header checkbox to select/deselect all
        document.getElementById('headerCheckbox').addEventListener('change', function() {
            document.getElementById('selectAll').checked = this.checked;
            document.getElementById('selectAll').dispatchEvent(new Event('change'));
        });

        // Individual checkbox toggle
        document.querySelectorAll('.supply-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                toggleQuantityInput(this);
                updateSelectAllState();
            });
        });

        function toggleQuantityInput(checkbox) {
            const supplyId = checkbox.dataset.supplyId;
            const input = document.getElementById('quantity_' + supplyId);
            input.disabled = !checkbox.checked;
            if (!checkbox.checked) {
                input.value = '';
            }
        }

        function updateSelectAllState() {
            const checkboxes = document.querySelectorAll('.supply-checkbox');
            const checkedBoxes = document.querySelectorAll('.supply-checkbox:checked');
            document.getElementById('selectAll').checked = checkboxes.length === checkedBoxes.length;
            document.getElementById('headerCheckbox').checked = checkboxes.length === checkedBoxes.length;
        }

        // Clear All button
        document.getElementById('clearAll').addEventListener('click', function() {
            document.querySelectorAll('.supply-checkbox').forEach(cb => {
                cb.checked = false;
                toggleQuantityInput(cb);
            });
            document.getElementById('selectAll').checked = false;
            document.getElementById('headerCheckbox').checked = false;
        });
    </script>
</body>
</html>