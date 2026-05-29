# EVE Mining Tool

Lokales Webtool zur Auswertung von EVE Online Mining-Ledger-Daten, Marktpreisen und Verkaufsposten.

## Installation

1. Dateien in einen Webserver-Ordner kopieren.
2. `api/config.example.php` zu `api/config.php` kopieren.
3. Optional MariaDB/MySQL-Zugangsdaten in `api/config.php` eintragen.
4. `index.html` im Browser oder über den Webserver öffnen.

Ohne Datenbank nutzt die PHP-API lokalen Datei-Speicher unter `api/data/`.

## Marktpreis-Snapshots

Mit Datenbank kann die API aktuelle ESI Market Orders regelmaessig speichern:

```text
/api/index.php?action=refresh-price-snapshots&token=CRON_TOKEN
```

Der Token wird in `api/config.php` als `cron_token` gesetzt. Standardmaessig werden Domain/Amarr und die 15 Materialien der Verkaufsposten-Seite gespeichert.

Die Verkaufsposten-Empfehlung nutzt gespeicherte Snapshots bevorzugt als aktuellen Station-Best-Sell minus `0,01 ISK`. 5T/20T-Werte bleiben Fallback und Trendhilfe.

## Private Dateien

Diese Dateien gehören nicht ins Repository:

- `api/config.php`
- `api/data/`

## Release 2026-05-15

- Verkaufsposten-Seite mit 15 veredelten Materialien.
- Netto-Berechnung mit Sales Tax und Broker Fee.
- Appweit größere Schriftgrößen für bessere Lesbarkeit.
- Dashboard, Marktpreise, Import und Verkaufsposten überarbeitet.
