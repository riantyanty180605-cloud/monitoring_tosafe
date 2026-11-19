<?php
session_start();

// Jika sudah terlanjur login, cegah masuk ke login.php dan arahkan ke dashboard rolenya masing-masing
if (isset($_SESSION["login"]) && $_SESSION["login"] === true) {
    if ($_SESSION["role"] === 'admin') {
        header("Location: admin.php");
        exit;
    } else {
        header("Location: satpam.php");
        exit;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Validasi login & pembagian role
    if ($username === 'admin' && $password === '12345') {
        $_SESSION['login'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'admin';
        header('Location: admin.php'); 
        exit;
    } elseif ($username === 'satpam' && $password === '12345678') {
        $_SESSION['login'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'satpam';
        header('Location: satpam.php'); 
        exit;
    } else {
        $error = 'Username atau password salah!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Keamanan Terpadu SECURE-OPS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0b1120; margin: 0; padding: 0; }
        .bg-image-container {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: -10;
            background-image: url('PT ULP TONDANO.jpeg'); background-size: 100% 100%; background-position: center; background-repeat: no-repeat;
        }
        .bg-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: -5; background: rgba(11, 17, 32, 0.4); }
        .login-card { background: rgba(15, 23, 42, 0.88); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); }
        .btn-gradient { background: linear-gradient(90deg, #2563eb 0%, #06b6d4 100%); transition: all 0.3s ease; }
        .btn-gradient:hover { opacity: 0.95; box-shadow: 0 0 15px rgba(37, 99, 235, 0.5); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-start pl-6 md:pl-16 lg:pl-24 p-4 relative">

    <div class="bg-image-container"></div>
    <div class="bg-overlay"></div>

    <div class="login-card w-full max-w-md rounded-2xl p-6 text-white relative overflow-hidden z-10">
        <div class="flex flex-col items-center mb-5">
            <div class="flex items-center space-x-2.5 mb-2">
                <div class="w-12 h-12 bg-gradient-to-tr from-cyan-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-cyan-500/30">
                    <i class="fa-solid fa-bolt text-2xl text-yellow-300"></i>
                </div>
                <span class="bg-blue-900/60 text-blue-300 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-blue-500/30 uppercase tracking-wider">
                    ULP Tondano
                </span>
            </div>
            <h1 class="text-xl font-extrabold tracking-wide text-center uppercase text-slate-100"> MONITORING TOSAFE</h1>
            <p class="text-[11px] text-slate-400 mt-0.5 font-medium text-center"></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-3 p-2.5 bg-red-500/20 border border-red-500/50 rounded-lg text-red-300 text-xs text-center font-medium">
                <i class="fa-solid fa-circle-exclamation mr-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-3.5">
            <div>
                <label for="username" class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">Username</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400"><i class="fa-solid fa-user text-xs"></i></span>
                    <input type="text" id="username" name="username" required placeholder="admin atau satpam" class="w-full bg-slate-100/90 text-slate-900 placeholder-slate-400 text-xs rounded-lg pl-9 pr-3 py-2.5 font-semibold focus:outline-none focus:ring-2 focus:ring-cyan-400 transition">
                </div>
            </div>

            <div>
                <label for="password" class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400"><i class="fa-solid fa-lock text-xs"></i></span>
                    <input type="password" id="password" name="password" required placeholder="••••••••" class="w-full bg-slate-100/90 text-slate-900 placeholder-slate-400 text-xs rounded-lg pl-9 pr-9 py-2.5 font-semibold focus:outline-none focus:ring-2 focus:ring-cyan-400 transition">
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                        <i class="fa-solid fa-eye text-xs" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full btn-gradient py-2.5 px-4 rounded-lg text-white font-bold text-xs flex items-center justify-center space-x-2 shadow-lg mt-1">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Masuk ke Sistem</span>
            </button>
        </form>

        <div class="mt-4 pt-3 border-t border-slate-700/60">
            <div class="bg-slate-900/80 rounded-lg p-2.5 text-[11px] border border-slate-800">
                <div class="flex items-center text-slate-300 font-semibold mb-1.5">
                    <i class="fa-solid fa-circle-info text-cyan-400 mr-1.5"></i>
                    <span>Akun Pengujian:</span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-[10px] text-slate-400 font-mono">
                    <div>Admin: <span class="text-cyan-400">admin</span> / <span class="text-cyan-400">Admin123</span></div>
                    <div>Satpam: <span class="text-cyan-400">satpam</span> / <span class="text-cyan-400">Satpam123</span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeIcon = document.querySelector('#eyeIcon');
        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('device-width') === 'password' || password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>