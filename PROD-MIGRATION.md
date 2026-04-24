# Deploy a produzione `datyca.com` su Aruba

Hosting: **Aruba Easy Linux** — `ftp.datyca.com`, path FTP `/` (coincide con la web root).
CI/CD: push su `main` → GitHub Actions builda Astro e carica `dist/` via **FTPS** sulla root Aruba.

---

## Flusso di deploy

```
git push (main) ─► GitHub Actions
                    ├─ npm ci
                    ├─ npm run build        (Astro → dist/)
                    └─ FTPS upload dist/    (SamKirkland/FTP-Deploy-Action)
                                            └─ ftp.datyca.com:21, path /
```

Delta upload: SamKirkland salva un file `.ftp-deploy-sync-state.json` sul server e carica solo i file cambiati dai deploy successivi. Il primo deploy è completo (~pochi minuti), i successivi sono molto più veloci.

---

## Setup iniziale (una tantum) — prima del primo push

### 1. PHP version su Aruba → 8.4

Pannello Aruba → Gestione Hosting → **PHP Management** → seleziona **PHP 8.4** → Save.

### 2. GitHub Secrets (3)

Da terminale con `gh` CLI (una volta sola):

```bash
gh secret set FTP_SERVER   --body "ftp.datyca.com"
gh secret set FTP_USERNAME --body "13008380@aruba.it"
gh secret set FTP_PASSWORD              # prompt interattivo: incolla la password
```

Oppure via UI GitHub: Settings → Secrets and variables → Actions → New repository secret.

### 3. `contact-config.php` sul server (upload manuale, una tantum)

Il file contiene secrets (API key Brevo/Resend/Turnstile) e **non è nel repo**. Va caricato a mano una volta sola, nella cartella `/private/` del server Aruba.

a. Prepara il file partendo da `contact-config.example.php` nel repo, compilando:
   - `$RESEND_API_KEY`
   - `$BREVO_API_KEY`
   - `$TURNSTILE_SECRET`
   - `$CONTACT_EMAIL`

b. Via FTP client (Cyberduck / FileZilla) con le credenziali Aruba:
   - Host: `ftp.datyca.com` — Port: `21` — Protocol: **FTPS esplicito (AUTH TLS)**
   - User: `13008380@aruba.it` — Password: quella di login Aruba
   - Naviga in `/` (web root)
   - Carica il file in `/private/contact-config.php`

c. Verifica che `/private/.htaccess` (uploadato dal CI) stia proteggendo la cartella: apri in browser `https://datyca.com/private/contact-config.php` → deve restituire **403 Forbidden**. Se vedi il contenuto o il file ti viene proposto in download, il `.htaccess` non sta funzionando — NON procedere col go-live.

### 4. DNS

Il dominio `datyca.com` è registrato presso Aruba, quindi i record A / AAAA puntano già all'hosting Aruba (IP 89.46.110.76). **Nessuna azione DNS richiesta**.

I record DNS aggiuntivi (TXT di Brevo per DKIM/SPF, eventuali Google Search Console) sono già configurati sul pannello Aruba e non cambiano.

### 5. Verifica assets già presenti su prod

Dopo il primo deploy, verifica che questi URL rispondano 200:

- `https://datyca.com/images/logo-full_dark.png` (usato nelle email)
- `https://datyca.com/images/why-datyca/iso-42001.png`
- `https://datyca.com/images/why-datyca/aigp.png`
- `https://datyca.com/fonts/Optik-Regular.woff2`
- `https://datyca.com/fonts/Optik-Medium.woff2`
- `https://datyca.com/docs/DATYCA_Guida_AI_Governance_10_Step.pdf`
- `https://datyca.com/contact.php` (risponde JSON con errore CORS/405 se GET — è ok, significa che esiste)
- `https://datyca.com/lead-magnet.php` (idem)

---

## Il go-live (quando tutto è pronto)

```bash
git push origin main
```

Questo triggera la GitHub Action. Puoi seguire il deploy su **Actions** tab del repo.
Durata attesa: **~3–5 minuti** al primo deploy, **~30s–1m** ai successivi.

---

## Verifiche post go-live

- [ ] `https://datyca.com` carica in ≤2s
- [ ] Form contatti: invio test con email reale → arrivo email
- [ ] Lead magnet: opt-in → arrivo email Brevo con PDF
- [ ] `https://datyca.com/private/contact-config.php` → **403**
- [ ] DevTools Network: font `.woff2` caricati da `/_astro/` (non 404)
- [ ] Cookie banner iubenda appare al primo visit
- [ ] Mobile iOS: Hero video parte, pin sections funzionano
- [ ] Google Search Console: riverifica proprietà se richiesto

---

## Rollback in caso di regressione

1. **Codice**: `git revert <sha>` sul main → push → CI ridispiega automaticamente la versione corretta.
2. **Disattivazione temporanea sito**: caricare via FTP un `/index.html` di manutenzione nella root. La GitHub Action però al prossimo deploy lo sovrascriverà con Astro.
3. **Rollback DNS** (estremo, riporta su Hostinger): richiede cambio record A presso il DNS Aruba, propagazione 15min–48h. Assicurarsi che Hostinger sia ancora attivo.

---

## Sicurezza — cosa NON committare mai

- `contact-config.php` (vero, con secrets) — solo su server via FTP manuale
- `.env` (locale) — già in `.gitignore`
- Chiavi API in plain text — solo GitHub Secrets o config fuori dal repo

---

## File strutture su produzione

```
/ (web root + FTP root Aruba)
├── index.html                        ← da dist/
├── _astro/                           ← asset hashed (CSS, JS, font, img)
├── images/
├── fonts/
├── docs/
├── animations/
├── video/
├── contact.php                       ← da dist/ (ex public/)
├── lead-magnet.php                   ← da dist/
├── firma-founder.html, firma-team.html
├── favicon.svg
├── private/
│   ├── .htaccess                     ← da dist/ (uploadato dal CI)
│   └── contact-config.php            ← MAI dal CI, solo upload manuale
└── .ftp-deploy-sync-state.json       ← creato da SamKirkland (non toccare)
```

---

## Dismissione Hostinger (quando confermi che Aruba funziona)

Dopo 2–4 settimane di prod Aruba senza problemi:

1. Rimuovi il sito da Hostinger (pannello Hostinger → Hosting → Manage → Delete Website).
2. Disdici il piano Hostinger al prossimo rinnovo.
3. Su GitHub, elimina il branch residuo `deploy` se presente: `git push origin --delete deploy`
