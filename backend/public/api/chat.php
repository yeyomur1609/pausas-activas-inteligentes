<?php
// ARCHIVO: backend/public/api/chat.php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Controllers\ChatController;
use App\Middleware\CorsMiddleware;

CorsMiddleware::handle();
ChatController::handle();
