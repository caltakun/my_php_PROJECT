<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OWWA Supplies</title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar {
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
        }
        .navbar-brand {
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            background: linear-gradient(135deg, #0d6efd 0%, #003f8a 100%);
            color: #fff;
            padding: 110px 0;
            min-height: 75vh;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        .hero-section::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(255,255,255,0.18), transparent 40%);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-logo {
            max-width: 180px;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn {
            border-radius: 30px;
            padding: 10px 26px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 40px rgba(0,0,0,0.12);
        }

        /* Feature Icons */
        .feature-icon {
            width: 65px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(13,110,253,0.1);
            margin: 0 auto 15px;
        }

        /* Animation */
        .fade-in {
            animation: fadeUp 0.9s ease forwards;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        footer {
            margin-top: auto;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="images/RWO7.png" alt="OWWA Logo" width="45" height="45" class="me-2">
            OWWA Supplies
        </a>

        <div class="navbar-nav ms-auto">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a class="nav-link" href="<?php echo ($_SESSION['role'] ?? '') === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'; ?>">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            <?php else: ?>
                <a class="nav-link" href="register.php">
                    <i class="bi bi-person-plus"></i> Register
                </a>
                <a class="nav-link" href="login.php">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- HERO SECTION -->
<section class="hero-section">
    <div class="container hero-content">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8 fade-in">
                <img src="images/RWO7.png" alt="OWWA Logo" class="hero-logo">
                <h1 class="display-4 fw-bold">Welcome to OWWA Supplies</h1>
                <p class="lead mt-3">
                    A centralized internal system for OWWA employees to request, track, and manage
                    office supplies, equipment, and essential resources efficiently.
                </p>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <div class="mt-4">
                        <a href="register.php" class="btn btn-light btn-lg me-3">
                            <i class="bi bi-person-plus"></i> Register
                        </a>
                        <a href="login.php" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- QUICK STATS (LOGGED IN) -->
<?php if (isset($_SESSION['user_id'])): ?>
<section class="py-5 bg-light">
    <div class="container">
        <div class="row text-center g-4">
            <div class="col-md-4">
                <div class="card p-4">
                    <i class="bi bi-clipboard-check fs-1 text-warning"></i>
                    <h6 class="mt-2">Pending Requests</h6>
                    <h3><?php echo $_SESSION['pending_requests'] ?? 0; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-4">
                    <i class="bi bi-check-circle fs-1 text-success"></i>
                    <h6 class="mt-2">Approved</h6>
                    <h3><?php echo $_SESSION['approved_requests'] ?? 0; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-4">
                    <i class="bi bi-clock-history fs-1 text-primary"></i>
                    <h6 class="mt-2">In Progress</h6>
                    <h3><?php echo $_SESSION['in_progress_requests'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- FEATURES -->
<section class="py-5 bg-white">
    <div class="container">
        <div class="row text-center mb-5">
            <div class="col">
                <h2 class="fw-bold">System Features</h2>
                <p class="text-muted">Designed to streamline supply management</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card p-4 h-100 text-center">
                    <div class="feature-icon">
                        <i class="bi bi-box-seam fs-3 text-primary"></i>
                    </div>
                    <h5>Request Supplies</h5>
                    <p class="text-muted">Submit and manage supply requests quickly and easily.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card p-4 h-100 text-center">
                    <div class="feature-icon">
                        <i class="bi bi-graph-up fs-3 text-success"></i>
                    </div>
                    <h5>Track Progress</h5>
                    <p class="text-muted">Monitor approval and fulfillment status in real time.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card p-4 h-100 text-center">
                    <div class="feature-icon">
                        <i class="bi bi-shield-lock fs-3 text-warning"></i>
                    </div>
                    <h5>Secure Access</h5>
                    <p class="text-muted">Role-based access for staff and administrators.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="bg-dark text-white text-center py-3">
    <div class="container">
        &copy; 2023 OWWA. All rights reserved. |
        <a href="https://www.owwa.gov.ph" class="text-light" target="_blank">Visit Official OWWA Site</a>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
