<?php
// ARCHIVO: backend/config/database.php
// Conexión PDO Singleton preparada para Azure SQL Database (pdo_sqlsrv + TLS Encrypt=yes)
// manteniendo compatibilidad opcional con MySQL local (XAMPP) cuando DB_DRIVER=mysql.
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

require_once __DIR__ . '/secrets.php';

final class Database
{
    private static ?PDO $instance = null;
    private static ?string $activeDriver = null;

    private function __construct()
    {
    }

    /**
     * Determina el motor activo: 'sqlsrv' (Azure SQL por defecto en producción) o 'mysql' (desarrollo local XAMPP).
     */
    public static function driver(): string
    {
        if (self::$activeDriver !== null) {
            return self::$activeDriver;
        }

        $explicit = strtolower(trim(Secrets::get('DB_DRIVER', '')));
        if ($explicit === 'sqlsrv' || $explicit === 'mssql' || $explicit === 'azure_sql') {
            self::$activeDriver = 'sqlsrv';
            return self::$activeDriver;
        }
        if ($explicit === 'mysql' || $explicit === 'mariadb') {
            self::$activeDriver = 'mysql';
            return self::$activeDriver;
        }

        $host = strtolower(trim(Secrets::get('DB_HOST', '127.0.0.1')));
        $portRaw = trim(Secrets::get('DB_PORT', ''));
        $portExplicit = ($portRaw !== '' && is_numeric($portRaw)) ? (int)$portRaw : null;
        $env  = strtolower(trim(Secrets::get('APP_ENV', 'development')));

        // Priorizar Azure SQL (sqlsrv) en producción, hosts *.database.windows.net, puerto explícito 1433 o contenedores sin pdo_mysql
        if (
            $env === 'production'
            || str_contains($host, '.database.windows.net')
            || $portExplicit === 1433
            || (!in_array('mysql', PDO::getAvailableDrivers(), true) && in_array('sqlsrv', PDO::getAvailableDrivers(), true))
        ) {
            self::$activeDriver = 'sqlsrv';
            return self::$activeDriver;
        }

        self::$activeDriver = 'mysql';
        return self::$activeDriver;
    }

    public static function isSqlSrv(): bool
    {
        return self::driver() === 'sqlsrv';
    }

    /**
     * Obtiene el último ID autogenerado de forma segura en SQL Server (SCOPE_IDENTITY) y MySQL (lastInsertId).
     */
    public static function lastInsertId(PDO $pdo): int
    {
        if (self::isSqlSrv()) {
            $val = $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS BIGINT)')->fetchColumn();
            if ($val !== false && $val !== null && is_numeric($val)) {
                return (int)$val;
            }
        }
        return (int)$pdo->lastInsertId();
    }

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = self::driver();
        $host   = Secrets::get('DB_HOST', $driver === 'sqlsrv' ? 'localhost' : '127.0.0.1');
        $port   = Secrets::int('DB_PORT', $driver === 'sqlsrv' ? 1433 : 3306);
        $dbName = Secrets::get('DB_NAME', 'pausas_activas_db');
        $user   = Secrets::get('DB_USER', $driver === 'sqlsrv' ? '' : 'root');
        $pass   = Secrets::get('DB_PASSWORD', '') !== ''
            ? Secrets::get('DB_PASSWORD', '')
            : Secrets::get('DB_PASS', '');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($driver === 'sqlsrv') {
            $encrypt   = Secrets::get('DB_ENCRYPT', 'yes');
            $trustCert = Secrets::get('DB_TRUST_SERVER_CERTIFICATE', 'no');
            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=15',
                $host,
                $port,
                $dbName,
                $encrypt,
                $trustCert
            );
            if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
                $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
            }
        } else {
            $charset = Secrets::get('DB_CHARSET', 'utf8mb4');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
            $options[PDO::ATTR_EMULATE_PREPARES]  = false;
            $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);

            if ($driver === 'mysql') {
                $offset = date('P');
                if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
                    self::$instance->exec("SET time_zone = '{$offset}'");
                }
            }

            return self::$instance;
        } catch (PDOException $e) {
            error_log(sprintf('[Database Error][%s] No se pudo conectar: %s', $driver, $e->getMessage()));
            if (PHP_SAPI === 'cli') {
                throw $e;
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'    => false,
                'error' => 'Error interno de conexión con el servicio de datos. Intenta nuevamente más tarde.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
