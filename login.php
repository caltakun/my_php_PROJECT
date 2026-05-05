<?php
include 'config.php';
session_start();
$message = '';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);  // Trim to avoid whitespace issues
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role'] = $row['role'];
            
            // Redirect based on role
            if ($row['role'] == 'admin') {
                header("Location: admin_dashboard.php");
                exit;  // Stop script execution
            } elseif ($row['role'] == 'employee') {
                header("Location: dashboard.php");  // Or create employee_dashboard.php if needed
                exit;
            } else {
                // Default for 'client' or other roles
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $message = '<div class="alert alert-danger animate__animated animate__shake">Invalid password. Please check your credentials.</div>';
            error_log("Login failed: Invalid password for username: $username");  // Log for debugging
        }
    } else {
        $message = '<div class="alert alert-danger animate__animated animate__shake">User not found. Please check your username.</div>';
        error_log("Login failed: User not found for username: $username");  // Log for debugging
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login - OWWA Supplies</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 50%, #90caf9 100%);
            background-attachment: fixed;
        }
        .content-wrapper { flex-grow: 1; }
        .login-section {
            background: linear-gradient(135deg, #003d82 0%, #007bff 50%, #0056b3 100%);
            color: white;
            padding: 100px 0;
            min-height: 80vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .login-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="0.5" fill="%23ffffff" opacity="0.1"/><circle cx="75" cy="75" r="0.5" fill="%23ffffff" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            z-index: 1;
        }
        .login-content { position: relative; z-index: 2; }
        .card {
            border: none;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            border-radius: 20px;
            transition: all 0.4s ease;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .card-header {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border-radius: 20px 20px 0 0 !important;
            text-align: center;
            padding: 20px;
        }
        .form-control {
            border-radius: 12px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        .form-control:focus { border-color: #007bff; box-shadow: 0 0 0 0.3rem rgba(0,123,255,0.15); transform: scale(1.02); }
        .input-group-text { background: #f8f9fa; border-radius: 12px 0 0 12px; border: 2px solid #e0e0e0; }
        .btn { border-radius: 25px; font-weight: 600; transition: all 0.3s ease; position: relative; overflow: hidden; }
        .btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: left 0.5s; }
        .btn:hover::before { left: 100%; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.2); }
        .progress { height: 5px; border-radius: 10px; }
        .fade-in { animation: fadeIn 1.2s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .security-badge { position: absolute; top: 10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; }
        footer { margin-top: auto; background: #343a40; color: #adb5bd; }
        footer a { color: #adb5bd; text-decoration: none; transition: color 0.3s ease; }
        footer a:hover { color: white; }
        .loading-spinner { display: none; margin-left: 10px; }
    </style>
</head>
<body>
    <div class="content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-lg">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center" href="index.php">
                    <img src="images/RWO7.png" alt="OWWA Supplies Logo" width="50" height="50" class="me-2 rounded-circle border border-light">
                    <span class="fw-bold">OWWA Supplies</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <div class="navbar-nav ms-auto">
                        <a href="index.php" class="nav-link" data-bs-toggle="tooltip" title="Return to Home"><i class="bi bi-house"></i> Home</a>
                        <a href="register.php" class="nav-link" data-bs-toggle="tooltip" title="Create an account"><i class="bi bi-person-plus"></i> Register</a>
                    </div>
                </div>
            </div>
        </nav>

        <section class="login-section">
            <div class="container login-content">
                <div class="row justify-content-center">
                    <div class="col-md-6 fade-in">
                        <div class="card position-relative">
                            <div class="security-badge"><i class="bi bi-shield-check"></i> Secure</div>
                            <div class="card-header">
                                <h3 class="mb-2"><i class="bi bi-shield-lock-fill"></i> OWWA Supplies Login</h3>
                                <p class="mb-0 small">Access your account securely</p>
                            </div>
                            <div class="card-body">
                                <div class="progress mb-3" id="loginProgress" style="display: none;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: 0%"></div>
                                </div>
                                <?php echo $message; ?>
                                <form method="POST" id="loginForm">
                                    <div class="mb-3">
                                        <label for="username" class="form-label fw-semibold"><i class="bi bi-person-circle"></i> Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                            <input type="text" class="form-control" id="username" name="username" required aria-describedby="usernameHelp" placeholder="Enter your username">
                                            <div class="invalid-feedback">Please enter a valid username.</div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="password" class="form-label fw-semibold"><i class="bi bi-key"></i> Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                            <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="Toggle password visibility"><i class="bi bi-eye"></i></button>
                                            <div class="invalid-feedback">Password is required.</div>
                                        </div>
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="rememberMe">
                                        <label class="form-check-label" for="rememberMe">Remember Me</label>
                                    </div>
                                    <!-- CAPTCHA Placeholder (Integrate with reCAPTCHA) -->
                                    <div class="mb-3">
                                        <div id="captcha" class="g-recaptcha" data-sitekey="your-site-key"></div>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100" id="loginBtn">
                                        <i class="bi bi-box-arrow-in-right"></i> Login
                                        <div class="spinner-border spinner-border-sm loading-spinner" role="status"></div>
                                    </button>
                                </form>
                                <div class="mt-3 text-center">
                                    <a href="forgot_password.php" class="text-muted">Forgot Password?</a><br>
                                    <p class="mb-0">New user? <a href="register.php" class="text-primary fw-semibold">Register here</a></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <footer class="text-center py-3">
        <div class="container">
            <p class="mb-1">&copy; 2023 OWWA. All rights reserved.</p>
            <p class="small"><a href="privacy_policy.php">Privacy Policy</a> | <a href="terms.php">Terms of Service</a> | <a href="https://www.owwa.gov.ph" target="_blank">Official OWWA Website</a></p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script> <!-- For CAPTCHA -->
    <script>
        // Password toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const icon = this.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        // Form validation and progress
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const spinner = document.querySelector('.loading-spinner');
            const progress = document.getElementById('loginProgress');
            const progressBar = progress.querySelector('.progress-bar');

            btn.disabled = true;
            spinner.style.display = 'inline-block';
            progress.style.display = 'block';

            let width = 0;
            const interval = setInterval(() => {
                if (width >= 100) {
                    clearInterval(interval);
                } else {
                    width += 10;
                    progressBar.style.width = width + '%';
                }
            }, 100);
        });

        // Tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>