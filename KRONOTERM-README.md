# Kronoterm VERSI-X Cloud integration (read-only)

> **Historische Zwischenstands-Dokumentation (v2).** Die gültige technische Beschreibung für den kumulativen Stand v5 steht in [KRONOTERM-TECHNISCHE-DOKUMENTATION.md](KRONOTERM-TECHNISCHE-DOKUMENTATION.md). Insbesondere gelten elektrische Leistung und kumulierte elektrische Energie ausschließlich aus externen Variablen; die älteren Aussagen zur Übernahme elektrischer Cloud-Zähler sind überholt.

## Scope

This extension adds Kronoterm as a third manufacturer to NRGWPHub while retaining
Panasonic Comfort Cloud and Vaillant myVAILLANT, including their data contract.
It uses `https://cloud.kronoterm.com/jsoncgi.php`, directly in PHP.
The existing `WPHUB_GetFunctions()` contract remains at version 1.15.

## Setup (IP-Symcon 9)

1. Install the development branch `feat/kronoterm-complete` as module library.
2. Create a WPHub instance in the actual device location and choose **Kronoterm**.
3. Enter the Kronoterm Cloud credentials, apply changes, and use **Test Kronoterm Cloud**.
4. Review the controller inventory (loops 1–4, DHW, pool, source visibility).
5. Choose the physical buffer layout **manually**, unless explicit reliable
   installation flags are returned; otherwise `auto` remains unknown.
6. For each buffer sensor, choose **disabled**, **Cloud path**, or an **external
   Symcon variable**. The visible Cloud candidate paths are NOT automatically
   assigned to specific physical probe locations.
7. Configure external `PowerID` and `EnergyID` sources via the existing
   WPHub external meter inputs if desired; each can be independent.
8. Enable the instance after a successful connection test. Assign logging only
   with WPHub's explicit archiving action.

For a system with an 800-litre heating buffer and no cooling buffer, select
**Heating buffer only**. Link upper/lower UVR610 measurements independently,
or select a manually verified matching Kronoterm Cloud path.

## Data handling

- Configured heating loops (up to four), DHW and pool are distinguished from
  physical hardware: `unknown` never becomes `false` by inference.
- Missing Cloud pages are isolated from the main connection status.
- Daily electricity is queried separately using **read-only POST** (Kronoterm's
  consumption endpoint). Repeated `dValues[]` form keys are preserved.
- `CompHeating`, `CompTapWater`, `CPLoops`, `CPAddSource` are in kWh. A total is
  published only when all four components are valid. Cooling-specific daily
  electricity is not manufactured from `CompHeating`.
- The Cloud also exposes two 16-bit-word cumulative counters, published as
  separate diagnostic variables; after validating scale, monotonicity and
  scope, the electric counter can be selected as an external WPHub `EnergyID`
  source if appropriate (rather than being assigned automatically).
- `PowerID` and `EnergyID` continue to use external Symcon variables. Daily
  energy values are **not** cumulative lifetime meters.
- Neither setpoints nor a controller's calculated buffer temperature are
  mapped as physical measurements without verification.
- Existing variable Idents and archive histories are preserved.
- **No control requests** to the heat pump are implemented.

## Cloud credentials

The Cloud requires reusable credentials for reconnecting. They are stored in
Symcon instance properties, not in the repository or debug output. Protect
Symcon configuration backups accordingly.

## Offline verification

```sh
php -l WPHub/module.php
php .tools/test-kronoterm-client.php
php .tools/test-kronoterm-mapper.php
php .tools/test-kronoterm-buffer.php
php .tools/test-kronoterm-energy.php
```

## Device acceptance (still to be performed at an actual VERSI-X)

- Verify authentication and `hp_id`, regulator family/firmware, installed features.
- Check each available register/JSON value and unit against displayed values.
- Verify upper/lower buffer probe assignment and external variable selections.
- Compare daily components with Kronoterm Cloud on the same day.
- Validate optional unavailable pages, expired session, reconnection, archival
  persistence, contract 1.15 consumers and old Panasonic/Vaillant instances.

The snapshot includes potential reservoir temperature paths for manual inspection
but does not infer top/bottom from field names.

## Cloud-Datenpunktinventar (Gesamtpatch v2)

Nach einem erfolgreichen Kronoterm-Cloud-Abruf zeigt das Formular das Panel
**„Kronoterm Cloud-Datenpunktinventar“**. Darin gibt es zwei Tabellen:

- **Cloud-Werte, die WPHub nicht verwendet:** Alle tatsächlich erhaltenen
  numerischen, booleschen, kurzen Text- und Nullwerte ohne aktuelle
  WPHub-Zuordnung. Nicht abgefragte Cloud-Seiten werden nicht als leer gewertet.
- **Alle empfangenen Cloud-Werte mit WPHub-Zuordnung:** JSON-Pfad bzw.
  adressiertes Cloud-Register, aktueller oder zuletzt gesehener Wert, Einheit,
  Verwendung (`mapped`, `buffer`, `configuration`, `unused`), zugeordnete
  Symcon-Variable und Zeitstempel.

Die Klassen `KronotermMeasurementCatalog.php` und die vorhandenen Mapper
liefern gemeinsam die Zuordnung. Ein unbekanntes Register wird als empfangen,
aber ungenutzt angezeigt; es wird dadurch **nicht automatisch als Symcon-
Variable angelegt**. Selbsterklärende Temperaturfelder im Cloud-Snapshot
erscheinen als Pufferquellen, wenn sie numerisch und plausibel sind.
Sollwerte/berechnete Temperaturen werden dort ausgeschlossen.

Unter **Kronoterm Pufferspeicher und Temperaturfühler** wird pro Messstelle
zwischen `Deaktiviert`, `Kronoterm Cloud` und `Externe Symcon-Variable`
gewählt. Die vier Cloud-Feldpfade sind nun Dropdowns. Sie zeigen Pfad,
Quellbezeichnung und zuletzt empfangenen Wert. Die Auswahl verändert keine
Reglerkonfiguration und behält dieselben `KR_*CloudPath`-Properties bei.
Die physische Speicherart (`none`, `heating`, `cooling`, `separate`,
`combined`) bleibt unabhängig auswählbar.

Die Cloud-Tagesenergie wird in einem eigenen Intervall geholt. Im Katalog
werden ihre zuletzt empfangenen Werte mit ihrem tatsächlichen Abrufzeitpunkt
weiter angezeigt (nicht als aktueller Messwert ausgegeben). Nicht bekannte
Einheiten werden mit `?` markiert; bei lediglich namensbasiert erkannten
Temperaturen `°C?`.

Aus Datenschutzgründen werden sensible Felder wie Zugangsdaten, Sitzungen,
Geräte-IDs und Seriennummern nicht im Katalog gespeichert. Der Katalog
speichert nur einzelne bereinigte Werte, nicht die kompletten API-Antworten.
Er ist auf 6.000 Werte begrenzt und meldet eine mögliche Kürzung im Formular.
Ein PHP-Skript kann den Katalog zusätzlich über
`WPHUB_GetKronotermCloudInventory($instanceID)` als JSON abrufen.

### Funktionstest

1. Modulbibliothek in Symcon3 auf den aktualisierten Gitea-Branch bringen.
2. WPHub-Instanz öffnen, Kronoterm Cloud testen/Anlageninventar einlesen.
3. Im neuen Inventarpanel die nicht genutzten Cloud-Felder prüfen.
4. Heizpuffer auswählen, Quelltyp `Kronoterm Cloud` und im Dropdown das
   **inhaltlich passende Sensorfeld** auswählen, alternativ externe Variable.
5. Übernehmen, erneuten Abruf abwarten und den entstandenen Temperaturwert
   fachlich anhand der realen Pufferfühler prüfen.

Das Inventar ist eine dokumentierte Momentaufnahme der tatsächlich
angefragten Seiten und verfügbaren Reglerdaten, **keine Garantie** für
API-Felder, die der Controller/Cloud-Account nicht ausliefert.
