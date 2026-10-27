=== KEHA MCP ===
Version: 0.2.1
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

== Værktøjer (22) ==
Indhold:   site-info, list-content, get-content, create-content, update-content,
           replace-in-content, set-status, trash-content, restore-content,
           list-terms, create-term
Medier:    list-media, upload-media-from-url, upload-media-base64, update-media,
           set-featured-image
Temafiler: list-theme-files, read-theme-file, write-theme-file, edit-theme-file,
           list-theme-backups, restore-theme-backup

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
0.2.1 – chatgpt.com som tilladt redirect-vært; iss-parameter også ved fejl (ChatGPT).
0.2.0 – OAuth 2.1 til claude.ai, 18 nye værktøjer (sider, publicering, termer,
        medier, temafiler), admin-side. Retter tabte backslashes i blokmarkup.
0.1.0 – Fire kladdeværktøjer.
