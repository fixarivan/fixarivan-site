<?php
session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/api/lib/admin_auth.php';

function admin_login_safe_next(?string $next): string {
    $next = trim((string) $next);
    if ($next === '') {
        return '../index.php';
    }
    if (preg_match('#^(https?:)?//#i', $next) || strpos($next, "\0") !== false) {
        return '../index.php';
    }
    if (!preg_match('#^[a-zA-Z0-9_./-]+$#', $next)) {
        return '../index.php';
    }
    return $next;
}

if (isset($_GET['next'])) {
    $n = admin_login_safe_next($_GET['next']);
    $_SESSION['login_redirect_next'] = ($n !== '../index.php') ? $n : '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['login_redirect_next']);
}

// Уже вошли — на целевую страницу или дашборд
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_GET['next'])) {
        header('Location: ' . admin_login_safe_next($_GET['next']));
        exit();
    }
    header('Location: ../index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $postedNext = admin_login_safe_next($_POST['next'] ?? '');

    if (fixarivan_admin_verify((string) $username, (string) $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = (string) $username;
        $target = $postedNext;
        if ($target === '' || $target === '../index.php') {
            $target = !empty($_SESSION['login_redirect_next']) ? admin_login_safe_next($_SESSION['login_redirect_next']) : '../index.php';
        }
        unset($_SESSION['login_redirect_next']);
        header('Location: ' . admin_login_safe_next($target));
        exit();
    }
    $error = 'Неверный логин или пароль!';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FixariVan - Вход в админ панель</title>
    <link rel="icon" href="../favicon.svg" type="image/svg+xml">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0a0a0a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        /* Animated Background */
        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .star {
            position: absolute;
            background: white;
            border-radius: 50%;
            animation: twinkle 2s infinite;
        }

        .star:nth-child(odd) {
            animation-delay: 1s;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 1; }
        }

        /* Nebula Effect */
        .nebula {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.3) 0%, transparent 50%),
                        radial-gradient(circle at 40% 80%, rgba(120, 219, 255, 0.3) 0%, transparent 50%);
            pointer-events: none;
            z-index: 2;
        }

        .login-container {
            position: relative;
            z-index: 10;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 25px;
            padding: 40px;
            width: 400px;
            max-width: 90%;
            text-align: center;
        }

        .logo {
            font-size: 3rem;
            font-weight: 900;
            background: linear-gradient(45deg, #00d4ff, #ff00ff, #00ff88);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradientShift 3s ease-in-out infinite;
            text-shadow: 0 0 30px rgba(0, 212, 255, 0.5);
            margin-bottom: 20px;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .login-title {
            color: white;
            font-size: 1.8rem;
            margin-bottom: 30px;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            color: white;
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
        }

        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .login-btn {
            background: linear-gradient(45deg, #00d4ff, #ff00ff);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 212, 255, 0.3);
        }

        .error-message {
            background: rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 0, 0, 0.3);
        }

        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            padding: 10px 15px;
            border-radius: 25px;
            color: white;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="stars" id="stars"></div>
    <div class="nebula"></div>

    <a href="../track.html" class="back-btn">← Трекинг заявок</a>

    <div class="login-container">
        <div class="logo">FixariVan</div>
        <h2 class="login-title">🔐 Вход в админ панель</h2>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="next" value="<?php echo htmlspecialchars((string)($_SESSION['login_redirect_next'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="username">Логин:</label>
                <input type="text" id="username" name="username" placeholder="Введите логин" required>
            </div>
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" placeholder="Введите пароль" required>
            </div>
            <button type="submit" class="login-btn">Войти в систему</button>
        </form>
    </div>

    <script>
        // Create animated stars
        function createStars() {
            const starsContainer = document.getElementById('stars');
            const starCount = 100;
            
            for (let i = 0; i < starCount; i++) {
                const star = document.createElement('div');
                star.className = 'star';
                star.style.left = Math.random() * 100 + '%';
                star.style.top = Math.random() * 100 + '%';
                star.style.width = Math.random() * 3 + 'px';
                star.style.height = star.style.width;
                star.style.animationDelay = Math.random() * 2 + 's';
                starsContainer.appendChild(star);
            }
        }

        // Initialize stars on page load
        document.addEventListener('DOMContentLoaded', function() {
            createStars();
        });
    </script>
</body>
</html>
