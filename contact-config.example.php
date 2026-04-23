<?php
/**
 * Contact form configuration — KEEP THIS FILE OUTSIDE THE WEB ROOT
 *
 * On the server, place this file ONE level above public_html:
 *   /home/username/contact-config.php   (NOT in public_html/)
 *
 * contact.php loads it via: dirname(__DIR__) . '/contact-config.php'
 *
 * Copy this file, rename to contact-config.php, and fill in real values.
 */

// ── Contact form (contact.php) ──────────────────────────────
$RESEND_API_KEY    = 'your_resend_api_key_here';
$TURNSTILE_SECRET  = 'your_turnstile_secret_key_here';
$CONTACT_EMAIL     = 'info@datyca.com';

// ── Lead magnet (lead-magnet.php) ──────────────────────────
// Brevo API key (generated in the Brevo dashboard → SMTP & API → API Keys).
// Used for both the /contacts upsert and the /smtp/email transactional send.
// Only the API key is a secret — list ID, template ID and consent source are
// non-sensitive configuration and live as constants inside lead-magnet.php.
$BREVO_API_KEY     = 'your_brevo_api_key_here';
