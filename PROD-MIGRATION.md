# Checklist migrazione a produzione `datyca.com`

Task da completare **quando il sito passa dal dominio di staging Hostinger (`darkorchid-falcon-584985.hostingersite.com`) al dominio ufficiale `datyca.com` ospitato su Aruba Hosting**.

## File da aggiornare

### 1. `public/firma-founder.html`
Sostituire **3 occorrenze** di `https://darkorchid-falcon-584985.hostingersite.com` con `https://datyca.com`:
- Linea ~28: href link logo
- Linea ~29: src immagine logo
- Linea ~33: src immagine badge ISO 42001
- Linea ~36: src immagine badge AIGP

### 2. `public/firma-team.html`
Sostituire **2 occorrenze** di `https://darkorchid-falcon-584985.hostingersite.com` con `https://datyca.com`:
- Linea ~10: href link logo
- Linea ~11: src immagine logo
- Linea ~15: src immagine badge ISO 42001

### 3. `public/contact.php` (opzionale)
Linea 35: rimuovere `'https://darkorchid-falcon-584985.hostingersite.com'` dalla whitelist CORS **se non si vuole più permettere submit dal dominio di staging**. Se lo staging resta attivo per test, lasciare com'è.

### 4. Comando di ricerca globale

Per essere sicuri di non perdere occorrenze future:

```bash
grep -rn "darkorchid" --include="*.astro" --include="*.php" --include="*.html" --include="*.ts" --include="*.js" --include="*.json" --include="*.mjs" .
```

## File che NON richiedono modifiche

- `public/email-preview-leadmagnet.html` → già usa `https://datyca.com` (assicurarsi che logo/fonts/PDF siano caricati su prod)
- `public/email-preview.html` → usa path relativi, funziona su qualsiasi dominio
- `public/email-preview-notification.html` → usa path relativi
- Template email dentro `public/contact.php` → usa `$BASE_URL` dinamico (si adatta automaticamente al dominio del server)

## Brevo — verifiche su template

Se in futuro aggiungi/duplichi template su Brevo ispirandoti a quello del lead magnet, verifica che usino `https://datyca.com/...` e non link allo staging.

## Caricamento assets su produzione Aruba

Assicurarsi che sulla nuova hosting Aruba siano presenti:

- `/images/logo-full_dark.png` (usato nelle email)
- `/images/why-datyca/iso-42001.png`
- `/images/why-datyca/aigp.png`
- `/fonts/Optik-Regular.woff2`
- `/fonts/Optik-Medium.woff2`
- `/fonts/Spectralregular.woff2`
- `/fonts/Spectral500.woff2`
- `/fonts/Spectralitalic.woff2`
- `/docs/DATYCA_Guida_AI_Governance_10_Step.pdf`
- `/contact.php` (+ `contact-config.php` fuori web root con chiavi aggiornate)
- `/lead-magnet.php` (+ stesse chiavi in `contact-config.php`)

## Configurazione Aruba (post-migrazione)

1. DNS: i TXT/CNAME Brevo e Google già presenti restano validi (il dominio non cambia, solo il target dei record A).
2. PHP session: verificare che `session_start()` funzioni su Aruba (per rate limiting di contact.php e lead-magnet.php).
3. cURL abilitato (richiesto per Brevo/Resend/Turnstile).
4. Env file `contact-config.php` caricato **fuori** dalla web root di Aruba.
5. Verificare che il PDF della guida sia raggiungibile pubblicamente: `https://datyca.com/docs/DATYCA_Guida_AI_Governance_10_Step.pdf`
