# Come iniziare un nuovo progetto

## 1. Duplica la cartella

Copia `Project_0` e rinomina la copia con il nome del progetto.

## 2. Apri Claude Code nella nuova cartella

```bash
cd ~/Desktop/Code/NomeProgetto
claude
```

## 3. Inizializza il progetto

Digita nel terminale di Claude:

```
/new-project
```

Prende il nome dalla cartella automaticamente. Aggiorna `package.json`, titolo in Layout.astro e index.astro, e inizializza il repo git.

## 4. Sviluppa

Tutto il setup GSAP (ScrollSmoother, init queue, resize coordinator) è già pronto in `Layout.astro`. Comincia a creare componenti.

## Cosa c'è dentro

| File/Cartella | Scopo |
|---|---|
| `CLAUDE.md` | Regole critiche — lette da Claude ad ogni sessione |
| `.claude/rules/gsap-rules.md` | Pattern e gotchas GSAP — caricati automaticamente quando Claude apre `.astro` |
| `.claude/commands/new-project.md` | Il comando `/new-project` |
| `.claude/skills/deploy/` | Istruzioni deploy Hostinger — invocata con `/deploy` o quando serve |
| `.claude/skills/project-setup/` | Pattern setup (fallback GSAP, font, modulepreload) — invocata con `/project-setup` o quando serve |

## Comandi disponibili

| Comando | Quando usarlo |
|---|---|
| `/new-project` | Subito dopo aver duplicato la cartella (usa il nome della cartella) |
| `/deploy` | Quando vuoi configurare o eseguire il deploy |
| `/project-setup` | Quando aggiungi font, modulepreload, safety net, o fallback GSAP |
