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
// CONFIGURATION — loaded from external config file
// Tries two locations: one level above web root (legacy), then /private/
// inside web root (protected by .htaccess — used on shared hosting where
// FTP root IS the web root and no "parent" directory is accessible).
// ============================================
$configPath = dirname(__DIR__) . '/contact-config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/private/contact-config.php';
}
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['message' => 'Configurazione server mancante']);
    error_log('contact-config.php not found');
    exit;
}
require $configPath;

// contact-config.php must define:
// $RESEND_API_KEY, $TURNSTILE_SECRET, $CONTACT_EMAIL, $BREVO_API_KEY
$FROM_EMAIL = 'Datyca <noreply@datyca.com>';
$BASE_URL = 'https://datyca.com';

// Defensive: surface a clear error when the config file exists but
// $BREVO_API_KEY is missing/empty. Same guard as lead-magnet.php so an
// incomplete config fails loudly instead of producing a cryptic Brevo 401.
if (!isset($BREVO_API_KEY) || !is_string($BREVO_API_KEY) || trim($BREVO_API_KEY) === '') {
    http_response_code(500);
    echo json_encode(['message' => 'Chiave Brevo non configurata sul server. Aggiungi $BREVO_API_KEY a contact-config.php.']);
    error_log('contact.php: $BREVO_API_KEY is missing or empty in ' . $configPath);
    exit;
}

// Non-secret Brevo configuration. Kept inline because rotating list IDs is a
// code change tied to segmentation logic, not an ops action.
//   - CONTACTS_LIST_ID:   "Contatti - form sito" — every privacy-accepting
//                         submission lands here, regardless of marketing consent.
//                         Used for archive/analytics ONLY, never for marketing
//                         sends (the marketing consent may be missing).
//   - NEWSLETTER_LIST_ID: "Newsletter Datyca" — marketing list, same one used
//                         by lead-magnet.php. Added only when marketing is ticked.
//   - CONSENT_SOURCE:     legal audit-trail label (GDPR art. 7) — versioned
//                         so changes to the checkbox copy create a v2 etc.
//   - LAST_LEAD_SOURCE / FROM_*: marketing analytics. LAST_LEAD_SOURCE holds
//                         the last touchpoint (overwritten each submission);
//                         FROM_CONTACT_FORM_FOOTER is a boolean flag that, once
//                         set true, persists forever — Brevo's PATCH semantics
//                         leave attributes not in the payload untouched, so
//                         the same contact accumulates flags from each form
//                         it ever passed through (FROM_LM_HOME, FROM_LM_RISORSE
//                         from lead-magnet.php, etc.) without any extra GET.
const BREVO_CONTACTS_LIST_ID   = 6;
const BREVO_NEWSLETTER_LIST_ID = 3;
const BREVO_CONSENT_SOURCE     = 'contact_form_v1';
const BREVO_LAST_LEAD_SOURCE   = 'contact_form_footer';
const BREVO_FROM_FLAG_NAME     = 'FROM_CONTACT_FORM_FOOTER';

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

// Strict boolean check: the frontend sends real booleans (see Contatti.astro
// payload override). An unchecked / missing checkbox is always false.
$consentPrivacy   = !empty($data['consent_privacy'])   && $data['consent_privacy']   === true;
$consentMarketing = !empty($data['consent_marketing']) && $data['consent_marketing'] === true;

if (!$consentPrivacy) {
    $errors['consent_privacy'] = 'Il consenso al trattamento dei dati è obbligatorio';
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
<html lang="it" dir="ltr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>Nuovo messaggio dal sito</title>

  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <style>
    table { border-collapse: collapse; }
    td { font-family: Arial, sans-serif; }
  </style>
  <![endif]-->

  <style>
    :root {
      color-scheme: light dark;
      supported-color-schemes: light dark;
    }

    @font-face {
      font-family: 'Optik';
      font-style: normal;
      font-weight: 400;
      mso-font-alt: 'Arial';
      src: url('{$BASE_URL}/fonts/Optik-Regular.woff2') format('woff2');
    }
    @font-face {
      font-family: 'Spectral';
      font-style: normal;
      font-weight: 400;
      mso-font-alt: 'Georgia';
      src: url('{$BASE_URL}/fonts/Spectralregular.woff2') format('woff2');
    }
    @font-face {
      font-family: 'Spectral';
      font-style: normal;
      font-weight: 500;
      mso-font-alt: 'Georgia';
      src: url('{$BASE_URL}/fonts/Spectral500.woff2') format('woff2');
    }

    body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }

    @media (prefers-color-scheme: dark) {
      .email-bg { background-color: #F5F4F2 !important; }
      .logo-cell { background-color: #F5F4F2 !important; }
      .content-cell { background-color: #F5F4F2 !important; }
      .footer-cell { background-color: #F5F4F2 !important; }
      .heading-coral { color: #F21763 !important; }
      .body-text { color: #0D0826 !important; }
      .label-text { color: #666666 !important; }
      .value-text { color: #1a1a1a !important; }
      .link-indigo { color: #4545F7 !important; }
      .border-indigo { border-color: #4545F7 !important; }
      .msg-border { border-left-color: #4545F7 !important; }
      .footer-text { color: #4545F7 !important; }
    }

    [data-ogsb] .email-bg { background-color: #F5F4F2 !important; }
    [data-ogsb] .logo-cell { background-color: #F5F4F2 !important; }
    [data-ogsb] .content-cell { background-color: #F5F4F2 !important; }
    [data-ogsb] .footer-cell { background-color: #F5F4F2 !important; }
    [data-ogsc] .heading-coral { color: #F21763 !important; }
    [data-ogsc] .body-text { color: #0D0826 !important; }
    [data-ogsc] .label-text { color: #666666 !important; }
    [data-ogsc] .value-text { color: #1a1a1a !important; }
    [data-ogsc] .link-indigo { color: #4545F7 !important; }
    [data-ogsc] .border-indigo { border-color: #4545F7 !important; }
    [data-ogsc] .footer-text { color: #4545F7 !important; }

    @media only screen and (max-width: 620px) {
      .email-container { width: 100% !important; }
      .content-cell { padding: 32px 24px !important; }
      .logo-cell { padding: 28px 24px !important; }
      .footer-cell { padding: 20px 24px !important; }
      .heading-coral { font-size: 36px !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F5F4F2;" bgcolor="#F5F4F2">
  <div role="article" aria-roledescription="email" aria-label="Nuovo messaggio dal sito" lang="it" dir="ltr" style="font-size: medium; font-size: max(16px, 1rem);">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #F5F4F2;" bgcolor="#F5F4F2" class="email-bg">
      <tr>
        <td align="center" style="padding: 40px 16px;">

          <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="email-container" style="max-width: 600px; width: 100%; background-color: #F5F4F2;" bgcolor="#F5F4F2">

            <!-- ═══ LOGO ═══ -->
            <tr>
              <td class="logo-cell" style="padding: 32px 40px 28px; background-color: #F5F4F2; border: 2px solid #4545F7; border-bottom: none;" bgcolor="#F5F4F2">
                <a href="{$BASE_URL}" target="_blank" style="display: inline-block;"><img src="{$BASE_URL}/images/logo-full_dark.png" alt="Datyca Legal Design Lab" width="200" style="display: block; width: 200px; max-width: 100%; height: auto;" /></a>
              </td>
            </tr>

            <!-- ═══ CONTENT ═══ -->
            <tr>
              <td class="content-cell border-indigo" style="padding: 40px; background-color: #F5F4F2; border-left: 2px solid #4545F7; border-right: 2px solid #4545F7; border-top: 2px solid #4545F7;" bgcolor="#F5F4F2">

                <h1 class="heading-coral" style="margin: 0 0 28px; font-family: 'Spectral', Georgia, 'Palatino Linotype', serif; font-weight: 500; font-size: 48px; line-height: 1; letter-spacing: -0.03em; color: #F21763;">
                  Nuovo messaggio
                </h1>

                <!-- Data table -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 28px;">
                  <tr>
                    <td class="label-text" style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #666666; width: 100px; vertical-align: top;">Nome</td>
                    <td class="value-text" style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 18px; color: #1a1a1a; font-weight: 600;">{$safeName}</td>
                  </tr>
                  <tr>
                    <td class="label-text" style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #666666; vertical-align: top;">Email</td>
                    <td style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 18px;">
                      <a href="mailto:{$safeEmail}" class="link-indigo" style="color: #4545F7; text-decoration: none;">{$safeEmail}</a>
                    </td>
                  </tr>
                  <tr>
                    <td class="label-text" style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #666666; vertical-align: top;">Oggetto</td>
                    <td class="value-text" style="padding: 14px 0; border-bottom: 1px solid #ddd; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 18px; color: #1a1a1a; font-weight: 600;">{$safeOggetto}</td>
                  </tr>
                </table>

                <!-- Message block -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td class="msg-border" style="border-left: 4px solid #4545F7; padding: 20px;">
                      <p class="label-text" style="margin: 0 0 8px; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #666666; text-transform: uppercase; letter-spacing: 0.5px;">Messaggio</p>
                      <p class="body-text" style="margin: 0; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 18px; line-height: 1.6; color: #1a1a1a;">{$safeMessage}</p>
                    </td>
                  </tr>
                </table>

              </td>
            </tr>

            <!-- ═══ FOOTER ═══ -->
            <tr>
              <td class="footer-cell border-indigo" style="padding: 24px 40px 28px; background-color: #F5F4F2; border: 2px solid #4545F7; border-top: 2px solid #4545F7; text-align: center;" bgcolor="#F5F4F2">
                <p class="footer-text" style="margin: 0; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #4545F7;">
                  Rispondi a questa email per contattare {$safeName}
                </p>
              </td>
            </tr>

          </table>

        </td>
      </tr>
    </table>

  </div>
</body>
</html>
HTML;

// ============================================
// AUTO-REPLY EMAIL (to sender)
// ============================================
$autoReplyHtml = <<<HTML
<!DOCTYPE html>
<html lang="it" dir="ltr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>DATYCA — Messaggio ricevuto</title>

  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <style>
    table { border-collapse: collapse; }
    td { font-family: Arial, sans-serif; }
  </style>
  <![endif]-->

  <style>
    :root {
      color-scheme: light dark;
      supported-color-schemes: light dark;
    }

    @font-face {
      font-family: 'Optik';
      font-style: normal;
      font-weight: 400;
      mso-font-alt: 'Arial';
      src: url('{$BASE_URL}/fonts/Optik-Regular.woff2') format('woff2');
    }
    @font-face {
      font-family: 'Spectral';
      font-style: normal;
      font-weight: 400;
      mso-font-alt: 'Georgia';
      src: url('{$BASE_URL}/fonts/Spectralregular.woff2') format('woff2');
    }
    @font-face {
      font-family: 'Spectral';
      font-style: italic;
      font-weight: 400;
      mso-font-alt: 'Georgia';
      src: url('{$BASE_URL}/fonts/Spectralitalic.woff2') format('woff2');
    }
    @font-face {
      font-family: 'Spectral';
      font-style: normal;
      font-weight: 500;
      mso-font-alt: 'Georgia';
      src: url('{$BASE_URL}/fonts/Spectral500.woff2') format('woff2');
    }

    body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }

    @media (prefers-color-scheme: dark) {
      .email-bg { background-color: #F5F4F2 !important; }
      .logo-cell { background-color: #F5F4F2 !important; }
      .content-cell { background-color: #F5F4F2 !important; }
      .footer-cell { background-color: #F5F4F2 !important; }
      .heading-coral { color: #F21763 !important; }
      .body-text { color: #0D0826 !important; }
      .border-indigo { border-color: #4545F7 !important; }
      .footer-text { color: #4545F7 !important; }
      .footer-italic { color: #4545F7 !important; }
    }

    [data-ogsb] .email-bg { background-color: #F5F4F2 !important; }
    [data-ogsb] .logo-cell { background-color: #F5F4F2 !important; }
    [data-ogsb] .content-cell { background-color: #F5F4F2 !important; }
    [data-ogsb] .footer-cell { background-color: #F5F4F2 !important; }
    [data-ogsc] .heading-coral { color: #F21763 !important; }
    [data-ogsc] .body-text { color: #0D0826 !important; }
    [data-ogsc] .border-indigo { border-color: #4545F7 !important; }
    [data-ogsc] .footer-text { color: #4545F7 !important; }
    [data-ogsc] .footer-italic { color: #4545F7 !important; }

    @media only screen and (max-width: 620px) {
      .email-container { width: 100% !important; }
      .content-cell { padding: 32px 24px !important; }
      .logo-cell { padding: 28px 24px !important; }
      .footer-cell { padding: 20px 24px !important; }
      .heading-coral { font-size: 36px !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F5F4F2;" bgcolor="#F5F4F2">
  <div role="article" aria-roledescription="email" aria-label="Messaggio ricevuto" lang="it" dir="ltr" style="font-size: medium; font-size: max(16px, 1rem);">

    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #F5F4F2;" bgcolor="#F5F4F2" class="email-bg">
      <tr>
        <td align="center" style="padding: 40px 16px;">

          <!-- Email container (600px) -->
          <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="email-container" style="max-width: 600px; width: 100%; background-color: #F5F4F2;" bgcolor="#F5F4F2">

            <!-- ═══ LOGO SECTION ═══ -->
            <tr>
              <td class="logo-cell" style="padding: 32px 40px 28px; background-color: #F5F4F2; border: 2px solid #4545F7; border-bottom: none;" bgcolor="#F5F4F2">
                <a href="{$BASE_URL}" target="_blank" style="display: inline-block;"><img src="{$BASE_URL}/images/logo-full_dark.png" alt="Datyca Legal Design Lab" width="200" style="display: block; width: 200px; max-width: 100%; height: auto;" /></a>
              </td>
            </tr>

            <!-- ═══ CONTENT SECTION ═══ -->
            <tr>
              <td class="content-cell border-indigo" style="padding: 40px; background-color: #F5F4F2; border-left: 2px solid #4545F7; border-right: 2px solid #4545F7; border-top: 2px solid #4545F7;" bgcolor="#F5F4F2">

                <!-- Heading -->
                <h1 class="heading-coral" style="margin: 0 0 24px; font-family: 'Spectral', Georgia, 'Palatino Linotype', serif; font-weight: 500; font-size: 48px; line-height: 1; letter-spacing: -0.03em; color: #F21763;">
                  Grazie per averci contattato!
                </h1>

                <!-- Body text -->
                <p class="body-text" style="margin: 0; font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-weight: 400; font-size: 18px; line-height: 23px; letter-spacing: 0.01em; color: #0D0826;">
                  Abbiamo ricevuto il tuo messaggio.<br>
                  Un nostro professionista ti ricontatter&agrave; a breve per comprendere le tue esigenze e individuare insieme il percorso pi&ugrave; adatto.
                </p>

              </td>
            </tr>

            <!-- ═══ FOOTER SECTION ═══ -->
            <tr>
              <td class="footer-cell border-indigo" style="padding: 24px 40px 28px; background-color: #F5F4F2; border: 2px solid #4545F7; border-top: 2px solid #4545F7; text-align: center;" bgcolor="#F5F4F2">

                <p style="margin: 0; font-size: 15px; line-height: 23px; letter-spacing: 0.08em;">
                  <span class="footer-text" style="font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-weight: 500; color: #4545F7;">Empowering trust, </span><span class="footer-italic" style="font-family: 'Optik', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; font-style: italic; font-weight: 500; color: #4545F7;">creating opportunities</span>
                </p>

              </td>
            </tr>

          </table>
          <!-- /Email container -->

        </td>
      </tr>
    </table>
    <!-- /Outer wrapper -->

  </div>
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

// ============================================
// BREVO: helper (mirrors lead-magnet.php)
// ============================================
function brevoRequest($apiKey, $endpoint, $payload) {
    $ch = curl_init('https://api.brevo.com/v3' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'content-type: application/json',
            'accept: application/json',
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

// ============================================
// BREVO: upsert contact (best-effort — never blocks the user-facing success)
// ============================================
// Runs only after the Resend notification succeeded, so we never write a
// contact for a submission the user wasn't told was received. The call is
// best-effort: any failure is logged but does NOT change the response — the
// message is already in info@datyca.com's inbox, that's the only thing the
// user actually depends on. Reconstruction from logs / inbox is possible if
// Brevo is down.
//
// List membership rules:
//   - listIds[0] = CONTACTS_LIST_ID  (always — privacy is mandatory and we
//                                     archive every form submission here for
//                                     internal analytics, not marketing)
//   - + NEWSLETTER_LIST_ID           (only if marketing checkbox was ticked)
$nowIso = gmdate('Y-m-d\TH:i:s\Z');
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

$brevoListIds = [BREVO_CONTACTS_LIST_ID];
if ($consentMarketing) {
    $brevoListIds[] = BREVO_NEWSLETTER_LIST_ID;
}

$brevoAttributes = [
    'NOME'                   => $name,
    'CONSENT_PRIVACY_AT'     => $nowIso,
    'CONSENT_IP'             => $remoteIp,
    'CONSENT_SOURCE'         => BREVO_CONSENT_SOURCE,
    'LAST_LEAD_SOURCE'       => BREVO_LAST_LEAD_SOURCE,
    BREVO_FROM_FLAG_NAME     => true,
];
if ($consentMarketing) {
    $brevoAttributes['CONSENT_MARKETING_AT'] = $nowIso;
}

$brevoResult = brevoRequest($BREVO_API_KEY, '/contacts', [
    'email'         => $email,
    'attributes'    => $brevoAttributes,
    'listIds'       => $brevoListIds,
    'updateEnabled' => true,
]);
$brevoCode = $brevoResult['code'];
if ($brevoCode !== 200 && $brevoCode !== 201 && $brevoCode !== 204) {
    error_log('contact.php Brevo /contacts failed (' . $brevoCode . ') for ' . $email . ': ' . json_encode($brevoResult['body']));
}

// 2) Auto-reply to sender (non-blocking — log error but don't fail)
$replyResult = sendResendEmail($RESEND_API_KEY, [
    'from'    => $FROM_EMAIL,
    'to'      => $email,
    'subject' => 'Abbiamo ricevuto il tuo messaggio — DATYCA',
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
