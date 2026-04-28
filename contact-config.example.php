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

// ── Brevo (lead-magnet.php + contact.php) ──────────────────
// Brevo API key (generated in the Brevo dashboard → SMTP & API → API Keys).
// Used by:
//   - lead-magnet.php → /contacts upsert + /smtp/email transactional send
//   - contact.php     → /contacts upsert (archive every form submission;
//                       email delivery itself goes via Resend, not Brevo)
// Only the API key is a secret — list IDs, template IDs and consent sources
// are non-sensitive configuration and live as constants inside the PHP files.
$BREVO_API_KEY     = 'your_brevo_api_key_here';
