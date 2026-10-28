# AD-filværktøjer i KEHA MCP 0.3.0

Opdater det eksisterende **KEHA MCP** via WordPress → Plugins → Tilføj nyt plugin → Upload plugin. Vælg `keha-mcp-0.3.0.zip` og erstat den installerede version. ZIP-filen indeholder hele mappen `keha-mcp/`. De eksisterende OAuth-indstillinger og forbindelser ligger i WordPress og ændres ikke af denne opdatering.

Opdater derefter værktøjslisten i den tilsluttede MCP-klient. Endpointet er fortsat `https://keha.dk/wp-json/keha-mcp/mcp`. Der skal vises 28 værktøjer. De seks nye abilities er også tilgængelige gennem WordPress Abilities REST API med samme administratorrettigheder.

## Værktøjer

| Ability | Brug |
| --- | --- |
| `keha/list-ad-files` | Filnavne, størrelser og SHA-256; `offset` giver næste side. |
| `keha/read-ad-file` | Tekst med linjenumre og SHA-256; valgfrit `start_line`/`end_line`. DOCX returneres som base64. |
| `keha/write-ad-file` | `path`, `content`, `expected_sha256`, valgfrit `encoding` (`utf-8` eller `base64`). |
| `keha/edit-ad-file` | `path`, `old`, `new`, `expected_sha256`; valgfrit `replace_all`. |
| `keha/list-ad-backups` | Backup-id, filsti, tidspunkt, størrelse og SHA-256; valgfrit `path`/`offset`. |
| `keha/restore-ad-backup` | `backup` og den nuværende fils `expected_sha256`. |

Eksempel: Læs `refinement.js`, kopiér dens `sha256`, og kald `edit-ad-file` med den præcise gamle tekst, den ønskede nye tekst og kontrolsummen. Hvis en anden bruger har ændret filen i mellemtiden, afvises ændringen med konflikt. Læs da filen igen. Oprettelse af en ny fil kræver `expected_sha256: "missing"`.

## Afgrænsning og backups

- Roden er den eksisterende `ad/`-mappe direkte i WordPress-roden (`ABSPATH`). Klienten kan ikke ændre den. En installation, hvor WordPress ligger i en særskilt undermappe, kræver en særskilt tilpasning; der gættes ikke på en anden rod.
- Kun `html`, `css`, `js`, `json`, `txt`, `md`, `svg` og `docx` på højst 2 MiB tillades. DOCX kontrolleres for ZIP-signatur; dokumentets interne Word-struktur valideres ikke. JSON og tekst valideres før gem.
- Absolutte stier, traversal, skjulte navne, symlinks, hardlinks og serverkode/konfiguration afvises. Mapper skal allerede findes. Værktøjerne kan ikke slette filer.
- Alle seks værktøjer kræver administrator; på multisite kræves superadministrator. WordPress' `DISALLOW_FILE_EDIT` og `DISALLOW_FILE_MODS` spærrer skrivning.
- Backups ligger i `private-backups/keha-mcp-ad/` ved siden af WordPress-roden, eksempelvis `/var/www/keha.dk/private-backups/keha-mcp-ad/`. Serveren skal have skriveret dér, og placeringen skal være uden for webserverens offentlige dokumentrod. Hver backupfil indeholder originalens byteindhold, filsti og SHA-256. Filrettigheder er 0600, og nyoprettede mapper er 0700.
- Der gemmes backup før hver ændring af en eksisterende fil, også før gendannelse. Backup-id'et returneres i svaret. Ved backupfejl gemmes den nye fil ikke. Backups slettes ikke automatisk.
- En kortvarig fil-lås koordinerer værktøjets skrivninger. Gem sker ved at udskifte en klargjort fil atomisk. SHA-256 kontrolleres igen lige før udskiftningen for at opdage ændringer fra eksempelvis FTP. Dette er ikke en transaktion på tværs af flere filer og låser ikke eksterne FTP-programmer.
- WordPress-caches tømmes efter gem. Ved ændring af JavaScript/CSS skal sidens asset-version stadig opdateres, når det er nødvendigt for browsercachen.

## Kontrol efter installation

1. Bekræft version 0.3.0 og 28 værktøjer under Indstillinger → KEHA MCP.
2. Opdater MCP-klientens værktøjsliste, og kald `list-ad-files` og `read-ad-file` for `refinement.js`.
3. Brug versionskontrolleret redigering til Word-downloadrettelsen, og gem de returnerede backup-id'er. Opdater scriptversionen i `index.html`.
4. Kontrollér begge downloadlinks på den loginbeskyttede `/ad/`-side og sammenlign filen med skabelonen.

Opdateringen giver ikke automatisk WordPress rettigheder til en mappe, operativsystemet beskytter. Hvis den private backupmappe eller `/ad/` ikke er skrivbar for PHP-brugeren, skal det løses i webhotellets filrettigheder.
