<?php
/**
 * Datyca Contact Form Handler
 *
 * Security:
 * 1. Rate limiting (session-based, 3 submissions / 5 min)
 * 2. Honeypot field (must be empty)
 * 3. Cloudflare Turnstile verification
 * 4. Input validation & sanitization
 * 5. XSS prevention (htmlspecialchars)
 * 6. CORS origin whitelist
 * 7. POST-only, JSON content-type enforced
 */

// ============================================
// CONFIGURATION — loaded from external config file (outside web root)
// ============================================
$configPath = dirname(__DIR__) . '/contact-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['message' => 'Configurazione server mancante']);
    error_log('contact-config.php not found at: ' . $configPath);
    exit;
}
require $configPath;

// contact-config.php must define:
// $RESEND_API_KEY, $TURNSTILE_SECRET, $CONTACT_EMAIL
$FROM_EMAIL = 'Datyca <noreply@datyca.com>';

$ALLOWED_ORIGINS = [
    'https://datyca.com',
    'https://www.datyca.com',
    'http://localhost:4321',
    'http://localhost:3000',
];

$OGGETTI = [
    'info'            => 'Informazioni generali',
    'collaborazione'  => 'Collaborazione',
    'consulenza'      => 'Consulenza',
    'altro'           => 'Altro',
];

// ============================================
// CORS
// ============================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://datyca.com');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Metodo non consentito']);
    exit;
}

// ============================================
// RATE LIMITING (session-based)
// ============================================
session_start();
$now = time();
$window = 300; // 5 minutes
$maxRequests = 3;

if (!isset($_SESSION['contact_times'])) {
    $_SESSION['contact_times'] = [];
}

// Purge entries older than window
$_SESSION['contact_times'] = array_filter(
    $_SESSION['contact_times'],
    fn($t) => ($now - $t) < $window
);

if (count($_SESSION['contact_times']) >= $maxRequests) {
    http_response_code(429);
    echo json_encode(['message' => 'Troppi invii. Riprova tra qualche minuto.']);
    exit;
}

// ============================================
// PARSE JSON INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['message' => 'Richiesta non valida']);
    exit;
}

// ============================================
// HONEYPOT CHECK
// ============================================
if (!empty($data['website'])) {
    // Bot detected — silent success
    http_response_code(200);
    echo json_encode(['message' => 'Messaggio inviato con successo']);
    exit;
}

// ============================================
// INPUT VALIDATION
// ============================================
$errors = [];

$name = trim($data['nome'] ?? '');
if (mb_strlen($name) < 2) {
    $errors['nome'] = 'Il nome deve avere almeno 2 caratteri';
} elseif (mb_strlen($name) > 100) {
    $errors['nome'] = 'Il nome è troppo lungo';
}

$email = trim($data['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Inserisci un\'email valida';
}

$oggetto = trim($data['oggetto'] ?? '');
if (!array_key_exists($oggetto, $OGGETTI)) {
    $errors['oggetto'] = 'Seleziona un oggetto valido';
}

$message = trim($data['messaggio'] ?? '');
if (mb_strlen($message) < 10) {
    $errors['messaggio'] = 'Il messaggio deve avere almeno 10 caratteri';
} elseif (mb_strlen($message) > 2000) {
    $errors['messaggio'] = 'Il messaggio è troppo lungo (max 2000 caratteri)';
}

$turnstileToken = $data['cf-turnstile-response'] ?? '';
if (empty($turnstileToken)) {
    $errors['turnstile'] = 'Verifica di sicurezza richiesta';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['message' => 'Dati non validi', 'errors' => $errors]);
    exit;
}

// ============================================
// TURNSTILE VERIFICATION
// ============================================
$ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => $TURNSTILE_SECRET,
        'response' => $turnstileToken,
        'remoteip' => $_SERVER['REMOTE_ADDR'],
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$turnstileResult = curl_exec($ch);
curl_close($ch);

$turnstileOutcome = json_decode($turnstileResult, true);
if (!$turnstileOutcome || !$turnstileOutcome['success']) {
    http_response_code(403);
    echo json_encode(['message' => 'Verifica di sicurezza fallita. Ricarica la pagina e riprova.']);
    exit;
}

// ============================================
// SANITIZE FOR EMAIL
// ============================================
$safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeOggetto = htmlspecialchars($OGGETTI[$oggetto], ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

// ============================================
// NOTIFICATION EMAIL (to Datyca)
// ============================================
$notificationHtml = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 20px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#0D0826;padding:32px 40px;">
            <img src="https://datyca.com/images/logo-full_dark.svg" alt="Datyca" width="140" style="display:block;" />
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            <h1 style="margin:0 0 24px;font-size:22px;color:#0D0826;">Nuovo messaggio dal sito</h1>
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
              <tr>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;width:120px;vertical-align:top;">Nome</td>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#0D0826;font-weight:600;">{$safeName}</td>
              </tr>
              <tr>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;vertical-align:top;">Email</td>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#0D0826;">
                  <a href="mailto:{$safeEmail}" style="color:#4545F7;text-decoration:none;">{$safeEmail}</a>
                </td>
              </tr>
              <tr>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;vertical-align:top;">Oggetto</td>
                <td style="padding:12px 0;border-bottom:1px solid #eee;color:#0D0826;font-weight:600;">{$safeOggetto}</td>
              </tr>
            </table>
            <div style="background:#f8f8fa;border-left:4px solid #4545F7;padding:20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 8px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:0.5px;">Messaggio</p>
              <p style="margin:0;color:#0D0826;line-height:1.6;">{$safeMessage}</p>
            </div>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:24px 40px;background:#f8f8fa;border-top:1px solid #eee;">
            <p style="margin:0;font-size:13px;color:#999;">
              Puoi rispondere direttamente a questa email per contattare {$safeName}.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

// ============================================
// AUTO-REPLY EMAIL (to sender)
// ============================================
$autoReplyHtml = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 20px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#0D0826;padding:32px 40px;text-align:center;">
            <img src="https://datyca.com/images/logo-full_dark.svg" alt="Datyca" width="140" style="display:block;margin:0 auto;" />
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            <h1 style="margin:0 0 16px;font-size:22px;color:#0D0826;">Grazie per averci contattato</h1>
            <p style="margin:0 0 24px;color:#444;line-height:1.6;">
              Ciao <strong>{$safeName}</strong>,<br><br>
              abbiamo ricevuto il tuo messaggio e ti risponderemo il prima possibile, generalmente entro 24-48 ore lavorative.
            </p>
            <div style="background:#f8f8fa;border-radius:6px;padding:20px;margin-bottom:24px;">
              <p style="margin:0 0 8px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:0.5px;">Riepilogo</p>
              <p style="margin:0 0 4px;color:#0D0826;"><strong>Oggetto:</strong> {$safeOggetto}</p>
              <p style="margin:0;color:#0D0826;"><strong>Messaggio:</strong></p>
              <p style="margin:8px 0 0;color:#444;line-height:1.6;">{$safeMessage}</p>
            </div>
            <p style="margin:0;color:#444;line-height:1.6;">
              Nel frattempo, ti invitiamo a visitare il nostro sito per scoprire di più sui nostri servizi.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:24px 40px;background:#0D0826;text-align:center;">
            <p style="margin:0 0 8px;font-size:14px;color:#EBEBEB;">
              DATYCA Legal Design Lab S.r.l.
            </p>
            <p style="margin:0;font-size:12px;color:#888;">
              Palermo &middot; Milano &middot; Roma
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

// ============================================
// SEND EMAILS VIA RESEND
// ============================================
function sendResendEmail($apiKey, $payload) {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($result, true)];
}

// 1) Notification to Datyca
$notifResult = sendResendEmail($RESEND_API_KEY, [
    'from'     => $FROM_EMAIL,
    'to'       => $CONTACT_EMAIL,
    'reply_to' => $email,
    'subject'  => "Nuovo messaggio: {$safeOggetto} — {$safeName}",
    'html'     => $notificationHtml,
]);

if ($notifResult['code'] !== 200) {
    error_log('Resend notification error: ' . json_encode($notifResult['body']));
    http_response_code(500);
    echo json_encode(['message' => 'Errore nell\'invio del messaggio. Riprova più tardi.']);
    exit;
}

// 2) Auto-reply to sender (non-blocking — log error but don't fail)
$replyResult = sendResendEmail($RESEND_API_KEY, [
    'from'    => $FROM_EMAIL,
    'to'      => $email,
    'subject' => 'Abbiamo ricevuto il tuo messaggio — Datyca',
    'html'    => $autoReplyHtml,
]);

if ($replyResult['code'] !== 200) {
    error_log('Resend auto-reply error: ' . json_encode($replyResult['body']));
    // Don't fail — the main notification was sent
}

// ============================================
// RATE LIMIT: record successful submission
// ============================================
$_SESSION['contact_times'][] = $now;

// ============================================
// SUCCESS
// ============================================
http_response_code(200);
echo json_encode(['message' => 'Messaggio inviato con successo']);
