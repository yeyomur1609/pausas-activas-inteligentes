<?php
// ARCHIVO: backend/public/api/usuarios.php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Controllers\UsuariosController;
use App\Middleware\CorsMiddleware;

CorsMiddleware::handle();
UsuariosController::handle();
