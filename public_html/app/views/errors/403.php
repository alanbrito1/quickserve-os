<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 32px;
            max-width: 380px;
            width: 100%;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
        }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 20px; color: #111827; margin-bottom: 8px; }
        p  { font-size: 14px; color: #6b7280; line-height: 1.5; }
        a  {
            display: inline-block;
            margin-top: 24px;
            padding: 12px 28px;
            background: #e94f37;
            color: #fff;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔐</div>
        <h1>Acceso Denegado</h1>
        <p>No tienes permisos suficientes para acceder a este módulo.<br>
           Contacta al administrador si crees que es un error.</p>
        <a href="<?= APP_BASE ?>/dashboard.php">Volver al Dashboard</a>
    </div>
</body>
</html>
