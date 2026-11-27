<?php
// ARCHIVO: backend/src/Services/EmailService.php
// Servicio de validación de correo real (MX + anti-desechables) y envío transaccional
// prioritario vía Mailjet Send API v3.1 (HTTPS) con respaldo opcional SMTP/PHPMailer.
declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailService
{
    private const MAILJET_SEND_API_URL = 'https://api.mailjet.com/v3.1/send';
    private const DOMINIOS_DESECHABLES = [
        'ejemplo.com', 'example.com', 'correo.com', 'email.com', 'test.com',
        'prueba.com', 'fake.com', 'falso.com', 'dominio.com', 'mailinator.com',
        'yopmail.com', 'tempmail.com', 'temp-mail.org', '10minutemail.com',
        'guerrillamail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
        'getnada.com', 'dispostable.com', 'throwawaymail.com', 'fakeinbox.com',
        'mailnesia.com', 'mohmal.com',
    ];

    /**
     * Valida formato, bloquea dominios desechables y comprueba registros MX reales.
     *
     * @return array{valido:bool,email_limpio:string,error:?string}
     */
    public static function validarCorreoReal(string $email): array
    {
        $correo = strtolower(trim($email));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return [
                'valido'       => false,
                'email_limpio' => '',
                'error'        => 'Ingresa un correo electrónico con formato válido.',
            ];
        }

        $partes = explode('@', $correo, 2);
        $local  = $partes[0] ?? '';
        $dominio = $partes[1] ?? '';

        if (strlen($local) < 2 || $dominio === '') {
            return [
                'valido'       => false,
                'email_limpio' => '',
                'error'        => 'Ingresa una dirección de correo electrónico válida.',
            ];
        }

        // Permitir cuentas demo institucionales locales
        if ($dominio === 'pausas.com' || $dominio === 'pausasactivas.edu.mx') {
            return [
                'valido'       => true,
                'email_limpio' => $correo,
                'error'        => null,
            ];
        }

        if (in_array($dominio, self::DOMINIOS_DESECHABLES, true)) {
            return [
                'valido'       => false,
                'email_limpio' => '',
                'error'        => "El dominio @{$dominio} es temporal o de prueba. Usa un correo electrónico real (Gmail, Outlook, iCloud o institucional).",
            ];
        }

        if (function_exists('checkdnsrr') && !@checkdnsrr($dominio, 'MX') && !@checkdnsrr($dominio, 'A')) {
            return [
                'valido'       => false,
                'email_limpio' => '',
                'error'        => "El dominio @{$dominio} no tiene servidores de correo activos (MX). Verifica que esté bien escrito.",
            ];
        }

        return [
            'valido'       => true,
            'email_limpio' => $correo,
            'error'        => null,
        ];
    }

    /**
     * Genera par criptográfico de token (URL) y código OTP de 6 dígitos con sus respectivos hashes.
     *
     * @return array{token:string,token_hash:string,codigo:string,codigo_hash:string}
     */
    public static function generarCredencialesTemporales(): array
    {
        $token  = bin2hex(random_bytes(32));
        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'token'       => $token,
            'token_hash'  => hash('sha256', $token),
            'codigo'      => $codigo,
            'codigo_hash' => password_hash($codigo, PASSWORD_BCRYPT),
        ];
    }

    private static function frontendBaseUrl(): string
    {
        return Secrets::appUrl();
    }

    /**
     * Envía correo de verificación de cuenta nueva (enlace + código de 6 dígitos).
     *
     * @return array{enviado:bool,modo_desarrollo:bool,enlace_preview:?string,codigo_preview:?string}
     */
    public static function enviarVerificacionCuenta(
        string $email,
        string $nombre,
        string $token,
        string $codigo,
        int $ttlMinutos = 1440
    ): array {
        $appUrl = self::frontendBaseUrl();
        $enlace = $appUrl . '/login.html?vista=verificar-email&token=' . rawurlencode($token) . '&email=' . rawurlencode($email);
        $vigenciaTexto = $ttlMinutos >= 60 ? ((int)round($ttlMinutos / 60)) . ' horas' : $ttlMinutos . ' minutos';

        $html = self::construirPlantillaHtml(
            $nombre,
            'Confirma tu correo electrónico',
            'Gracias por unirte a <strong>Pausas Activas Inteligentes</strong>. Para activar la sincronización de tu racha, parques favoritos e historial ergonómico, confirma tu cuenta con el botón o el código de 6 dígitos:',
            'Confirmar mi correo electrónico',
            $enlace,
            $codigo,
            $vigenciaTexto
        );

        $texto = "Confirma tu correo en Pausas Activas Inteligentes\n\nHola {$nombre}:\nAbre este enlace: {$enlace}\nO ingresa el código de 6 dígitos: {$codigo}\nVigencia: {$vigenciaTexto}.";

        return self::despacharCorreo(
            $email,
            $nombre,
            'Confirma tu correo en Pausas Activas Inteligentes',
            $html,
            $texto,
            $enlace,
            $codigo
        );
    }

    /**
     * Envía enlace mágico + código de 6 dígitos para acceso sin contraseña (TTL 15 min).
     *
     * @return array{enviado:bool,modo_desarrollo:bool,enlace_preview:?string,codigo_preview:?string}
     */
    public static function enviarMagicLink(
        string $email,
        string $nombre,
        string $token,
        string $codigo,
        int $ttlMinutos = 15
    ): array {
        $appUrl = self::frontendBaseUrl();
        $enlace = $appUrl . '/login.html?vista=login&magic_token=' . rawurlencode($token) . '&email=' . rawurlencode($email);

        $html = self::construirPlantillaHtml(
            $nombre,
            'Tu Enlace Mágico de Acceso',
            'Solicitaste iniciar sesión sin contraseña en <strong>Pausas Activas Inteligentes</strong>. Haz clic en el botón o escribe el código de 6 dígitos en la pantalla de acceso:',
            'Entrar a mi cuenta ahora',
            $enlace,
            $codigo,
            $ttlMinutos . ' minutos'
        );

        $texto = "Acceso sin contraseña — Pausas Activas Inteligentes\n\nHola {$nombre}:\nEnlace de un solo uso ({$ttlMinutos} min): {$enlace}\nCódigo de 6 dígitos: {$codigo}";

        return self::despacharCorreo(
            $email,
            $nombre,
            'Tu enlace y código de acceso a Pausas Activas Inteligentes',
            $html,
            $texto,
            $enlace,
            $codigo
        );
    }

    /**
     * Envía enlace y código OTP para restablecer la contraseña (TTL 30 min).
     *
     * @return array{enviado:bool,modo_desarrollo:bool,enlace_preview:?string,codigo_preview:?string}
     */
    public static function enviarRecuperacionPassword(
        string $email,
        string $nombre,
        string $token,
        string $codigo,
        int $ttlMinutos = 30
    ): array {
        $appUrl = self::frontendBaseUrl();
        $enlace = $appUrl . '/login.html?vista=restablecer-password&token=' . rawurlencode($token) . '&email=' . rawurlencode($email);

        $html = self::construirPlantillaHtml(
            $nombre,
            'Restablece tu contraseña',
            'Recibimos una solicitud para cambiar la contraseña de tu cuenta en <strong>Pausas Activas Inteligentes</strong>. Usa el siguiente botón o el código de verificación:',
            'Crear nueva contraseña',
            $enlace,
            $codigo,
            $ttlMinutos . ' minutos'
        );

        $texto = "Restablecer contraseña — Pausas Activas Inteligentes\n\nHola {$nombre}:\nEnlace ({$ttlMinutos} min): {$enlace}\nCódigo de 6 dígitos: {$codigo}";

        return self::despacharCorreo(
            $email,
            $nombre,
            'Recuperación de contraseña — Pausas Activas Inteligentes',
            $html,
            $texto,
            $enlace,
            $codigo
        );
    }

    /**
     * Envía resumen HTML de progreso ergonómico, rachas y últimas pausas al correo del usuario.
     *
     * @param array<string, mixed> $estadisticas
     * @return array{enviado:bool,modo_desarrollo:bool,enlace_preview:?string,codigo_preview:?string}
     */
    public static function enviarResumenProgreso(
        string $email,
        string $nombre,
        array $estadisticas
    ): array {
        $appUrl = self::frontendBaseUrl();
        $rachaActual = (int)($estadisticas['racha_actual'] ?? 0);
        $mejorRacha  = (int)($estadisticas['mejor_racha'] ?? 0);
        $totalPausas = (int)($estadisticas['total_pausas'] ?? 0);
        $minTotales  = (int)($estadisticas['minutos_totales'] ?? 0);
        $metaDiaria  = (int)($estadisticas['meta_diaria_min'] ?? 15);
        $minHoy      = (int)($estadisticas['minutos_hoy'] ?? 0);
        $utilidadProm = number_format((float)($estadisticas['calificacion_promedio'] ?? 5.0), 1);

        $filasHtml = '';
        $historial = is_array($estadisticas['historial'] ?? null) ? array_slice($estadisticas['historial'], 0, 5) : [];
        foreach ($historial as $item) {
            $tit = htmlspecialchars((string)($item['rutina_titulo'] ?? $item['actividad'] ?? 'Pausa Activa'), ENT_QUOTES, 'UTF-8');
            $ubi = htmlspecialchars((string)($item['ubicacion'] ?? 'Santa Catarina'), ENT_QUOTES, 'UTF-8');
            $fec = htmlspecialchars((string)($item['fecha'] ?? 'Reciente'), ENT_QUOTES, 'UTF-8');
            $min = (int)($item['minutos'] ?? 3);
            $filasHtml .= "<li style=\"margin-bottom:6px;color:#cbd5e1;\"><strong>{$tit}</strong> ({$ubi}) — +{$min} min • {$fec}</li>";
        }
        if ($filasHtml === '') {
            $filasHtml = '<li style="color:#94a3b8;">Aún no hay pausas registradas en tu historial reciente.</li>';
        }

        $resumenHtml = <<<HTML
Aquí tienes el reporte actualizado de tu actividad ergonómica en <strong>Pausas Activas Inteligentes</strong>:
<ul style="margin:14px 0;padding-left:18px;color:#f8fafc;line-height:1.7;">
  <li>🔥 <strong>Racha actual:</strong> {$rachaActual} días (Mejor racha: {$mejorRacha} días)</li>
  <li>⏱️ <strong>Pausas completadas:</strong> {$totalPausas} sesiones ({$minTotales} minutos activos en total)</li>
  <li>🎯 <strong>Progreso de hoy:</strong> {$minHoy} / {$metaDiaria} min de tu meta diaria</li>
  <li>⭐ <strong>Utilidad promedio percibida:</strong> {$utilidadProm} / 5.0</li>
</ul>
<div style="margin-top:12px;font-size:13px;color:#38bdf8;font-weight:700;">Últimas pausas registradas:</div>
<ul style="margin:8px 0 0 0;padding-left:18px;font-size:13px;line-height:1.5;">{$filasHtml}</ul>
HTML;

        $html = self::construirPlantillaHtml(
            $nombre,
            'Tu Resumen de Progreso y Bienestar',
            $resumenHtml,
            'Abrir Pausas Activas Inteligentes',
            $appUrl,
            'ACTIVO',
            'Reporte informativo'
        );

        $texto = "Resumen de Progreso — Pausas Activas Inteligentes\n\nHola {$nombre}:\nRacha actual: {$rachaActual} días\nMejor racha: {$mejorRacha} días\nPausas completadas: {$totalPausas} ({$minTotales} min)\nProgreso hoy: {$minHoy}/{$metaDiaria} min.";

        return self::despacharCorreo(
            $email,
            $nombre,
            '📊 Tu Resumen de Progreso en Pausas Activas Inteligentes',
            $html,
            $texto,
            $appUrl,
            'RESUMEN'
        );
    }

    private static function construirPlantillaHtml(
        string $nombre,
        string $titulo,
        string $mensajeHtml,
        string $textoBoton,
        string $enlace,
        string $codigo,
        string $vigencia
    ): string {
        $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $enlaceSeguro = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
        $codigoSeguro = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
        $vigenciaSegura = htmlspecialchars($vigencia, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:0;background-color:#0b1120;font-family:'Segoe UI',Roboto,Arial,sans-serif;color:#f8fafc;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 14px;background-color:#0b1120;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;background-color:#1e293b;border:1px solid #334155;border-radius:20px;padding:32px 26px;">
          <tr>
            <td>
              <div style="margin-bottom:12px;">
                <span style="display:inline-block;background-color:#172554;color:#38bdf8;border:1px solid #2563eb;border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;">
                  Pausas Activas Inteligentes • Salud Postural y Parques
                </span>
              </div>
              <h1 style="margin:0 0 14px 0;font-size:22px;color:#ffffff;">{$titulo}</h1>
              <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#cbd5e1;">
                Hola <strong style="color:#ffffff;">{$nombreSeguro}</strong>: {$mensajeHtml}
              </p>
              <div style="text-align:center;margin:24px 0;">
                <a href="{$enlaceSeguro}" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#0284c7);color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 28px;border-radius:12px;">
                  {$textoBoton}
                </a>
              </div>
              <div style="background-color:#0f172a;border:1px solid #334155;border-radius:14px;padding:16px;text-align:center;margin-bottom:20px;">
                <div style="font-size:12px;color:#94a3b8;margin-bottom:6px;">O escribe este código de verificación de 6 dígitos:</div>
                <div style="font-size:30px;font-weight:800;letter-spacing:8px;color:#38bdf8;font-family:Consolas,monospace;">{$codigoSeguro}</div>
              </div>
              <p style="margin:0 0 8px 0;font-size:12.5px;color:#94a3b8;">
                ⏱️ Este enlace y código son de un solo uso y vencen en <strong>{$vigenciaSegura}</strong>.
              </p>
              <p style="margin:0;font-size:12px;color:#64748b;">
                Si tú no realizaste esta solicitud, puedes ignorar este mensaje con total tranquilidad.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Despacha correo transaccional priorizando Mailjet Send API v3.1 (HTTPS)
     * y usando SMTP/PHPMailer solo como respaldo si MAIL_PROVIDER != 'mailjet' o faltan llaves de Mailjet.
     *
     * @return array{enviado:bool,proveedor:string,modo_desarrollo:bool,enlace_preview:?string,codigo_preview:?string}
     */
    private static function despacharCorreo(
        string $paraEmail,
        string $paraNombre,
        string $asunto,
        string $html,
        string $textoPlano,
        string $enlacePreview,
        string $codigoPreview
    ): array {
        $cfg = Secrets::mailConfig();
        $esDev = !Secrets::isProduction();
        $destinatarioLimpio = preg_replace('/[\r\n<>"]/', '', trim($paraNombre)) ?: 'Usuario';

        $tieneMailjet = $cfg['mailjet_api_key'] !== '' && $cfg['mailjet_secret_key'] !== '';
        $provider = $cfg['provider'];
        $debeUsarMailjet = ($provider === '' || $provider === 'mailjet') && $tieneMailjet;

        if ($debeUsarMailjet) {
            if (self::enviarPorMailjet($cfg, $paraEmail, $destinatarioLimpio, $asunto, $html, $textoPlano)) {
                return [
                    'enviado'         => true,
                    'proveedor'       => 'mailjet',
                    'modo_desarrollo' => false,
                    'enlace_preview'  => null,
                    'codigo_preview'  => null,
                ];
            }
        } else {
            if ($provider === 'mailjet' && !$tieneMailjet) {
                error_log('[EmailService Warning] MAIL_PROVIDER=mailjet pero faltan MAILJET_API_KEY o MAILJET_SECRET_KEY; evaluando respaldo SMTP.');
            }
            if (self::enviarPorSmtp($cfg, $paraEmail, $destinatarioLimpio, $asunto, $html, $textoPlano)) {
                return [
                    'enviado'         => true,
                    'proveedor'       => 'smtp',
                    'modo_desarrollo' => false,
                    'enlace_preview'  => null,
                    'codigo_preview'  => null,
                ];
            }
        }

        // En desarrollo local sin proveedor activo, devolver vista previa segura para pruebas
        if ($esDev) {
            return [
                'enviado'         => true,
                'proveedor'       => 'dev_preview',
                'modo_desarrollo' => true,
                'enlace_preview'  => $enlacePreview,
                'codigo_preview'  => $codigoPreview,
            ];
        }

        return [
            'enviado'         => false,
            'proveedor'       => $debeUsarMailjet ? 'mailjet' : ($provider !== '' ? $provider : 'none'),
            'modo_desarrollo' => false,
            'enlace_preview'  => null,
            'codigo_preview'  => null,
        ];
    }

    /**
     * Envía un correo mediante Mailjet Send API v3.1 (HTTPS + Basic Auth MAILJET_API_KEY:MAILJET_SECRET_KEY).
     *
     * @param array<string, mixed> $cfg
     */
    private static function enviarPorMailjet(
        array $cfg,
        string $paraEmail,
        string $paraNombre,
        string $asunto,
        string $html,
        string $textoPlano
    ): bool {
        if (!function_exists('curl_init')) {
            error_log('[EmailService Mailjet Error] La extensión cURL de PHP no está disponible.');
            return false;
        }

        $fromEmail = (string)($cfg['from_email'] ?? '');
        $fromName  = (string)($cfg['from_name'] ?? 'Pausas Activas Inteligentes');
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[EmailService Mailjet Error] MAIL_FROM_EMAIL no configurado o con formato inválido.');
            return false;
        }

        $payload = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => $fromEmail,
                        'Name'  => $fromName,
                    ],
                    'To' => [
                        [
                            'Email' => $paraEmail,
                            'Name'  => $paraNombre,
                        ],
                    ],
                    'Subject'  => $asunto,
                    'HTMLPart' => $html,
                    'TextPart' => $textoPlano,
                ],
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            error_log('[EmailService Mailjet Error] No se pudo serializar el payload JSON.');
            return false;
        }

        $ch = curl_init(self::MAILJET_SEND_API_URL);
        if ($ch === false) {
            error_log('[EmailService Mailjet Error] No se pudo inicializar cURL.');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => (string)$cfg['mailjet_api_key'] . ':' . (string)$cfg['mailjet_secret_key'],
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw       = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErrno !== 0) {
            error_log(sprintf(
                '[EmailService Mailjet cURL Error] errno=%d err=%s to=%s',
                $curlErrno,
                $curlError,
                $paraEmail
            ));
            return false;
        }

        $rawStr = (string)$raw;
        $decoded = json_decode($rawStr, true);

        if ($httpCode >= 400 || $httpCode < 200) {
            $resumenBody = mb_substr(preg_replace('/\s+/', ' ', $rawStr) ?? $rawStr, 0, 500, 'UTF-8');
            error_log(sprintf(
                '[EmailService Mailjet HTTP Error] status=%d to=%s body=%s',
                $httpCode,
                $paraEmail,
                $resumenBody
            ));
            return false;
        }

        $msgStatus = is_array($decoded) ? strtolower((string)($decoded['Messages'][0]['Status'] ?? '')) : '';
        if ($msgStatus !== 'success') {
            $resumenBody = mb_substr(preg_replace('/\s+/', ' ', $rawStr) ?? $rawStr, 0, 500, 'UTF-8');
            error_log(sprintf(
                '[EmailService Mailjet Delivery Error] status=%d msg_status=%s to=%s body=%s',
                $httpCode,
                $msgStatus !== '' ? $msgStatus : 'unknown',
                $paraEmail,
                $resumenBody
            ));
            return false;
        }

        return true;
    }

    /**
     * Respaldo opcional mediante PHPMailer/SMTP cuando MAIL_PROVIDER != 'mailjet' o faltan llaves de Mailjet.
     *
     * @param array<string, mixed> $cfg
     */
    private static function enviarPorSmtp(
        array $cfg,
        string $paraEmail,
        string $paraNombre,
        string $asunto,
        string $html,
        string $textoPlano
    ): bool {
        $smtpHost = (string)($cfg['smtp_host'] ?? '');
        $smtpUser = (string)($cfg['smtp_user'] ?? '');
        $smtpPass = (string)($cfg['smtp_pass'] ?? '');

        if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || !class_exists(PHPMailer::class)) {
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $puerto = (int)($cfg['smtp_port'] ?? 587);
            $seguridad = strtolower((string)($cfg['smtp_secure'] ?? 'tls'));

            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->Port       = $puerto;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 12;
            $mail->SMTPSecure = ($seguridad === 'ssl' || $puerto === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $remitente = (string)($cfg['from_email'] ?? $smtpUser);
            if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
                $remitente = $smtpUser;
            }

            $mail->setFrom($remitente, (string)($cfg['from_name'] ?? 'Pausas Activas Inteligentes'));
            $mail->addAddress($paraEmail, $paraNombre);
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $html;
            $mail->AltBody = $textoPlano;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('[EmailService SMTP Error] ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
            return false;
        }
    }
}
