<?php
// Cierra la sesión y redirige al login con confirmación.
require_once __DIR__ . '/app/config/app.php';
require_once __DIR__ . '/app/helpers/AuthHelper.php';

auth_session_init();
auth_logout();

header('Location: ' . APP_BASE . '/login.php?msg=logout');
exit;
