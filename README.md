# KEHA MCP

WordPress-pluginet bag forbindelsen **keha wp mcp** til [keha.dk](https://keha.dk). Version 0.3.0 eksponerer 28 MCP-værktøjer til indhold, medier, temafiler og afgrænset filadgang til `/ad/` uden WPVibe.

## Version og kilde

Grundlaget er installationspakken `keha-mcp-0.2.1.zip`. Version 0.3.0 tilføjer filværktøjer til Aktivitetsdesigner. OAuth, forbindelser, endpoint og eksisterende værktøjer bevares.

## Installation

1. Brug WordPress 6.9 eller nyere og PHP 7.4 eller nyere.
2. Installer og aktiver pluginet **MCP Adapter**.
3. Kopiér mappen `keha-mcp` til `wp-content/plugins/`, og aktiver **KEHA MCP**.
4. Administrer OAuth-forbindelser under **Indstillinger → KEHA MCP**.

Alternativt kan mappen `keha-mcp` pakkes som ZIP og uploades via WordPress' plugininstallation.

## Forbindelse

MCP-endpoint på keha.dk:

```text
https://keha.dk/wp-json/keha-mcp/mcp
```

Pluginet understøtter OAuth med PKCE og WordPress Application Passwords. Loginoplysninger og tokens skal oprettes og opbevares på den relevante installation; de indgår ikke i repositoryet.

Se [pluginets vejledning](keha-mcp/readme.txt) for opsætning, værktøjer, adgangskontrol og fejlfinding.

## Indhold

- `keha-mcp/keha-mcp.php`: pluginindgang og MCP-serverregistrering.
- `keha-mcp/includes/abilities-content.php`: sider, indlæg, kategorier og tags.
- `keha-mcp/includes/abilities-media.php`: mediebibliotek og uploads.
- `keha-mcp/includes/abilities-theme.php`: læsning, ændring og backup af temafiler.
- `keha-mcp/includes/abilities-ad.php`: filadgang afgrænset til `/ad/`, versionskontrol og private backups.
- `keha-mcp/includes/oauth.php`: OAuth og tokengodkendelse.
- `keha-mcp/includes/admin.php`: administrationsside.
- `keha-mcp/includes/helpers.php`: fælles hjælpefunktioner.

## Licens

Pluginets kildefil angiver **GPL-2.0-or-later**.

## AD-filer og kontrol

Se [AD-vejledningen](docs/ad-files.md) for installation, værktøjer, backupplacering og eksempler.

Kør `php tests/ad-files.php`, `php tests/ad-files.php --disallow-edit` og `php tests/ad-files.php --disallow-mods`. Testene anvender rigtige midlertidige filer og lette WordPress-stubs. De erstatter ikke en kontrol af MCP-endpointet efter installation.

På Windows kan junction-afgrænsningen testes med `node tests/junctions.cjs C:\sti\til\php.exe`. Byg installationspakken med `python build.py`; scriptet kontrollerer hele ZIP-indholdet og skriver en SHA-256-fil i `dist/`.
