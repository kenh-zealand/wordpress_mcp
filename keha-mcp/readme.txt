=== KEHA MCP ===
Version: 0.3.0
Kræver: WordPress 6.9+, PHP 7.4+, pluginet "MCP Adapter"

Egen MCP-server til keha.dk med indbygget OAuth 2.1, så den kan bruges som
custom connector i claude.ai (web, desktop og mobil) – uden WPVibe.

== Endpoint ==
https://keha.dk/wp-json/keha-mcp/mcp

== Forbind fra claude.ai ==
1. Claude → Indstillinger → Connectors → Tilføj custom connector.
2. Navn: keha.dk – URL: https://keha.dk/wp-json/keha-mcp/mcp
3. Lad OAuth Client ID/Secret være tomme. Klik Tilføj og derefter Forbind.
4. Log ind på keha.dk og klik Godkend.

Desktopappen med Application Password virker fortsat på samme endpoint.

== Værktøjer (28) ==
Indhold:   site-info, list-content, get-content, create-content, update-content,
           replace-in-content, set-status, trash-content, restore-content,
           list-terms, create-term
Medier:    list-media, upload-media-from-url, upload-media-base64, update-media,
           set-featured-image
Temafiler: list-theme-files, read-theme-file, write-theme-file, edit-theme-file,
           list-theme-backups, restore-theme-backup
AD-filer: list-ad-files, read-ad-file, write-ad-file, edit-ad-file,
          list-ad-backups, restore-ad-backup

== Filer i /ad/ ==
Kun den eksisterende ad-mappe direkte i WordPress-roden kan tilgås.
Tilladte filtyper: html, css, js, json, txt, md, svg og docx (højst 2 MiB).
Læs filen først, og send dens sha256 som expected_sha256 ved skrivning,
redigering og gendannelse. Brug missing ved oprettelse af en ny fil.
Tekst gemmes som UTF-8; DOCX kan overføres som base64.
Backups gemmes under ../private-backups/keha-mcp-ad uden for WordPress-roden.
Hvis en backup ikke kan gemmes, overskrives originalfilen ikke.
PHP, konfiguration, skjulte filer, symbolske links og hardlinks er udelukket.
Der er ingen funktion til sletning eller oprettelse af undermapper.
Kun administratorer (superadministratorer på multisite) har adgang.
DISALLOW_FILE_EDIT og DISALLOW_FILE_MODS respekteres ved alle AD-skrivninger.
Eksisterende login og .htaccess i /ad/ ændres ikke.

Bevidst udeladt: permanent sletning, plugins, brugere, indstillinger, SQL.

== Sikkerhed ==
- Kun administratorer kan godkende en forbindelse (samtykkeside på keha.dk).
- PKCE (S256) er påkrævet. Tokens gemmes kun som SHA-256-hash.
- Adgangstoken: 1 time. Refresh-token: 60 dage, roteres ved hver brug.
- Dynamisk klientregistrering kun med redirect til tilladte værter
  (standard: claude.ai, claude.com, chatgpt.com og localhost). Kan ændres/slås fra.
- Forbindelser kan tilbagekaldes under Indstillinger → KEHA MCP.
- Temafiler: PHP syntakstjekkes før gem, backup af hver ændring
  (uploads/keha-mcp-backups, beskyttet med .htaccess), og ændringen rulles
  automatisk tilbage, hvis forsiden derefter giver en fatal fejl.
- DISALLOW_FILE_EDIT i wp-config.php respekteres.

== Fejlfinding ==
- "Connection issue" i Claude: åbn
  https://keha.dk/.well-known/oauth-protected-resource/wp-json/keha-mcp/mcp
  og https://keha.dk/.well-known/oauth-authorization-server – begge skal vise JSON.
- 401 trods godkendelse: serveren fjerner måske Authorization-headeren.
  Tilføj øverst i .htaccess:
    RewriteEngine On
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
- Pluginet "JWT Authentication for WP REST API" er aktivt på sitet. KEHA MCP
  ignorerer dets fejl på egne ruter, så de to kan køre side om side.

== Ændringer ==
0.3.0 – Seks filværktøjer afgrænset til /ad/, SHA-256-versionskontrol,
        privat backup, atomisk gem og gendannelse. Samme MCP-endpoint og OAuth.
0.2.1 – chatgpt.com som tilladt redirect-vært; iss-parameter også ved fejl (ChatGPT).
0.2.0 – OAuth 2.1 til claude.ai, 18 nye værktøjer (sider, publicering, termer,
        medier, temafiler), admin-side. Retter tabte backslashes i blokmarkup.
0.1.0 – Fire kladdeværktøjer.
