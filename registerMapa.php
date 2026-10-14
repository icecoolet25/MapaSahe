<?php
session_start();

$message = '';

if (isset($_GET['success']) && $_GET['success'] == 'registered') {
    $message = '<p style="color: #1B9C85; text-align: center; font-weight: 500;">Registration successful! You can now log in.</p>';
} elseif (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);

    switch ($error) {
        case 'emailalreadyexists':
            $message = '<p style="color: #FF0060; text-align: center;">Error: That email is already registered.</p>';
            break;
        case 'usertaken':
            $message = '<p style="color: #FF0060; text-align: center;">Error: That username is already taken.</p>';
            break;
        case 'emptyfields':
            $message = '<p style="color: #FF0060; text-align: center;">Error: Please fill in all fields.</p>';
            break;
        case 'invalidemail':
            $message = '<p style="color: #FF0060; text-align: center;">Error: Please enter a valid email address.</p>';
            break;
        case 'dberror':
        case 'failed':
            $message = '<p style="color: #FF0060; text-align: center;">Error: Server issues. Please try again later.</p>';
            break;
        default:
            $message = '<p style="color: #FF0060; text-align: center;">Registration failed. Please try again.</p>';
    }
}
?>
<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign up — MapaSahe</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="register.css">
</head>

<body>
    <iframe id="mainPage" src="system1_3.php" title="MapaSahe route planner" tabindex="-1" aria-hidden="true"></iframe>
    <div class="overlay"></div>

    <div class="wrapper" id="registerForm">
        <a href="system1_3.php" class="exit-btn" aria-label="Close and go back to the route planner"><i class='bx bx-x'></i></a>

        <form action="register_process.php" method="POST">
            <h1>Register</h1>

            <?php echo $message; ?>

            <div class="input-box">
                <input type="text" name="username" placeholder="Username" required>
                <i class='bx bxs-user-rectangle'></i>
            </div>

            <div class="input-box">
                <input type="email" name="email" placeholder="Email" required>
                <i class='bx bx-envelope'></i>
            </div>

            <div class="input-box">
                <input type="password" name="password" placeholder="Password" required>
                <i class='bx bxs-lock-alt'></i>
            </div>

            <input type="hidden" name="role" value="customer">

            <button type="submit" class="btn">Create Account</button>

            <div class="register-link">
                <p>Already have an account? <a href="loginMapa.php">Login</a></p>
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
