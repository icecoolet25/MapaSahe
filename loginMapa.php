<?php
$message = '';
if (isset($_GET['error'])) {
    $error_msg = htmlspecialchars($_GET['error']);
    if ($error_msg === 'invalidcredentials') {
        $message = '<p style="color: red; text-align: center;">Login failed: Invalid email or password.</p>';
    } else {
        $message = '<p style="color: red; text-align: center;">Error: ' . $error_msg . '</p>';
    }
}
?>
<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="Log in to MapaSahe, the jeepney route planner."/>
    <title>Log in — MapaSahe</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="login.css">
</head>

<body>
    <iframe id="mainPage" src="system1_3.php" title="MapaSahe route planner" tabindex="-1" aria-hidden="true"></iframe>
    <div class="overlay"></div>

    <div class="wrapper" id="loginForm">
        <a href="system1_3.php" class="exit-btn" aria-label="Close and go back to the route planner"><i class='bx bx-x'></i></a>

        <form action="login_process.php" method="POST">
            <h1>Login</h1>
            <?php echo $message; ?>

            <div class="input-box">
                <input type="email" name="email" placeholder="Email" required>
                <i class='bx bx-envelope'></i>
            </div>

            <div class="input-box">
                <input type="password" name="password" placeholder="Password" required>
                <i class='bx bxs-lock-alt'></i>
            </div>

            <div class="input-box" style="display:none;">
                <select name="role" id="role">
                    <option value="customer" selected></option>
                </select>
            </div>

            <div class="remember-forgot">
                <label><input type="checkbox"> Remember me</label>
                <a href="#">Forgot Password?</a>
            </div>

            <button type="submit" class="btn">Login</button>

            <div class="register-link">
                <p>Don't have an account? <a href="registerMapa.php">Register</a></p>
            </div>
        </form>
    </div>

    <script>
        const iframe = document.getElementById('mainPage');
        const overlay = document.querySelector('.overlay');
        const wrapper = document.querySelector('.wrapper');
        const exitBtn = document.querySelector('.exit-btn');

        iframe.style.filter = 'blur(8px) brightness(60%)';
        exitBtn.addEventListener('click', () => {
            wrapper.style.opacity = '0';
            overlay.style.opacity = '0';
            iframe.style.filter = 'none';
            setTimeout(() => {
                wrapper.style.display = 'none';
                overlay.style.display = 'none';
            }, 400);
        });
    </script>
</body>
</html>
