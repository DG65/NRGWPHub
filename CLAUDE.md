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

## Was noch fehlt (eigentliche Aufgabe dieser Sitzung)

1. Panasonic Comfort Cloud API recherchieren (inoffiziell, kein offizielles
   öffentliches API — Community-Reverse-Engineering nötig, z. B. bestehende
   Python-/Node-Bibliotheken als Referenz für den Login-Flow prüfen).
2. `Login()`-Methode: E-Mail+Passwort → Token, Token in `CC_Token` ablegen,
   Property-Passwort NICHT dauerhaft behalten.
3. Geräteliste + Messwerte abrufen, `CC_DeviceList`-Attribut befüllen,
   passende IPS-Variablen anlegen (`MaintainVariable()`, siehe SUITE.md
   Stolperfalle zu `RegisterVariableXXX()`).
4. `GetFunctions()` mit echten `PowerID`/`EnergyID` befüllen.
5. Token-Ablauf/Reauth-Handling.
6. "🆕 Neu in Version 0.1.0"-Panel ergänzen, sobald der erste funktionsfähige
   Stand steht (aktuell bewusst weggelassen, Scaffold hat noch keine
   nutzbare Funktion).
7. Punkt-12-Checkliste ("Neuinstallations-Simulation") vor dem ersten
   beta/main-Wechsel durchgehen.

## Verbund-Kontakt

Bei Rückfragen zur Kontraktform: HeishaMon-Sitzung direkt anschreiben
(`mcp__ccd_session_mgmt__send_message`, deren `session_id` über
`list_sessions` finden). Bei Verbund-weiten Architekturfragen: EMS-
Koordinationssitzung.
