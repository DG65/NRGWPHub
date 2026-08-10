# WPHub — Übergabe-Kontext für die neue Sitzung

Dieses Repo wurde am 10.08.2026 von der EMS-Koordinationssitzung angelegt, auf
Basis einer Übergabe von HeishaMon (die den fachlichen Panasonic-Comfort-Cloud-
Vorschlag gemacht hat) und Dietmars Zustimmung. **Primärquelle für alle
Verbund-Konventionen ist [EMS/SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md)**
— bei Zweifeln dort zuerst grep'en, nicht Code zwischen Modulen vergleichen.
Das Folgende ist eine kondensierte Zusammenfassung, kein Ersatz.

## Scope-Entscheidung (bereits getroffen, nicht neu diskutieren)

WPHub startet **ausschließlich mit Panasonic Comfort Cloud** — als Cloud-
Alternative zu HeishaMon (lokal/MQTT) für Nutzer ohne HeishaMon-Platine.
Grund: Dietmar hat nur eine Panasonic-Anlage, andere Herstellerclouds
(Mitsubishi MELCloud, Viessmann, Vaillant myVaillant, Stiebel Eltron ISG, ...)
kann er nicht selbst testen. Diese folgen später über die Community, sobald
sich Nutzer mit passender Hardware finden — analog zu Tessie/TibberGridReward,
die auch mit einem Hersteller/Dienst gestartet sind, für den Dietmar Testzugang
hatte. **Nicht von sich aus mit weiteren Herstellern anfangen.**

## Verbund-Konventionen (kondensiert, siehe SUITE.md für Details)

1. **Marke/Repo:** Verbund heißt nach außen "NRG-Stack", DG65 = Hersteller/Org
   (technisch: Dietmars persönlicher GitHub-Account, keine echte Org).
   `library.json→name` = "NRG-Stack WPHub for IP-Symcon". `module.json→name`
   (PHP-Klassenname) bleibt technisch "WPHub", NIE mit Bindestrich, taucht
   nicht im Markennamen auf.
2. **Lizenz:** PolyForm Noncommercial 1.0.0, LICENSE-Datei bereits 1:1 aus dem
   EMS-Repo übernommen — Rechtstext nicht umformulieren.
3. **Sprache:** Alle nutzersichtbaren Texte deutsch, keine vermeidbaren
   Anglizismen (Ausnahme: Idents, Klassennamen, feststehende Technikbegriffe).
4. **Zielbild:** Bei jeder Entscheidung mitdenken — Wirtschaftlichkeit,
   Netzdienlichkeit/Rechtskonformität, Zuverlässigkeit ohne KI-Krücke (kein
   Endnutzer hat eine KI-Sitzung parat), Einfachheit.
5. **Formular-Konvention:** "🆕 Neu in Version X.Y" (aufgeklappt, pro-Version
   dismissible, KEINE Versionsnummer drin) → "📖 Dokumentation & Hilfe"
   (eingeklappt, Versionsnummer rein) → Fachpanels → Forum-Hinweis
   (dismissible). Erklärungsbedürftige Felder: `PopupButton` mit `"?"`-
   Beschriftung, `width:"70px"` (kein natives Tooltip in Symcon-Formularen).
   Referenz: InverterHub.
6. **Versionierung:** SemVer je Modul. Datenverträge liefern additiv
   `'contractVersion' => 'Major.Minor'`, Major nur bei Bruch.
7. **Contract-Form (WICHTIG, bereits im Scaffold umgesetzt):**
   `WPHUB_GetFunctions()` muss `Type=>'heatpump'` liefern, konsistent zu
   HeishaMons Form (`Caption`, `PowerID`, `EnergyID`, `Measured`,
   `unit=>'W'`, `reachable`, `contractVersion`). Referenz:
   https://github.com/DG65/NRGHeishaMon/blob/ems-integration/HeishaMon/module.php
   (Methode `GetFunctions()`) — vor dem Ausbau dort nochmal gegenprüfen.
8. **Credentials (WICHTIG für dieses Cloud-Modul):** Handshake/Token
   bevorzugt — Passwort nur einmalig für den Login-Handshake, danach NICHT
   speichern, nur das Token. Token in `RegisterAttributeString` (NICHT
   Property). IPS verschlüsselt Attribute NICHT at rest — "sicher" heißt nur
   "nicht im Formular/Log sichtbar", nicht verschlüsselt, so auch gegenüber
   dem Nutzer kommunizieren. `PasswordTextBox` für die Formulareingabe.
   Referenz: MeterHub/Inexogy-Treiber. Im Scaffold bereits als
   `CC_Email`/`CC_Password` (Properties, Login-Input) und `CC_Token`
   (Attribut, Ergebnis) angelegt — `Login()`/Handshake-Logik fehlt noch.
9. **Branch-Modell:** `ems-integration` (verbundweit identischer Name) —
   solange die EMS-Integrationsphase läuft, geht ALLES dorthin, nicht auf
   `beta`/`main`.
10. **Store-Review-Checkliste (12 Punkte, siehe SUITE.md):** u. a. keine
    Selbstpersistenz in Formular-Buttons, `vendor` in `module.json` =
    Gerätehersteller (hier "Panasonic", NICHT "DG65" — bereits so gesetzt),
    `library.json` NUR die 8 Store-Felder, `Translate()`-Quellstrings
    englisch, Punkt 12 "Neuinstallations-Simulation" vor jedem
    beta→main-Wechsel.
11. **IPS-Stolperfallen:** `module.json→name` MUSS exakt der PHP-Klassenname
    sein; globale Hilfsklassen (HTTP-Client für die Comfort-Cloud-API)
    brauchen ein Modul-Präfix wegen Namenskollisionen zwischen Modulen im
    selben EMS-Prozess; form.json-List-Spalten ohne `save:true` gehen beim
    Übernehmen verloren; Emojis sind ausdrücklich erwünscht.
12. **Modul-Update:** Push auf GitHub wirkt NICHT automatisch — Dietmar zieht
    den Stand manuell über die Modulverwaltungs-Konsole nach. Nach Push:
    Baufortschritt melden und warten, nicht selbst per API forcieren.
13. **Modulverwaltungs-Instabilität:** Eigenes Modul NIEMALS zusätzlich über
    den offiziellen Symcon Module Store buchen (nur Git-Tracking).
14. **Cross-Session-Kommunikation:** `mcp__ccd_session_mgmt__send_message`
    verwenden (Ziel-`session_id` über `list_sessions`), NICHT `SendMessage`.

## Was im Scaffold bereits steht

- `library.json`, `WPHub/module.json` (GUIDs frisch generiert, `vendor:
  "Panasonic"`, `prefix: "WPHUB"`)
- `WPHub/module.php`: Modul-Lebenszyklus (`Create`/`ApplyChanges`/
  `GetConfigurationForm`), `Update()`-Skelett (TODO markiert), sowie
  `GetFunctions()` mit der korrekten `Type=>'heatpump'`-Vertragsform
  (liest aktuell nur aus dem noch leeren `CC_DeviceList`-Attribut)
- `WPHub/form.json`: Doku-Panel, Allgemein-Panel, Comfort-Cloud-Panel mit
  `PasswordTextBox` + Sicherheits-PopupButton
- `LICENSE` (1:1 aus EMS-Repo)

## Umsetzungsstand (10.08.2026, Build 2)

Erledigt (siehe CHANGELOG 0.1.0 Build 2):

1. ✅ API recherchiert — Referenz: sockless-coding/aio-panasonic-comfort-cloud
   (Python, aktiv gepflegt; lostfields' requests.http beschreibt nur den
   VERALTETEN Vor-2023-Flow). Kernpunkte: Auth0/PKCE-Login auf
   authglb.digital.panasonic.com (Scope enthält `a2w.control` → Aquarea läuft
   über die Comfort Cloud, kein separates Aquarea-Smart-Cloud-Konto nötig),
   Geräte-API auf accsmart.panasonic.com mit signiertem `x-cfc-api-key`
   (sha256 aus Zeitstempel+Token, in PHP nachgebaut und gegen die
   Python-Referenz abgeglichen), A2W-Status über den Transfer-Proxy
   `/remote/v1/app/common/transfer`. Alles in
   `WPHub/libs/ComfortCloudClient.php` (Klasse `WPHUB_ComfortCloudClient`).
2. ✅ `Login()` (Auth0-Handshake, Token-Bündel in `CC_Token`, Passwort-Property
   wird nach Erfolg geleert — Muster MeterHub/InexogyLogin). 2FA-Konten werden
   erkannt und mit klarer Meldung abgelehnt (noch nicht unterstützt).
3. ✅ Gerätesuche + Variablen (`MaintainVariable`, `NRG.Celsius` nur-bei-Fehlen):
   Erreichbar, Betrieb, Außentemperatur, Warmwasser Ist/Soll, Zonen Ist/Soll.
   Marker 126 = „kein Messwert" wird gefiltert; Klimageräte (Einträge MIT
   `parameters` in der Gruppenantwort) werden bewusst übersprungen.
4. ⚠️ `GetFunctions()` liefert HeishaMon-Form mit contractVersion **1.2**
   (HeishaMon ems-integration liefert 1.2, nicht mehr 1.0 wie im Scaffold) —
   aber `PowerID`/`EnergyID` bewusst 0: Die Cloud liefert keine
   Momentanleistung, Verbrauch nur als Tageswerte → Verbund-Regel „Energie nur
   aus kumulativen Zählern, nie hochrechnen".
5. ✅ Token-Erneuerung über Refresh-Token (5-Minuten-Vorlauf); schlägt sie fehl
   → Status 201 „Anmeldung erforderlich" + Protokollhinweis (Neuanmeldung kann
   das Modul mangels gespeichertem Passwort bewusst nicht selbst auslösen).
6. ✅ „🆕 Neu in Version"-Panel (Attribut `SeenNews` + `UpdateFormField`, kein
   Selbst-Persistieren).
7. Prüfstand: `php .tools/test-module.php` (39 Prüfungen, ohne Netz).

## Was noch offen ist

1. **Verifikation am echten Konto** — der komplette Login-/Abruf-Pfad ist
   gegen die Python-Referenz gebaut, aber noch nie gegen die echte Cloud
   gelaufen. Erster Test an Dietmars Anlage nötig (SendDebug der Instanz
   liefert die HTTP-Stationen).
2. **Leistung/Energie:** `getAquareaConsumption()` ist experimentell
   vorbereitet (Pfad aus aioaquarea, über den Transfer-Proxy ungeprüft).
   Wenn am echten Konto verifiziert: klären, ob daraus eine vertragstaugliche
   Größe wird (kumulativ zählen? → mit EMS abstimmen), erst dann
   `PowerID`/`EnergyID` befüllen.
3. **2FA-Unterstützung** (Auth0 mfa_token-Flow, in der Python-Referenz
   vorhanden) — nur bei Bedarf.
4. **Forum-Hinweis-Panel** folgt, sobald es einen WPHub-Forumsthread gibt
   (gleiche Begründung wie bei MeterHub).
5. Punkt-12-Checkliste ("Neuinstallations-Simulation") vor dem ersten
   beta/main-Wechsel durchgehen.

## Verbund-Kontakt

Bei Rückfragen zur Kontraktform: HeishaMon-Sitzung direkt anschreiben
(`mcp__ccd_session_mgmt__send_message`, deren `session_id` über
`list_sessions` finden). Bei Verbund-weiten Architekturfragen: EMS-
Koordinationssitzung.
