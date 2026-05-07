<?php
// Punto de entrada. Redirige según estado de sesión.
require_once __DIR__ . '/app/config/app.php';
require_once __DIR__ . '/app/helpers/AuthHelper.php';

auth_session_init();

header('Location: ' . APP_BASE . (auth_check() ? '/dashboard.php' : '/login.php'));
exit;
