<?php
/**
 * Datyca Lead Magnet Handler (AI Governance 10 Step Guide)
 *
 * Security:
 *   1. Rate limiting (session-based, 5 submissions / 10 min)
 *   2. Honeypot field (must be empty)
 *   3. Cloudflare Turnstile verification
 *   4. Input validation
 *   5. CORS origin whitelist
 *   6. POST-only, JSON content-type enforced
 *
 * Delivery: two Brevo API calls — upsert contact + send transactional email (template #1)
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

// contact-config.php must define secrets only:
//   $TURNSTILE_SECRET, $BREVO_API_KEY

// Defensive: surface a clear error when the config file exists but
// $BREVO_API_KEY hasn't been added to it (undefined, null, empty string).
// Without this guard we'd pass an empty key to Brevo and get a cryptic 401.
if (!isset($BREVO_API_KEY) || !is_string($BREVO_API_KEY) || trim($BREVO_API_KEY) === '') {
    http_response_code(500);
    echo json_encode(['message' => 'Chiave Brevo non configurata sul server. Aggiungi $BREVO_API_KEY a contact-config.php.']);
    error_log('lead-magnet.php: $BREVO_API_KEY is missing or empty in ' . $configPath);
    exit;
}

// Non-secret Brevo configuration (safe to version-control).
// Kept inline here because rotating list/template IDs is a code change
// (the template copy, segmentation logic, etc. are tied to these values),
// not an ops action to be done on the production server without a deploy.
const BREVO_LIST_ID        = 3;                          // "Newsletter Datyca"
const BREVO_TEMPLATE_ID    = 1;                          // "Consegna Guida AI Governance 10 Step"
const BREVO_CONSENT_SOURCE = 'homepage_leadmagnet_v1';   // written to CONSENT_SOURCE attribute

$ALLOWED_ORIGINS = [
    'https://datyca.com',
    'https://www.datyca.com',
    'http://localhost:4321',
    'http://localhost:3000',
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
// RATE LIMITING (session-based, 5 / 10 min)
// ============================================
session_start();
$now = time();
$window = 600; // 10 minutes
$maxRequests = 5;

if (!isset($_SESSION['leadmagnet_times'])) {
    $_SESSION['leadmagnet_times'] = [];
}

$_SESSION['leadmagnet_times'] = array_filter(
    $_SESSION['leadmagnet_times'],
    fn($t) => ($now - $t) < $window
);

if (count($_SESSION['leadmagnet_times']) >= $maxRequests) {
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
// HONEYPOT
// ============================================
if (!empty($data['website'])) {
    // Silent success — don't let bots know they've been flagged
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Guida inviata con successo']);
    exit;
}

// ============================================
// VALIDATION
// ============================================
$errors = [];

$email = trim($data['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Inserisci un\'email valida';
}

$nome = trim($data['nome'] ?? '');
if (mb_strlen($nome) > 100) {
    $errors['nome'] = 'Il nome è troppo lungo';
}

$ruolo = trim($data['ruolo'] ?? '');
if (mb_strlen($ruolo) > 150) {
    $errors['ruolo'] = 'Il ruolo è troppo lungo';
}

$consentPrivacy   = !empty($data['consent_privacy']) && $data['consent_privacy'] === true;
$consentMarketing = !empty($data['consent_marketing']) && $data['consent_marketing'] === true;

if (!$consentPrivacy) {
    $errors['consent_privacy'] = 'Il consenso al trattamento dei dati è obbligatorio';
}

$turnstileToken = $data['cf-turnstile-response'] ?? '';
if (empty($turnstileToken)) {
    $errors['turnstile'] = 'Verifica di sicurezza richiesta';
}

// Source page (which CTA opened the modal). Whitelisted to prevent attribute
// pollution if someone forges the request — unknown values silently fall back
// to 'home' so legacy clients without the field keep working. Keep in sync
// with ALLOWED_SOURCES in LeadMagnetModal.astro and the pathname mapping in
// PromoCards.astro (getLeadMagnetSource).
//
// Each source also maps to a boolean Brevo attribute (FROM_LM_*) that is
// written = true on every submission and accumulates over time: Brevo's PATCH
// semantics leave attributes not in the payload untouched, so a contact that
// once downloaded the home guide and later the risorse guide ends up with
// BOTH flags = true without any extra read. This gives historical "passed
// through this source at least once" segmentation in the dashboard, while
// LAST_LEAD_SOURCE separately holds the most recent touchpoint.
$sourceToFlagAttribute = [
    'home'                => 'FROM_LM_HOME',
    'risorse'             => 'FROM_LM_RISORSE',
    'impact-ai-learn-hub' => 'FROM_LM_AI_LEARN_HUB',
    'impact-per-la-pa'    => 'FROM_LM_PER_LA_PA',
    'impact-taig-lab'     => 'FROM_LM_TAIG_LAB',
];
$source = $data['source'] ?? 'home';
$allowedSources = array_keys($sourceToFlagAttribute);
if (!in_array($source, $allowedSources, true)) {
    $source = 'home';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['message' => 'Dati non validi', 'errors' => $errors]);
    exit;
}

// ============================================
// TURNSTILE VERIFY
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
if (!$turnstileOutcome || empty($turnstileOutcome['success'])) {
    http_response_code(403);
    echo json_encode(['message' => 'Verifica di sicurezza fallita. Ricarica la pagina e riprova.']);
    exit;
}

// ============================================
// BREVO: helper
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

// ============================================
// BREVO: upsert contact
// ============================================
$nowIso = gmdate('Y-m-d\TH:i:s\Z');
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

$attributes = [
    'CONSENT_PRIVACY_AT' => $nowIso,
    'CONSENT_IP'         => $remoteIp,
    'CONSENT_SOURCE'     => BREVO_CONSENT_SOURCE,
    // Last touchpoint (overwritten each submission) — pairs with the
    // boolean FROM_LM_* flag below, which preserves the full history.
    'LAST_LEAD_SOURCE'           => $source,
    $sourceToFlagAttribute[$source] => true,
];
// Preserve existing NOME / JOB_TITLE if the user re-submits without filling
// the optional fields. Sending an empty string would wipe what they gave us
// on a previous submission. Omitting the key entirely leaves the stored
// attribute untouched (Brevo does a partial update with updateEnabled: true).
if ($nome !== '') {
    $attributes['NOME'] = $nome;
}
if ($ruolo !== '') {
    $attributes['JOB_TITLE'] = $ruolo;
}
if ($consentMarketing) {
    $attributes['CONSENT_MARKETING_AT'] = $nowIso;
}

$contactPayload = [
    'email'         => $email,
    'attributes'    => $attributes,
    'updateEnabled' => true,
];
if ($consentMarketing) {
    $contactPayload['listIds'] = [BREVO_LIST_ID];
}

$contactResult = brevoRequest($BREVO_API_KEY, '/contacts', $contactPayload);
$contactCode = $contactResult['code'];

if ($contactCode === 401) {
    error_log('Brevo /contacts auth failure: ' . json_encode($contactResult['body']));
    http_response_code(500);
    echo json_encode(['message' => 'Errore di configurazione del servizio. Riprova più tardi.']);
    exit;
}
if ($contactCode === 402) {
    error_log('Brevo /contacts quota exceeded: ' . json_encode($contactResult['body']));
    http_response_code(503);
    echo json_encode(['message' => 'Servizio temporaneamente non disponibile. Riprova più tardi.']);
    exit;
}
if ($contactCode !== 200 && $contactCode !== 201 && $contactCode !== 204) {
    // Log but do not block email delivery — the contact can be reconstructed from logs
    error_log('Brevo /contacts unexpected response (' . $contactCode . '): ' . json_encode($contactResult['body']));
}

// ============================================
// BREVO: unblock on new explicit request
// ============================================
// A fresh form submission is a new specific consent (GDPR art. 6.1.b,
// execution of user's request) and overrides any stale unsubscribe or
// bounce block on the transactional blocklist. Without this, an address
// that ever clicked "unsubscribe" on a past delivery would be silently
// blocked forever even though the user just explicitly re-requested
// the guide — confusing UX, and not legally required.
//
// DELETE /v3/smtp/blockedContacts/{email}
//   204 → was blocked, now removed
//   404 → wasn't blocked (no-op, good)
//   other → log, proceed anyway (the send may still succeed)
$unblockCh = curl_init('https://api.brevo.com/v3/smtp/blockedContacts/' . urlencode($email));
curl_setopt_array($unblockCh, [
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_HTTPHEADER     => [
        'api-key: ' . $BREVO_API_KEY,
        'accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
curl_exec($unblockCh);
$unblockCode = curl_getinfo($unblockCh, CURLINFO_HTTP_CODE);
curl_close($unblockCh);
if ($unblockCode !== 204 && $unblockCode !== 404 && $unblockCode !== 200) {
    error_log('lead-magnet.php: unblock probe http ' . $unblockCode . ' for ' . $email);
}

// ============================================
// BREVO: resolve display name for this send
// ============================================
// Personalization ($displayName → both envelope "name" and template
// {{ params.NOME }}). Prefer the value submitted now; otherwise fall back
// to whatever is stored on the contact from a previous submission, so a
// user who introduced themselves last time still sees their name in the
// subject/body today.
$displayName = $nome;
if ($displayName === '') {
    $getCh = curl_init('https://api.brevo.com/v3/contacts/' . urlencode($email));
    curl_setopt_array($getCh, [
        CURLOPT_HTTPHEADER     => ['api-key: ' . $BREVO_API_KEY, 'accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $getResp = curl_exec($getCh);
    $getCode = curl_getinfo($getCh, CURLINFO_HTTP_CODE);
    curl_close($getCh);
    if ($getCode === 200) {
        $parsed = json_decode($getResp, true);
        $storedName = isset($parsed['attributes']['NOME']) ? trim((string) $parsed['attributes']['NOME']) : '';
        if ($storedName !== '') {
            $displayName = $storedName;
        }
    }
}

// ============================================
// BREVO: send transactional email
// ============================================
// `tags` lets us slice stats per email type on Brevo's dashboard. The
// `leadmagnet-<source>` variant gives a per-page breakdown on Transactional →
// Statistics without any extra setup. When a newsletter or other template is
// added later, give it a different tag (e.g. 'newsletter_<YYYYMM>') to keep
// reports cleanly separated.
$emailPayload = [
    'templateId' => BREVO_TEMPLATE_ID,
    'to'         => [
        [
            'email' => $email,
            'name'  => $displayName !== '' ? $displayName : $email,
        ],
    ],
    'params'     => [
        'NOME' => $displayName,
    ],
    'tags'       => ['leadmagnet-' . $source],
];

$emailResult = brevoRequest($BREVO_API_KEY, '/smtp/email', $emailPayload);
$emailCode = $emailResult['code'];

if ($emailCode !== 200 && $emailCode !== 201 && $emailCode !== 202) {
    error_log('Brevo /smtp/email error (' . $emailCode . '): ' . json_encode($emailResult['body']));
    http_response_code(500);
    echo json_encode(['message' => 'Errore nell\'invio dell\'email. Riprova tra qualche minuto.']);
    exit;
}

// ============================================
// RATE LIMIT: record successful submission
// ============================================
$_SESSION['leadmagnet_times'][] = $now;

// ============================================
// SUCCESS
// ============================================
http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'Guida inviata con successo']);
