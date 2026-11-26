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

        $env  = strtolower(trim(Secrets::get('APP_ENV', 'development')));
        $host = strtolower(trim(Secrets::get('DB_HOST', $env === 'production' ? '' : '127.0.0.1')));
        $portRaw = trim(Secrets::get('DB_PORT', ''));
        $portExplicit = ($portRaw !== '' && is_numeric($portRaw)) ? (int)$portRaw : null;

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

    public static function getConnection(bool $throwOnError = false): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = self::driver();
        $isProd = Secrets::isProduction();
        $host   = trim(Secrets::get('DB_HOST', $isProd ? '' : ($driver === 'sqlsrv' ? 'localhost' : '127.0.0.1')));
        $port   = Secrets::int('DB_PORT', $driver === 'sqlsrv' ? 1433 : 3306);
        $dbName = trim(Secrets::get('DB_NAME', $isProd ? '' : 'pausas_activas_db'));
        $user   = trim(Secrets::get('DB_USER', $isProd ? '' : ($driver === 'sqlsrv' ? '' : 'root')));
        $pass   = Secrets::get('DB_PASSWORD', '') !== ''
            ? Secrets::get('DB_PASSWORD', '')
            : Secrets::get('DB_PASS', '');

        if ($isProd) {
            $missing = [];
            if ($host === '') {
                $missing[] = 'DB_HOST';
            }
            if ($dbName === '') {
                $missing[] = 'DB_NAME';
            }
            if ($user === '') {
                $missing[] = 'DB_USER';
            }
            if ($pass === '') {
                $missing[] = 'DB_PASSWORD';
            }
            if (!empty($missing)) {
                $msg = 'Faltan variables de entorno obligatorias de base de datos: ' . implode(', ', $missing);
                error_log('[Database Config Error] ' . $msg);
                if (PHP_SAPI === 'cli' || $throwOnError) {
                    throw new \RuntimeException($msg);
                }
                self::abortJson500('DB_CONFIG_MISSING', 'Servicio de datos no configurado en el servidor.');
            }
        }

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
            if (Secrets::bool('DB_ENCRYPT', false)) {
                $trustCert = Secrets::bool('DB_TRUST_SERVER_CERTIFICATE', false);
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !$trustCert;
                }
                $sslCa = trim(Secrets::get('DB_SSL_CA', ''));
                if ($sslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                }
            }
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
            if (PHP_SAPI === 'cli' || $throwOnError) {
                throw $e;
            }
            self::abortJson500('DB_CONNECTION_ERROR', 'Error interno de conexión con el servicio de datos. Intenta nuevamente más tarde.');
        }
    }

    private static function abortJson500(string $codigo, string $mensaje): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'     => false,
            'codigo' => $codigo,
            'error'  => $mensaje,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
