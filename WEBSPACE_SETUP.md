# Webspace Setup

Ziel: Das Tool laeuft wie `BELA.soluratec.de` auf dem Webspace und nutzt die vorhandenen Datenbank-Zugangsdaten.

## Dateien

Diese Dateien werden hochgeladen:

- `index.html`
- `.htaccess`
- `api/index.php`
- `api/schema.sql`
- `api/config.example.php`
- `eve-market-prices-domain-amarr.tsv`
- `README.md`
- `RELEASE_NOTES.md`

Diese Dateien werden nicht veroeffentlicht oder nicht committed:

- `api/config.php`
- `api/data/`
- `.htpasswd`, wenn moeglich ausserhalb des Webroots ablegen

## Datenbank

Die Anwendung nutzt diese Tabellen:

```sql
eve_mining_state
eve_market_cache
eve_market_order_snapshots
```

Die Tabellen werden von `api/index.php` automatisch angelegt, wenn `pdo_mysql` funktioniert.

## config.php

Auf dem Webspace:

1. `api/config.example.php` nach `api/config.php` kopieren.
2. Bestehende Datenbankdaten eintragen.
3. Optional `api_token` setzen.
4. Optional `cron_token` setzen, wenn geplante Preis-Snapshots per URL ausgelöst werden sollen.

Beispiel:

```php
<?php
return [
    'api_token' => '',
    'cron_token' => '',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'DATENBANKNAME',
        'user' => 'BENUTZER',
        'pass' => 'PASSWORT',
        'charset' => 'utf8mb4',
    ],
];
```

## Geplante Marktpreis-Snapshots

Der Endpunkt sammelt aktuelle ESI Market Orders fuer Domain/Amarr und speichert je Material einen Snapshot in `eve_market_order_snapshots`.

```text
https://SUBDOMAIN/api/index.php?action=refresh-price-snapshots&token=CRON_TOKEN
```

Standardwerte:

- Region: `10000043` Domain
- Station: `60008494` Amarr VIII (Oris) - Emperor Family Academy
- Materialien: die 15 veredelten Materialien aus der Verkaufsposten-Seite

Empfohlene Aufrufzeiten:

```text
06:00, 09:00, 12:00, 15:00, 18:00, 21:00
```

Wenn der Hoster keine Cronjobs anbietet, kann ein externer Webcron genau diese URL aufrufen.

## .htaccess

In `.htaccess` muss diese Zeile angepasst werden:

```apache
AuthUserFile /ABSOLUTER/SERVER/PFAD/.htpasswd
```

Der absolute Serverpfad steht meist im Webspace-Panel oder kann per PHP `__DIR__` ermittelt werden.

## .htpasswd

Die `.htpasswd` sollte idealerweise ausserhalb des oeffentlichen Webroots liegen.

Erzeugen kann man sie im Hosting-Panel oder lokal mit Apache-Tools:

```bash
htpasswd -c .htpasswd benutzername
```

## Funktionstest

Nach Upload pruefen:

```text
https://SUBDOMAIN/api/index.php?action=health
```

Erwartet:

```json
{
  "ok": true,
  "storage": "mysql",
  "mysqlConfigured": true,
  "pdo_mysql": true,
  "curl": true,
  "dbError": null
}
```
