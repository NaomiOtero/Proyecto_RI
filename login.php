<?php

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '1');
session_start();

if (isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'funciones.php';

$error   = '';
$success = '';
$tabActiva = 'login'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar') {
    $tabActiva = 'registro';
    $nombre      = trim($_POST['nombre']      ?? '');
    $email       = trim($_POST['email']       ?? '');
    $pass        = $_POST['password']         ?? '';
    $pass2       = $_POST['password2']        ?? '';
    $id_plan     = (int)($_POST['id_plan']    ?? 1);
    $cuenta_email = trim($_POST['cuenta_email'] ?? '');

    if (!$nombre || !$email || !$pass) {
        $error = "Todos los campos son obligatorios.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "El correo no es válido.";
    } elseif (strlen($pass) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($pass !== $pass2) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $chk = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $chk->bind_param("s", $email);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $error = "Ya existe una cuenta con ese correo.";
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $id_cuenta = null;

            if ($cuenta_email !== '') {
                // Buscar cuenta existente
                $stmt_cuenta = $conexion->prepare("SELECT c.id_cuenta, c.max_usuarios, COUNT(u.id_usuario) as num_usuarios FROM cuentas c LEFT JOIN usuarios u ON c.id_cuenta = u.id_cuenta WHERE c.email_cuenta = ? GROUP BY c.id_cuenta");
                $stmt_cuenta->bind_param("s", $cuenta_email);
                $stmt_cuenta->execute();
                $stmt_cuenta->bind_result($id_cuenta_temp, $max_usuarios, $num_usuarios);
                if ($stmt_cuenta->fetch()) {
                    if ($num_usuarios >= $max_usuarios) {
                        $error = "La cuenta ya tiene el máximo de usuarios permitidos.";
                    } else {
                        $id_cuenta = $id_cuenta_temp;
                    }
                } else {
                    $error = "Cuenta no encontrada.";
                }
                $stmt_cuenta->close();
            } else {
                // Crear nueva cuenta
                $max_usuarios = $id_plan == 1 ? 2 : 5;
                $stmt_cuenta = $conexion->prepare("INSERT INTO cuentas (id_plan, max_usuarios, email_cuenta) VALUES (?, ?, ?)");
                $stmt_cuenta->bind_param("iis", $id_plan, $max_usuarios, $email);
                if ($stmt_cuenta->execute()) {
                    $id_cuenta = $conexion->insert_id;
                } else {
                    $error = "Error al crear cuenta.";
                }
                $stmt_cuenta->close();
            }

            if (!$error && $id_cuenta) {
                $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, email, password, id_cuenta) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $nombre, $email, $hash, $id_cuenta);
                if ($stmt->execute()) {
                    $success = "¡Cuenta creada! Ya puedes iniciar sesión.";
                    $tabActiva = 'login';
                } else {
                    $error = "Error al registrar. Intenta de nuevo.";
                }
                $stmt->close();
            }
        }
        $chk->close();
    }
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'login') {
    $email = trim($_POST['email']  ?? '');
    $pass  = $_POST['password']    ?? '';

    if (!$email || !$pass) {
        $error = "Ingresa tu correo y contraseña.";
    } else {
        $stmt = $conexion->prepare(
            "SELECT u.id_usuario, u.nombre, u.password, u.id_cuenta, c.id_plan, pl.nombre AS nombre_plan
             FROM usuarios u
             JOIN cuentas c ON u.id_cuenta = c.id_cuenta
             JOIN planes pl ON c.id_plan = pl.id_plan
             WHERE u.email = ?"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $error = "Correo o contraseña incorrectos.";
        } else {
            $stmt->bind_result($id_usuario, $nombre, $hash, $id_cuenta, $id_plan, $nombre_plan);
            $stmt->fetch();

            if (password_verify($pass, $hash)) {
                $_SESSION['id_usuario']   = $id_usuario;
                $_SESSION['nombre']       = $nombre;
                $_SESSION['id_cuenta']    = $id_cuenta;
                $_SESSION['id_plan']      = $id_plan;
                $_SESSION['nombre_plan']  = $nombre_plan;
                header("Location: index.php");
                exit;
            } else {
                $error = "Correo o contraseña incorrectos.";
            }
        }
        $stmt->close();
    }
}

// Cargar planes para el formulario de registro
$planes = [];
$res = $conexion->query("SELECT id_plan, nombre, descripcion, precio FROM planes ORDER BY precio");
while ($r = $res->fetch_assoc()) $planes[] = $r;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso — CinePelis</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md">

    <!-- LOGO -->
    <div class="text-center mb-8">
        <h1 class="text-white text-4xl font-bold">📽️ CinePelis</h1>
        <p class="text-gray-400 text-sm mt-1">Tu catálogo de películas</p>
    </div>

    <!-- TABS -->
    <div class="flex rounded-xl overflow-hidden mb-6 border border-gray-700">
        <button id="tabLogin" onclick="mostrarTab('login')"
            class="flex-1 py-3 text-sm font-semibold transition">
            Iniciar sesión
        </button>
        <button id="tabRegistro" onclick="mostrarTab('registro')"
            class="flex-1 py-3 text-sm font-semibold transition">
            Registrarse
        </button>
    </div>

    <!-- ALERTAS -->
    <?php if ($error): ?>
    <div class="mb-4 p-3 bg-red-900 border border-red-600 text-red-200 rounded-lg text-sm">
        ⚠️ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="mb-4 p-3 bg-green-900 border border-green-600 text-green-200 rounded-lg text-sm">
        ✅ <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <!-- FORM: LOGIN -->
    <div id="formLogin" class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
        <form method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="accion" value="login">
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Correo electrónico</label>
                <input type="email" name="email" required placeholder="tu@correo.com"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Contraseña</label>
                <input type="password" name="password" required placeholder="••••••••"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <button type="submit"
                class="bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg font-semibold mt-2">
                Entrar
            </button>
        </form>
        <p class="text-gray-500 text-xs text-center mt-4">
            ¿No tienes cuenta?
            <button onclick="mostrarTab('registro')" class="text-red-400 hover:underline">Regístrate</button>
        </p>
    </div>

    <!-- FORM: REGISTRO -->
    <div id="formRegistro" class="hidden bg-gray-900 border border-gray-700 rounded-2xl p-6">
        <form method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="accion" value="registrar">
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Nombre</label>
                <input type="text" name="nombre" required placeholder="Tu nombre"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Correo electrónico</label>
                <input type="email" name="email" required placeholder="tu@correo.com"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Email de cuenta existente (opcional)</label>
                <input type="email" name="cuenta_email" placeholder="Si te quieres unir a una cuenta existente"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Contraseña</label>
                <input type="password" name="password" required placeholder="Mínimo 6 caracteres"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block">Confirmar contraseña</label>
                <input type="password" name="password2" required placeholder="Repite la contraseña"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>

            <!-- SELECCIÓN DE PLAN -->
            <div>
                <label class="text-gray-400 text-xs mb-2 block">Elige tu plan</label>
                <div class="grid grid-cols-2 gap-3">
                <?php foreach ($planes as $plan): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="id_plan" value="<?= $plan['id_plan'] ?>"
                            class="hidden peer" <?= $plan['id_plan'] == 1 ? 'checked' : '' ?>>
                        <div class="peer-checked:border-red-500 peer-checked:bg-gray-700
                                    border-2 border-gray-600 bg-gray-800 rounded-xl p-3 text-center transition">
                            <p class="text-white font-bold text-sm">
                                <?= $plan['id_plan'] == 2 ? '⭐ ' : '📦 ' ?><?= htmlspecialchars($plan['nombre']) ?>
                            </p>
                            <p class="text-gray-400 text-xs mt-1"><?= htmlspecialchars($plan['descripcion']) ?></p>
                            <p class="text-red-400 font-semibold text-sm mt-2">
                                <?= $plan['precio'] > 0 ? '$' . number_format($plan['precio'], 2) . '/mes' : 'Gratis' ?>
                            </p>
                        </div>
                    </label>
                <?php endforeach; ?>
                </div>
            </div>

            <button type="submit"
                class="bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg font-semibold">
                Crear cuenta
            </button>
        </form>
        <p class="text-gray-500 text-xs text-center mt-4">
            ¿Ya tienes cuenta?
            <button onclick="mostrarTab('login')" class="text-red-400 hover:underline">Inicia sesión</button>
        </p>
    </div>

</div>

<script>
const TAB_ACTIVA = '<?= $tabActiva ?>';

function mostrarTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('formLogin').classList.toggle('hidden', !isLogin);
    document.getElementById('formRegistro').classList.toggle('hidden', isLogin);
    document.getElementById('tabLogin').className =
        'flex-1 py-3 text-sm font-semibold transition ' +
        (isLogin ? 'bg-red-700 text-white' : 'bg-gray-800 text-gray-400');
    document.getElementById('tabRegistro').className =
        'flex-1 py-3 text-sm font-semibold transition ' +
        (!isLogin ? 'bg-red-700 text-white' : 'bg-gray-800 text-gray-400');
}

mostrarTab(TAB_ACTIVA);
</script>
</body>
</html>
<?php $conexion->close(); ?>
