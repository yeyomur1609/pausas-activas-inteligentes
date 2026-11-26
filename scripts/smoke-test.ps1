param(
  [string]$BackendUrl = '',
  [string]$FrontendUrl = '',
  [int]$ColdStartTimeoutSec = 75,
  [switch]$SkipAuthDemo
)

$ErrorActionPreference = 'Stop'

# Habilitar TLS 1.2+ para llamadas HTTPS hacia Render / Vercel en Windows PowerShell 5.1
try {
  [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch {}

if ([string]::IsNullOrWhiteSpace($BackendUrl) -or [string]::IsNullOrWhiteSpace($FrontendUrl)) {
  $devUp = $false
  try {
    $probe = Invoke-WebRequest -Uri 'http://localhost:8000/health.php' -TimeoutSec 2 -UseBasicParsing
    if ($probe.StatusCode -eq 200) { $devUp = $true }
  } catch {}
  if ($devUp) {
    if ([string]::IsNullOrWhiteSpace($BackendUrl)) { $BackendUrl = 'http://localhost:8000' }
    if ([string]::IsNullOrWhiteSpace($FrontendUrl)) { $FrontendUrl = 'http://localhost:3000' }
  } else {
    if ([string]::IsNullOrWhiteSpace($BackendUrl)) { $BackendUrl = 'http://localhost/pausas-activas-inteligentes/backend/public' }
    if ([string]::IsNullOrWhiteSpace($FrontendUrl)) { $FrontendUrl = 'http://localhost/pausas-activas-inteligentes/frontend' }
  }
}

$BackendUrl = $BackendUrl.TrimEnd('/')
$FrontendUrl = $FrontendUrl.TrimEnd('/')
$OriginHeader = ([Uri]$FrontendUrl).GetLeftPart('Authority')
$IsRemoteHttps = $BackendUrl.StartsWith('https://', [System.StringComparison]::OrdinalIgnoreCase)

function Assert-True([bool]$Condition, [string]$Message) {
  if (-not $Condition) {
    throw "FALLO: $Message"
  }
  Write-Host "[OK] $Message" -ForegroundColor Green
}

function Get-HttpErrorStatus($ExceptionRecord) {
  $ex = $ExceptionRecord.Exception
  if ($ex.Response) {
    try {
      return [int]$ex.Response.StatusCode
    } catch {}
  }
  return 0
}

Write-Host "=== Smoke Test: Pausas Activas Inteligentes ===" -ForegroundColor Cyan
Write-Host "Backend : $BackendUrl" -ForegroundColor DarkCyan
Write-Host "Frontend: $FrontendUrl (Origin: $OriginHeader)" -ForegroundColor DarkCyan
if ($IsRemoteHttps) {
  Write-Host "Modo remoto HTTPS detectado: tolerando cold start de Render hasta ${ColdStartTimeoutSec}s..." -ForegroundColor Yellow
}

# 1. Backend /health (rewrite limpio), /health?deep=1 y /health.php (absorbe posible cold start de Render Free)
$healthCleanResp = Invoke-WebRequest -Uri "$BackendUrl/health" -TimeoutSec $ColdStartTimeoutSec -Headers @{ Origin = $OriginHeader } -UseBasicParsing
Assert-True ($healthCleanResp.StatusCode -eq 200) "GET /health (rewrite limpio) -> 200 OK"
$healthJson = $healthCleanResp.Content | ConvertFrom-Json
Assert-True ($healthJson.ok -eq $true) "GET /health -> ok: true (PHP $($healthJson.php), driver=$($healthJson.db_driver), env=$($healthJson.env))"

$healthDeepResp = Invoke-WebRequest -Uri "$BackendUrl/health?deep=1" -TimeoutSec 25 -Headers @{ Origin = $OriginHeader } -UseBasicParsing
$healthDeepJson = $healthDeepResp.Content | ConvertFrom-Json
Assert-True ($healthDeepResp.StatusCode -eq 200 -and $healthDeepJson.db -eq 'connected') "GET /health?deep=1 -> 200 OK (db=connected)"

# 1b. Preflight CORS OPTIONS y verificación de cabeceras cross-domain
$preflightResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=session" -Method Options `
  -Headers @{
    Origin                         = $OriginHeader
    'Access-Control-Request-Method'  = 'POST'
    'Access-Control-Request-Headers' = 'Content-Type, Authorization, X-CSRF-Token'
  } -TimeoutSec 20 -UseBasicParsing
Assert-True ($preflightResp.StatusCode -eq 204) "OPTIONS /api/auth.php (CORS preflight) -> 204 No Content"
$acao = [string]$preflightResp.Headers['Access-Control-Allow-Origin']
$acac = [string]$preflightResp.Headers['Access-Control-Allow-Credentials']
Assert-True ($acao -eq $OriginHeader -and $acac -eq 'true') "CORS autoriza Origin=$OriginHeader con Allow-Credentials: true"

# 2. Handshake inicial GET /api/auth.php?accion=session
$sessInitResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=session" -SessionVariable apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 30 -UseBasicParsing
Assert-True ($sessInitResp.StatusCode -eq 200) "GET /api/auth.php?accion=session (inicial) -> 200 OK"
$sessInitJson = $sessInitResp.Content | ConvertFrom-Json
$csrfToken = [string]$sessInitJson.csrf_token
Assert-True (-not [string]::IsNullOrWhiteSpace($csrfToken)) "Handshake entrega token CSRF válido"
Assert-True ($sessInitJson.autenticado -eq $false) "Sesión inicial no autenticada devuelve autenticado: false"

# 2b. Seguridad MPA: Endpoints protegidos deben devolver HTTP 401 sin sesión activa
function Assert-HttpStatus401([string]$Url, [string]$Method = 'GET', $Session = $null, [hashtable]$Headers = @{}, [string]$Body = '') {
  $status = 0
  try {
    if ($Method -eq 'POST') {
      Invoke-WebRequest -Uri $Url -Method Post -WebSession $Session -Headers $Headers -ContentType 'application/json' -Body $Body -TimeoutSec 25 -UseBasicParsing | Out-Null
      $status = 200
    } else {
      Invoke-WebRequest -Uri $Url -Method Get -WebSession $Session -Headers $Headers -TimeoutSec 25 -UseBasicParsing | Out-Null
      $status = 200
    }
  } catch {
    $status = Get-HttpErrorStatus $_
  }
  Assert-True ($status -eq 401) "$Method $Url sin sesión -> HTTP 401"
}

Assert-HttpStatus401 "$BackendUrl/api/rutinas.php" 'GET' $apiSession @{ Origin = $OriginHeader }
Assert-HttpStatus401 "$BackendUrl/api/mapa.php?accion=parques_cercanos&lat=25.6768&lng=-100.4615&radio=3000" 'GET' $apiSession @{ Origin = $OriginHeader }
Assert-HttpStatus401 "$BackendUrl/api/usuarios.php?accion=comunidad" 'GET' $apiSession @{ Origin = $OriginHeader }
Assert-HttpStatus401 "$BackendUrl/api/usuarios.php?accion=perfil" 'GET' $apiSession @{ Origin = $OriginHeader }
Assert-HttpStatus401 "$BackendUrl/api/chat.php" 'POST' $apiSession @{ 'X-CSRF-Token' = $csrfToken; 'Origin' = $OriginHeader } '{"accion":"mensaje","mensaje":"Hola"}'

if (-not $SkipAuthDemo) {
  # 3. Prueba de rechazo 401 con contraseña incorrecta
  $badStatus = 0
  try {
    $badBody = @{ email = 'estudiante@pausasactivas.edu.mx'; password = 'clave_incorrecta_xyz' } | ConvertTo-Json -Compress
    Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=login" -Method Post -WebSession $apiSession `
      -Headers @{ 'X-CSRF-Token' = $csrfToken; 'Origin' = $OriginHeader } `
      -ContentType 'application/json' -Body $badBody -TimeoutSec 25 -UseBasicParsing | Out-Null
  } catch {
    $badStatus = Get-HttpErrorStatus $_
  }
  Assert-True ($badStatus -eq 401) "POST /api/auth.php?accion=login con clave incorrecta -> HTTP 401"

  # 4. Login válido con estudiante@pausasactivas.edu.mx / admin12345
  $loginBody = @{ email = 'estudiante@pausasactivas.edu.mx'; password = 'admin12345' } | ConvertTo-Json -Compress
  $loginResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=login" -Method Post -WebSession $apiSession `
    -Headers @{ 'X-CSRF-Token' = $csrfToken; 'Origin' = $OriginHeader } `
    -ContentType 'application/json' -Body $loginBody -TimeoutSec 25 -UseBasicParsing
  Assert-True ($loginResp.StatusCode -eq 200) "POST /api/auth.php?accion=login (estudiante@pausasactivas.edu.mx) -> 200 OK"
  $loginJson = $loginResp.Content | ConvertFrom-Json
  $jwtToken = [string]$loginJson.token
  Assert-True ($loginJson.ok -eq $true -and $loginJson.usuario.email -eq 'estudiante@pausasactivas.edu.mx' -and -not [string]::IsNullOrWhiteSpace($jwtToken)) "Login devuelve usuario autenticado y JWT Bearer firmado"

  # 4b. Verificación Stateless pura (JWT Bearer SIN cookies ni X-CSRF-Token, simulando Vercel <-> Render sin cookies de terceros)
  $statelessPerfilResp = Invoke-WebRequest -Uri "$BackendUrl/api/usuarios.php?accion=perfil" `
    -Headers @{ Authorization = "Bearer $jwtToken"; Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  $statelessPerfilJson = $statelessPerfilResp.Content | ConvertFrom-Json
  Assert-True ($statelessPerfilResp.StatusCode -eq 200 -and $statelessPerfilJson.ok -eq $true -and $statelessPerfilJson.usuario.email -eq 'estudiante@pausasactivas.edu.mx') "GET /api/usuarios.php con Authorization: Bearer (sin cookies) -> 200 OK"

  $statelessPrefsResp = Invoke-WebRequest -Uri "$BackendUrl/api/usuarios.php?accion=guardar_preferencias" -Method Put `
    -Headers @{ Authorization = "Bearer $jwtToken"; Origin = $OriginHeader } `
    -ContentType 'application/json' -Body '{"accion":"guardar_preferencias","preferencias":{"tema_claro":true}}' -TimeoutSec 25 -UseBasicParsing
  $statelessPrefsJson = $statelessPrefsResp.Content | ConvertFrom-Json
  Assert-True ($statelessPrefsResp.StatusCode -eq 200 -and $statelessPrefsJson.ok -eq $true) "PUT /api/usuarios.php con Authorization: Bearer (sin cookie CSRF) -> 200 OK"

  $tamperedStatus = 0
  try {
    Invoke-WebRequest -Uri "$BackendUrl/api/usuarios.php?accion=perfil" `
      -Headers @{ Authorization = "Bearer ${jwtToken}x"; Origin = $OriginHeader } -TimeoutSec 15 -UseBasicParsing | Out-Null
  } catch {
    $tamperedStatus = Get-HttpErrorStatus $_
  }
  Assert-True ($tamperedStatus -eq 401) "JWT Bearer alterado es rechazado con HTTP 401"

  # 5. Verificación de persistencia de cookie de sesión GET /api/auth.php?accion=session
  $sessAuthResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=session" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  Assert-True ($sessAuthResp.StatusCode -eq 200) "GET /api/auth.php?accion=session (autenticado) -> 200 OK"
  $sessAuthJson = $sessAuthResp.Content | ConvertFrom-Json
  Assert-True ($sessAuthJson.ok -eq $true -and $sessAuthJson.autenticado -eq $true -and $sessAuthJson.usuario.email -eq 'estudiante@pausasactivas.edu.mx') 'GET /api/auth.php?accion=session -> ok: true, autenticado: true'

  # 6. Perfil y estadísticas del usuario autenticado
  $perfilResp = Invoke-WebRequest -Uri "$BackendUrl/api/usuarios.php?accion=perfil" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  Assert-True ($perfilResp.StatusCode -eq 200) "GET /api/usuarios.php?accion=perfil -> 200 OK"
  $perfilJson = $perfilResp.Content | ConvertFrom-Json
  Assert-True ($perfilJson.ok -eq $true -and $null -ne $perfilJson.estadisticas.calificacion_promedio) "Perfil devuelve ok: true e incluye calificacion_promedio"

  $favResp = Invoke-WebRequest -Uri "$BackendUrl/api/mapa.php?accion=favoritos" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  Assert-True ($favResp.StatusCode -eq 200) "GET /api/mapa.php?accion=favoritos -> 200 OK"
  $favJson = $favResp.Content | ConvertFrom-Json
  Assert-True ($favJson.ok -eq $true) "GET /api/mapa.php?accion=favoritos -> ok: true"

  # 7. Catálogo de rutinas y parques
  $rutinasResp = Invoke-WebRequest -Uri "$BackendUrl/api/rutinas.php" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  Assert-True ($rutinasResp.StatusCode -eq 200) "GET /api/rutinas.php -> 200 OK"
  $rutinasJson = $rutinasResp.Content | ConvertFrom-Json
  Assert-True ($rutinasJson.ok -eq $true -and $rutinasJson.rutinas.Count -ge 1) "Catálogo de rutinas devuelve >= 1 rutina"

  $mapaResp = Invoke-WebRequest -Uri "$BackendUrl/api/mapa.php?accion=parques_cercanos&lat=25.6768&lng=-100.4615&radio=3000" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 35 -UseBasicParsing
  Assert-True ($mapaResp.StatusCode -eq 200) "GET /api/mapa.php?accion=parques_cercanos -> 200 OK"
  $mapaJson = $mapaResp.Content | ConvertFrom-Json
  Assert-True ($mapaJson.ok -eq $true -and $mapaJson.parques.Count -ge 1) "Endpoint de parques Overpass responde ok: true con parques reales ($($mapaJson.parques.Count))"
  if ($mapaJson.parques.Count -gt 1) {
    $ordered = $true
    $pPrev = -1
    foreach ($pq in $mapaJson.parques) {
      if ([int]$pq.distancia_metros -lt $pPrev) { $ordered = $false; break }
      $pPrev = [int]$pq.distancia_metros
    }
    Assert-True $ordered "Resultados OSM ordenados estrictamente de menor a mayor distancia"
  }

  # 7b. Prueba real de ActiBot con Google Gemini AI (POST /api/chat.php)
  $csrfChat = [string]$sessAuthJson.csrf_token
  $chatPayload = @{
    accion          = 'mensaje'
    mensaje         = 'Recomiéndame una pausa activa de 2 minutos para cuello y hombros adaptada a silla de ruedas en Santa Catarina.'
    parque_contexto = 'Santa Catarina, N.L.'
    historial       = @(
      @{ role = 'user'; text = 'Hola ActiBot, trabajo frente a la computadora.' },
      @{ role = 'model'; text = 'Hola, con gusto te ayudo a mejorar tu postura.' }
    )
  } | ConvertTo-Json -Compress -Depth 5
  $chatResp = Invoke-WebRequest -Uri "$BackendUrl/api/chat.php" -Method Post -WebSession $apiSession `
    -Headers @{ 'X-CSRF-Token' = $csrfChat; 'Origin' = $OriginHeader } `
    -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes($chatPayload)) -TimeoutSec 40 -UseBasicParsing
  Assert-True ($chatResp.StatusCode -eq 200) "POST /api/chat.php -> 200 OK"
  $chatJson = $chatResp.Content | ConvertFrom-Json
  Assert-True ($chatJson.ok -eq $true -and $chatJson.origen -eq 'gemini_api' -and -not [string]::IsNullOrWhiteSpace($chatJson.respuesta)) "ActiBot responde con Google Gemini AI (origen=$($chatJson.origen), modelo=$($chatJson.modelo))"

  # 8. Cierre de sesión POST /api/auth.php?accion=logout
  $csrfAuth = [string]$sessAuthJson.csrf_token
  $logoutResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=logout" -Method Post -WebSession $apiSession `
    -Headers @{ 'X-CSRF-Token' = $csrfAuth; 'Origin' = $OriginHeader } `
    -ContentType 'application/json' -Body '{}' -TimeoutSec 25 -UseBasicParsing
  Assert-True ($logoutResp.StatusCode -eq 200) "POST /api/auth.php?accion=logout -> 200 OK"
  $logoutJson = $logoutResp.Content | ConvertFrom-Json
  Assert-True ($logoutJson.ok -eq $true) "Logout devuelve ok: true"
  $csrfAfterLogout = [string]$logoutJson.csrf_token

  # 8b. Login válido con admin@pausasactivas.edu.mx / admin12345 y limpieza de sesión
  $adminLoginBody = @{ email = 'admin@pausasactivas.edu.mx'; password = 'admin12345' } | ConvertTo-Json -Compress
  $adminResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=login" -Method Post -WebSession $apiSession `
    -Headers @{ 'X-CSRF-Token' = $csrfAfterLogout; 'Origin' = $OriginHeader } `
    -ContentType 'application/json' -Body $adminLoginBody -TimeoutSec 25 -UseBasicParsing
  Assert-True ($adminResp.StatusCode -eq 200) "POST /api/auth.php?accion=login (admin@pausasactivas.edu.mx) -> 200 OK"
  $adminJson = $adminResp.Content | ConvertFrom-Json
  Assert-True ($adminJson.ok -eq $true -and $adminJson.usuario.rol -eq 'admin' -and $adminJson.usuario.email -eq 'admin@pausasactivas.edu.mx') "Login admin devuelve ok: true y rol=admin"

  $adminSessResp = Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=session" -WebSession $apiSession -Headers @{ Origin = $OriginHeader } -TimeoutSec 25 -UseBasicParsing
  Assert-True ($adminSessResp.StatusCode -eq 200) "GET /api/auth.php?accion=session (admin) -> 200 OK"
  $adminSessJson = $adminSessResp.Content | ConvertFrom-Json
  Assert-True ($adminSessJson.ok -eq $true -and $adminSessJson.autenticado -eq $true) 'GET /api/auth.php?accion=session (admin) -> ok: true, autenticado: true'

  Invoke-WebRequest -Uri "$BackendUrl/api/auth.php?accion=logout" -Method Post -WebSession $apiSession `
    -Headers @{ 'X-CSRF-Token' = [string]$adminSessJson.csrf_token; 'Origin' = $OriginHeader } `
    -ContentType 'application/json' -Body '{}' -TimeoutSec 25 -UseBasicParsing | Out-Null
} else {
  Write-Host "[SKIP] Pruebas de login demo omitidas por -SkipAuthDemo." -ForegroundColor Yellow
}

# 9. Validación de vistas Frontend y recursos PWA
$frontendPaths = @(
  '/',
  '/index.html',
  '/login.html',
  '/parques.html',
  '/rutinas.html',
  '/chatbot.html',
  '/ajustes.html',
  '/legal.html',
  '/404.html',
  '/sw.js',
  '/public/manifest.webmanifest',
  '/public/logo.svg',
  '/public/favicon.svg',
  '/public/topo-pattern.svg',
  '/public/icon-192.png',
  '/public/icon-512.png',
  '/public/og-cover.png',
  '/assets/css/variables.css',
  '/assets/css/app.css',
  '/assets/css/accessibility.css',
  '/assets/fonts/fraunces-latin-700.woff2',
  '/assets/fonts/atkinson-hyperlegible-latin-400.woff2',
  '/assets/fonts/ibm-plex-mono-latin-500.woff2',
  '/assets/js/vendor/lottie_light.min.js',
  '/assets/js/modules/config.js',
  '/assets/js/modules/api-client.js',
  '/assets/js/modules/ui.js',
  '/assets/js/modules/auth.js',
  '/assets/js/modules/layout.js',
  '/assets/js/modules/inicio.js',
  '/assets/js/modules/lottie-ejercicios.js',
  '/assets/js/modules/rutinas.js',
  '/assets/js/modules/parques.js',
  '/assets/js/modules/chatbot.js',
  '/assets/js/modules/ajustes.js',
  '/assets/animations/regla-20-20-20-parpadeo.json',
  '/assets/animations/liberacion-lateral-cuello.json',
  '/assets/animations/circulos-escapulares-apertura.json',
  '/assets/animations/descompresion-munecas.json',
  '/assets/animations/torsion-columna-toracica.json',
  '/assets/animations/coordinacion-cruzada-enfoque.json',
  '/assets/animations/respiracion-diafragmatica.json',
  '/assets/animations/marcha-activacion-parque.json',
  '/assets/animations/apertura-pectoral-aire-libre.json',
  '/assets/animations/movilidad-cadera-tobillos.json',
  '/assets/animations/estiramiento-lateral-cadena.json',
  '/assets/animations/pausa-sensorial-cierre.json',
  '/assets/animations/gato-vaca-columna.json',
  '/assets/animations/empuje-dinamico-banca.json',
  '/assets/animations/respiracion-cuadrada-enfoque.json',
  '/assets/animations/default.json'
)

foreach ($path in $frontendPaths) {
  $resp = Invoke-WebRequest -Uri "$FrontendUrl$path" -TimeoutSec 20 -UseBasicParsing
  Assert-True ($resp.StatusCode -eq 200) "Frontend GET $path -> 200 OK"
  if ($path -eq '/sw.js') {
    $cc = [string]$resp.Headers['Cache-Control']
    Assert-True ($cc -match 'no-cache') "sw.js incluye Cache-Control con no-cache ($cc)"
  }
  if ($path -eq '/public/manifest.webmanifest') {
    $ct = [string]$resp.Headers['Content-Type']
    Assert-True ($ct -match 'manifest\+json') "manifest.webmanifest sirve Content-Type application/manifest+json ($ct)"
  }
}

if ($FrontendUrl -notmatch ':3000') {
  foreach ($cleanRoute in @('/login', '/parques', '/rutinas', '/chatbot', '/ajustes', '/legal')) {
    $cleanResp = Invoke-WebRequest -Uri "$FrontendUrl$cleanRoute" -TimeoutSec 20 -UseBasicParsing
    Assert-True ($cleanResp.StatusCode -eq 200) "Frontend GET $cleanRoute (clean rewrite) -> 200 OK"
  }
}

Write-Host "`n=== TODOS LOS CHECKS DEL SMOKE TEST PASARON EXITOSAMENTE ===" -ForegroundColor Green
