# WPHub — Übergabe-Kontext für die neue Sitzung

Dieses Repo wurde am 10.08.2026 von der EMS-Koordinationssitzung angelegt, auf
Basis einer Übergabe von HeishaMon (die den fachlichen Panasonic-Comfort-Cloud-
Vorschlag gemacht hat) und Dietmars Zustimmung. **Primärquelle für alle
Verbund-Konventionen ist die lokale SUITE.md (siehe unten)**
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

**Update 13.08.2026:** Das ist der Startzustand, kein Dauerzustand. Dietmars
Zukunftsziel: WPHub soll perspektivisch mehrere Wärmepumpenhersteller über
deren jeweilige Cloud-APIs bündeln — analog zu InverterHub (mehrere
Wechselrichter-Hersteller in einem Modul). Nicht von uns aus jetzt starten,
aber beim Weiterbauen nichts tun, das diesen Weg versperrt. Konkret im Blick
behalten: `CC_Email`/`CC_Password`/`CC_AppVersion` sind aktuell Comfort-Cloud-
spezifisch benannte Properties — bei tatsächlicher Erweiterung bräuchte es
eine Hersteller-Auswahl + je Hersteller ein Formular-Panel. Alles andere
(Geräteliste, Variablenpflege, Präfixbildung, `WPHUB_GetFunctions()`-Vertrag)
ist bereits herstellerneutral.

**Update 14.09.2026 (0.10.0):** Der "nicht von uns aus starten"-Vorbehalt ist
jetzt Dietmars ausdrücklicher Auftrag ("Ich hätte das schon gerne gebaut").
Umgesetzt: `Manufacturer`-Select-Property (Standard weiterhin "panasonic",
Muster InverterHub) + zweiter, paralleler Treiber-Zweig fuer **Vaillant
myVAILLANT** (`WPHub/libs/VaillantClient.php`, Referenz signalkraft/myPyllant).
Panasonic-Pfade (Login/Update/RequestAction/ccClient/refreshDevices/
maintainDeviceVariables) bewusst NICHT angefasst -- der Vaillant-Zweig hat
eigene Funktionen (loginVaillant ist public `LoginVaillant()`, dazu
`updateVaillant()`/`refreshDevicesVaillant()`/`maintainDeviceVariablesVaillant()`/
`vaillantClient()`/`vaillantTokenBundle()`/`vaillantEnsureToken()`) und ein
eigenes Attribut-Paar (`VAI_Token`/`VAI_DeviceList`, getrennt von
`CC_Token`/`CC_DeviceList` ueber `deviceListAttribute()`/`readDeviceList()`/
`writeDeviceList()`). Grund: Dietmars Panasonic-Instanz ist live produktiv --
ein gemeinsamer Umbau der bestehenden Pfade waere ein unnoetiges Risiko dafuer
gewesen. Vaillant ist **bewusst nur lesend** (keine Steuerbefehle) und **Stand
14.09.2026 ungeprueft an einem echten Konto** -- kein Vaillant-Testkonto
vorhanden, nur die Login-/ALTCHA-Logik ist eigenstaendig (mit nachgerechnetem
PBKDF2-Schluessel) unit-getestet. Naechster Schritt vor mehr Ausbau: ein
Tester mit echter Vaillant-Anlage (Forumsthread wirbt dafuer, siehe
`/Users/dietmar/Nextcloud/Claude/forum-ankuendigung-wphub.md`).

## Verbund-Konventionen (kondensiert, siehe SUITE.md für Details)

1. **Marke/Repo:** Verbund heißt nach außen "NRG-Stack", DG65 = Hersteller/Org
   (technisch: Dietmars persönlicher GitHub-Account, keine echte Org).
   `library.json→name` = "NRG-Stack WPHub" (12.09.2026: "for IP-Symcon"-Zusatz
   entfernt, Dietmars Entscheidung). `module.json→name`
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
   (dismissible). **Feld-Hilfestellung** (SUITE.md-Konvention seit
   01.09.2026, „Feld-Hilfestellung", ~Zeile 489–514 -- NICHT von uns
   erfunden, wir hatten sie nur unvollstaendig umgesetzt): `PopupButton`
   mit der VOLLEN, KONKRETEN Frage als Beschriftung, NIE nur "?" und NIE
   generisch ("Wofür ist das gut?" ohne Bezug ist zu unspezifisch, sobald
   mehrere Hilfe-Knoepfe untereinander stehen -- der Gegenstand gehoert in
   die Frage, z. B. "Wofür sind externe Sensoren & Zähler gut?"). `width`
   fest auf 460–480px (EMS-Empfehlung, bewaehrt fuer einzeilige Fragen;
   NICHT weglassen). Beschriftung und Popup-`caption` sind identisch. Kein
   natives Tooltip in Symcon-Formularen, daher PopupButton statt Tooltip.
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
9. **Branch-Modell:** `ems-integration` (verbundweit identischer Name) bleibt
   der aktive Entwicklungsbranch. Seit 16.09.2026 existiert zusätzlich `beta`
   (erster Store-Release-Branch, Dietmars Auftrag "nach Beta mergen"). **Seit
   18.09.2026 (Dietmars Entscheidung, verbundweit): beide Branches laufen
   automatisch gleich** — jeder Push nach `ems-integration` geht im selben Zug
   auch nach `beta`, kein manuelles Nachziehen mehr nötig. `main` existiert für
   dieses Repo noch nicht.
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

**Update 14.09.2026:** Punkt 1 (Verifikation am echten Konto) war hier
faelschlich noch als offen gefuehrt, obwohl das laengst erledigt ist — Login,
Geraetesuche und der Transfer-Proxy-Datenabruf laufen seit Build 14 (12.09.2026)
live an Dietmars echter Anlage (Standardadapter STD_ADP-TAW1), inkl. mehrerer
darauf aufbauender Klaerungen direkt am Konto (Consumption-Endpunkt, gesperrte
`/deviceHistoryData`, keine thermische Energiegroesse verfuegbar). Diese
CLAUDE.md-Liste wurde beim laufenden Ausbau nicht mitgepflegt — bei
Status-Fragen daher immer zusaetzlich das CHANGELOG.md gegenpruefen, nicht
allein auf diese Liste verlassen.

1. **Leistung/Energie:** Die Cloud liefert nachweislich keine
   vertragstaugliche (kumulative) Groesse — nur Tageswerte, die um
   Mitternacht auf 0 zurueckspringen (`dailyEnergy*ID`, contractVersion 1.11,
   bewusst NICHT `PowerID`/`EnergyID`). `PowerID`/`EnergyID` bleiben daher
   strukturell 0, es sei denn der Nutzer verknuepft manuell eine externe
   Zaehler-Variable (`Ext_PowerVariable`/`Ext_EnergyVariable`, z. B. aus
   MeterHub) — kein weiterer Klaerungsbedarf am Konto.
2. **2FA-Unterstützung** (Auth0 mfa_token-Flow, in der Python-Referenz
   vorhanden) — nur bei Bedarf.
3. ~~Forum-Hinweis-Panel~~ — erledigt 16.09.2026: Thread ist live
   (https://community.symcon.de/t/modul-nrg-stack-wphub-waermepumpen-cloud-anbindung-fuer-ip-symcon-panasonic-comfort-cloud-vaillant-myvaillant-cloud-alternative-zu-heishamon/144412),
   Panel `ForumHint()`/`AckForumHint()` in module.php verlinkt (0.10.1).
4. ~~Punkt-12-Checkliste vor dem ersten beta-Wechsel~~ — erledigt
   16.09.2026 (Ergebnis siehe CHANGELOG 0.10.4). `migrationsvergleich.php`
   (SUITE.md 9e) entfiel bewusst: erster Beta-Release, kein Vorgänger-Stand
   zum Vergleichen. Vor einem künftigen `beta`→`main`-Wechsel erneut
   durchgehen, dann greift 9e auch inhaltlich.

## Heizkurven-Recherche für Dashboard (18.09.2026)

Dashboard-Sitzung wollte einen einheitlichen `*_GetHeatingCurve`/`*_SetHeatingCurve`-Vertrag
für WPHub/WPModbusHub/SamsungEhs klären. Recherche-Ergebnis (Belege/Details siehe Chat-
Transkript, nicht hier dupliziert): **Panasonic Comfort Cloud hat gar kein Kurvenkonzept**
(nur Direkt-Zielwert je Zone, bereits als `Zone{n}Soll` implementiert). **Vaillant
myVAILLANT hat eine Heizkurve, aber nur die Steigung** (`Circuit.heating_curve`, PATCH
`.../circuit/{i}/heating-curve`, 0,1–5,0, Schritt 0,05) -- kein Fußpunkt/Niveau in der API,
ein "Verschieben" der Kurve ist darüber nicht moeglich, nur eine Drehung um einen fuer uns
unsichtbaren Punkt.

**Dietmars Entscheidung (Dashboard, 18.09.2026):** WPMonitor-Heizkurven-Reiter v1 wird NUR
gegen HeishaMon gebaut (einziger heute geprueft schreibbarer Weg). WPHub (Panasonic/Vaillant)
bekommt **keinen Zeitdruck** -- der Dashboard-Vertrag erhaelt Kapazitaetsfelder
(`curveModel`/`curveWritable`), damit spaeteres Andocken ohne UI-Umbau moeglich ist. Naechster
Schritt liegt bei uns: Vaillant-Schreibzugriff erst an echter Hardware verifizieren (bisher
KEIN Testkonto), bevor `SetHeatingCurve()` gebaut wird -- dann von uns aus bei Dashboard
melden, nicht umgekehrt.

## Offene Anfrage: Fujitsu Waterstage über BSB-LAN (23.09.2026)

Forumsnutzer "ArMu"/Lütfü (WPHub-Thread, Beitrag #4) hat eine Fujitsu Waterstage
WSYK160DG9 mit Siemens-Steuerung RVS21.831/127, bereits über einen BSB-LAN-Adapter
(ESP32) lokal in Symcon eingebunden, Kommunikation via MQTT/JSON/HTTP. **Das ist KEINE
Cloud-Anbindung und gehört damit NICHT zu WPHub** -- architektonisch naeher an
WPModbusHub (lokal, Register-/Parameterbasiert) oder SamsungEhs (lokal, eigenes
Protokoll) als an WPHub (Cloud/Token). Noch kein Modul gebaut, noch keine Dietmar-
Entscheidung, ob/als was das umgesetzt wird -- Forumsantwort fragt gezielt nach
BSB-LAN-Schnittstelle (HTTP/JSON vs. MQTT), konkreten Parameternummern und einem
Beispielsatz, bevor irgendetwas gebaut wird (Muster: erst Ghostraiders/Christians
konkrete Werte, dann Registerkarte -- nicht raten).

**Update 23.09.2026 (Lütfüs Antwort, Forum-Post #6):** HTTP/JSON via `GET /JQ=<Parameternummer>`,
drei echte Beispiele geliefert: Außentemperatur (8700) = 11,9 °C, Vorlauftemperatur
Wärmepumpe (8412) = 23.8 °C, Rücklauftemperatur Wärmepumpe (8410) = 33.4 °C. Seine
Parameterdefinition ist controller-spezifisch von einem BSB-LAN-Maintainer ("Frederik")
aus Rohdaten seiner RVS21.831F/127 erstellt worden (~6000 Parameter fuer diesen Regler) --
**vermutlich NICHT 1:1 auf andere RVS-Regler uebertragbar**, anders als z. B. IDMs
Navigator-2.0-PDF, das ausdruecklich fuer eine ganze Reglerfamilie gilt. Aktuell nur
lesend, Schreiben ungetestet. Er ist bereit zu testen.

**Update 23.09.2026 (selbst recherchiert, Dietmars Nachfrage "Hast Du den Link auch
angeguckt?"):** offizielle Doku (docs.bsb-lan.de) + das Projekt-eigene `openapi.yaml`
(github.com/fredlcore/bsb_lan, API-Version 2.4) gelesen -- **JSON-Schema damit geklaert,
OHNE Lütfü fragen zu muessen.** Die drei Beispiele waren scheinbar inkonsistent: Lütfüs
erstes Beispiel (8700, Aussentemperatur) hatte deutsche, grossgeschriebene Feldnamen
("Name"/"Wert"/"Fehler"/"Genauigkeit"/"Einheit", dazu ungueltiges JSON mit Dezimalkomma
"0,1" statt Punkt), die beiden anderen (8412/8410) englische, kleingeschriebene
("name"/"value"/"error"/"precision"/"unit", Dezimalpunkt). Der offizielle `ParameterMap`/
`Parameter`-Schema-Typ in `openapi.yaml` bestaetigt **exakt das zweite/dritte Format**
(name/dataType_family/dataType_name/destination/error/value/desc/payload/precision/
dataType/readwrite/unit, Punkt-Dezimaltrennzeichen) als das stabile, versionierte
Format (`/JV` liefert die API-Version) -- **unabhaengig vom Regler**, nicht
controller-spezifisch. Lütfüs erstes Beispiel war offenbar keine echte API-Antwort
(vermutlich aus der deutschen Web-Oberflaeche abgetippt/uebersetzt beim Posten). Dietmar-
Hinweis (23.09.2026): Lütfü ist vermutlich kein Programmierer -- deshalb NICHT nach dem
Schema-Unterschied gefragt (koennte er kaum beantworten), stattdessen selbst geklaert und
nur noch simple, ohne Technikwissen beantwortbare Fragen gestellt (Parameternummern im
Webinterface ablesen).

**Weitere Erkenntnisse aus der offiziellen Doku, relevant fuers spaetere Modul:**
- `/JK=<Kategorie>`/`/JC=<Parameter,...>` liefern `ParameterDefinition` inkl. `name`,
  `unit`, `possibleValues` (Enum-Beschreibungen), `dataType` -- BSB-LAN ist teilweise
  SELBSTBESCHREIBEND, anders als unsere Modbus-Registerkarten (WPMBHUB_Drivers), die alles
  statisch vorhalten muessen. Koennte die Registerkarte deutlich schlanker machen (Namen/
  Einheiten vom Geraet lesen statt hart im Code).
- `/JS` (Schreiben) und `/JB` ("Liste aller schreibbaren Parameter") existieren offiziell --
  Schreiben waere hier protokollseitig vorgesehen, anders als bei WPModbusHub (v1 bewusst
  nur lesend) faellt die Grundsatzfrage "geht das ueberhaupt" schon mal weg, falls spaeter
  gewuenscht (v1 bliebe trotzdem nur lesend, analog zu allen anderen WP-Modulen).
- `/Q` liefert die geraetespezifische Parameterliste direkt vom BSB-LAN-Geraet als
  Download -- das waere die verlaesslichste Quelle fuer Lütfüs konkrete Parameternummern,
  noch besser als einzelne Beispiele oder Frederiks Datei aus zweiter Hand.
- Transportarten: HTTP/JSON-Polling (wie hier angefragt) ODER MQTT (von BSB-LAN offiziell
  EMPFOHLEN, Topic-Schema `<Topic>/<Geraete-ID>/<Kategorie>/<Parameter>` mit /status,/set,
  /poll). Fuer ein erstes Modul spricht HTTP/JSON-Polling trotzdem dafuer: kein zusaetzlicher
  MQTT-Broker als Voraussetzung noetig, konsistent mit dem Zyklus-Poll-Muster von
  WPModbusHub/SamsungEhs -- MQTT waere ein moeglicher spaeterer Ausbau, kein v1-Blocker.

**Update 23.09.2026 (zweite Recherche, Dietmars Hinweis "gibt es ein Forum fuer
Waermepumpen und Heizungen"):** Websuche (haustechnikdialog, GitHub, diverse Blogs) ergab
einen Blog eines ANDEREN Fujitsu-Waterstage/RVS21-Besitzers mit BSB-LAN
(mattstech.info/posts/fujitsu-bsblan-monitoring/ + .../fujitsu-waterstage-domotica/),
der eine vollstaendige Parameterliste fuer genau diese Baureihe veroeffentlicht hat.
**Starke Gegenprobe:** seine dort gelisteten Vorlauf-/Ruecklauf-Parameter (8412/8410)
stimmen EXAKT mit Lütfüs eigenen, bereits bestaetigten Werten ueberein -- deutlicher
Hinweis, dass der Rest der Liste ebenfalls fuer seine Anlage passt:

| Ident | Parameter | Quelle |
|---|---|---|
| Aussentemperatur | 8700 | mattstech.info, deckt sich mit Lütfüs eigenem Beispiel |
| Vorlauftemperatur | 8412 | mattstech.info, EXAKT identisch mit Lütfüs eigenem Wert |
| Ruecklauftemperatur | 8410 | mattstech.info, EXAKT identisch mit Lütfüs eigenem Wert |
| Warmwasser Ist | 8830 ("DHW temp actual value top") | mattstech.info, NOCH NICHT von Lütfü bestaetigt |
| Warmwasser Soll | 8831 ("DHW temp current setpoint", aktuell wirksam) bzw. 1610 ("DHW temp nominal setpoint", Komfort-Vorgabe) -- welches der Vertrag will, noch offen | mattstech.info, NOCH NICHT von Lütfü bestaetigt |
| Betriebsart | 700 | mattstech.info UND BSB-LANs eigenes offizielles Doku-Beispiel (homeautomation.html, "Parameter 700 = Betriebsart Heizkreis 1") -- zwei unabhaengige Quellen |

**Update 23.09.2026 (Lütfüs Bestaetigung, Forum-Post #8) -- ALLE SECHS Werte live
bestaetigt:** "Die Werte sind identisch mit den Werten, die die Anlage auf dem Display
anzeigt." Damit ist die komplette Basisregisterkarte an echter Hardware verifiziert:

| Ident | Parameter | Bestaetigter Wert (23.09.2026) |
|---|---|---|
| Aussentemperatur | 8700 | 11,9 °C (Lütfüs eigenes erstes Beispiel) |
| Vorlauftemperatur | 8412 | 23,8 °C |
| Ruecklauftemperatur | 8410 | 33,4 °C |
| Warmwasser Ist | 8830 ("Trinkwassertemperatur-Istwert Oben (B3)") | 52,5 °C |
| Warmwasser Soll | 8831 ("Trinkwassertemperatur-Sollwert aktuell") | 55,0 °C |
| Betriebsart | 700 (ENUM) | 0 = "Schutzbetrieb" |

Betriebsart (700) ist ein ENUM mit `desc`-Klartext direkt von BSB-LAN mitgeliefert
("Schutzbetrieb" bei Wert 0) -- muss NICHT wie bei anderen Herstellern lokal in eine
Klartext-Tabelle uebersetzt werden, BSB-LAN liefert die Beschreibung schon mit.

**Damit ist genug fuer einen ersten Modulentwurf da.** Bekannte Einschraenkung bleibt:
Lütfüs Parameterdefinition ist von einem BSB-LAN-Maintainer aus seinen eigenen Rohdaten
erstellt -- ob dieselben Parameternummern 1:1 auf ANDERE RVS21-Anlagen (andere
Konfiguration/Firmware-Stand) uebertragbar sind, ist damit noch nicht gesagt, nur fuer
Lütfüs eigene Anlage bestaetigt (Muster: IDM-PDF gilt fuer eine ganze Reglerfamilie, das
hier vorerst nur fuer EIN Geraet) -- deshalb im neuen Modul frei editierbare
Parameternummern statt einer festen Registerkarte.

**Update 23.09.2026 -- Dietmar-Entscheidung "Ja, bauen" (AskUserQuestion), umgesetzt.**
Neues, eigenes Repo **WPBsbLan** (`DG65/NRGWPBsbLan`, Praefix `WPBSBL`, lokal
`/Users/dietmar/Nextcloud/Claude/WPBsbLan/`), NICHT Teil von WPHub -- siehe eigene
CLAUDE.md dort fuer Architektur/Details. Version 0.1.0 gepusht (`ems-integration` +
`beta`, Commit `3be1b05`), 58-Pruefungen-Pruefstand, neun Mutationen geprueft. Fuenfter
Baustein der Waermepumpen-Vertikale neben WPHub/HeishaMon/WPModbusHub+Gateway/SamsungEhs.
Noch offen: Forumsantwort an Lütfü mit dem Ergebnis, Forumsthread fuer WPBsbLan selbst
(noch keiner -- `ForumHint()` liefert bewusst `null` bis dahin).

## Vaillant: camelCase-Fix + vrc700-Anlauf (0.11.0, 25.09.2026)

cbeham hat sich im Thread gemeldet (Beitrag #19/#20/#22): WPHub installiert, nur
"Panasonic" sichtbar (veraltete Store-Version, Vaillant ist seit 0.10.0 im Code --
Update-Hinweis gepostet), danach als echter myVAILLANT-Kontoinhaber getestet
(Vaillant LWP VWF117 + recoVair365/4-Lueftung). Login klappte ("Angemeldet,
Zugangsschluessel gespeichert"), aber "im Konto wurde keine Anlage gefunden" --
Debug-Screenshot zeigte die Ursache: `Vaillant/Geraete: Uebersprungen (Regler-Typ
"vrc700" noch nicht unterstuetzt)`.

**Zwei Funde beim Nachlesen des myPyllant-Quelltexts (github.com/signalkraft/myPyllant,
25.09.2026, NICHT geraten):**

1. **Bug im bestehenden "tli"-Pfad, unabhaengig von cbehams Anlage.** Die rohe
   Vaillant-API liefert camelCase-Feldnamen (z. B. `outdoorTemperature`), myPyllant
   wandelt das selbst per `dict_to_snake_case()` (Regex `(?<!^)(?=[A-Z])`, Unterstrich
   vor JEDEM Grossbuchstaben) in snake_case um -- WPHub hatte diesen Konvertierungsschritt
   nie gebaut, sondern gleich die snake_case-Namen aus myPyllants eigenen (bereits
   konvertierten) Pydantic-Modellen als vermeintliche Roh-Feldnamen uebernommen. Ohne
   Konvertierung waeren bei JEDER tli-Anlage (nicht nur bei cbeham) alle Temperaturfelder
   leer geblieben -- nie aufgefallen, weil noch kein Tester bis zu den echten Werten kam.
   Fix: `WPHUB_VaillantClient::snakeCaseKeysDeep()`, 1:1-Nachbau der Python-Regex,
   angewendet in `parseTliBody()`.
2. **vrc700 hat eine EIGENE Basis-URL**, nicht die tli-Basis mit anderem Pfad-Suffix
   (`.../vrc700/v1` statt `.../end-user-app-api/v1`, aus `myPyllant.const.API_URL_BASE`).
   Dazu ein Textersatz VOR dem JSON-Dekodieren (`domesticHotWater`->`dhw`,
   `DomesticHotWater`->`Dhw`) -- 1:1 aus `myPyllant.api.get_systems()`. Neu:
   `getSystemVrc700()`/`parseVrc700Body()`, `refreshDevicesVaillant()` ruft das jetzt
   auf statt zu ueberspringen. `scf`/iQconnect bleibt uebersprungen (laut Referenz-
   Kommentar strukturell ohne aggregiertes System).

**Bewusst NICHT geraten:** welche Feldnamen eine echte vrc700-Anlage nach der
Konvertierung tatsaechlich liefert (Akronym-Handling wie bei DHW macht das Ergebnis
NICHT 1:1 identisch mit tli, z. B. `_dhw` statt `_d_h_w`) -- `maintainDeviceVariablesVaillant()`
liest weiterhin nur die bei tli bestaetigten Feldnamen; findet sie keinen Treffer, bleibt
die Anlage erreichbar/erkannt, aber ohne Werte. Das komplette Roh-System geht zusaetzlich
per `SendDebug('Vaillant/vrc700-Rohdaten', ...)` raus, um von cbeham die echten
Feldnamen zu bekommen -- naechster Schritt nach seinem Update auf 0.11.0.

Pruefstand: 299 Pruefungen (vorher 288), sechs neue Mutationen (`snakeCaseKeysDeep()`
nicht angewendet, DHW-Ersatz fehlt, vrc700-Basis-URL falsch, vrc700 wieder uebersprungen
-- je einmal fuer tli und vrc700) alle gefangen.

## Vaillant: API-Kontingent-Sperrfrist (0.11.1, 25.09.2026)

m_rothenpieler (Forum-Post #24, 2x aroTHERM split 7,5 kW Kaskade, sensoCOMFORT ueber
VR920 -- vermutlich "tli", nicht "vrc700") meldete: Login/Erreichbar funktionierte, aber
keine weiteren Werte, und nach mehreren 60s-Zyklen `GET /v1/homes -> HTTP 403
"Out of call volume quota. Quota will be replenished in 00:02:51."`, danach Erreichbar
auf Alarm. Er hat das Modul wieder entfernt. Seine Vermutung ("Datenstruktur stimmt nicht")
deckt sich vermutlich mit dem gerade erst gefundenen camelCase-Bug (siehe Abschnitt oben,
0.11.0) -- die Kontingent-Sperre ist aber ein ZWEITES, unabhaengiges Problem: WPHub fragt
bislang JEDEN Zyklus ungebremst erneut an, auch direkt nach einem 403, was die Sperre nur
verlaengert haben duerfte.

**Fix:** `WPHUB_VaillantClient::parseQuotaRetrySeconds()` liest Vaillants eigene Angabe
("Quota will be replenished in HH:MM:SS") aus der Fehlerantwort, `failApi()` setzt das
oeffentliche `$client->quotaRetryAfterSeconds`, sobald HTTP 403 kommt (Fallback 5 Minuten,
falls das Zeitmuster mal fehlt). `updateVaillant()` legt daraus eine Sperrfrist
(`VAI_RetryNotBefore`-Attribut) an und ueberspringt waehrend dieser Zeit den kompletten
Zyklus -- kein weiterer API-Aufruf, keine weitere Protokollzeile, bis die Sperrfrist um ist.
**Noch NICHT geloest:** ob das Kontingent pro Konto oder GETEILT ueber den oeffentlichen,
festen `SUBSCRIPTION_KEY` (alle myPyllant-/WPHub-Nutzer weltweit) gilt, ist unbekannt --
falls Letzteres, hilft eine laengere Pause nur bedingt. Kein Anlass, den Subscription-Key
zu aendern (oeffentlich, aus myPyllant selbst, keine Alternative bekannt).

Testbarkeit: `vaillantClient()` hat jetzt denselben `$GLOBALS['ips']['vaillantClientFactory']`-
Testseam wie WPBsbLan (`bsbClientFactory`) -- vorher liess sich `updateVaillant()` gar
nicht ohne echten Netzzugriff pruefen. Pruefstand 305 -> 311, sechs neue Mutationen
gefangen (inkl. der Verdrahtung in `failApi()`, direkt am echten Client statt nur an der
Attrappe getestet).

## Erste echte Vaillant-Bestaetigung + offene Datenluecken (0.11.2, Forum-Post #26, 25.09.2026)

Markus (m_rothenpieler) hat 0.11.1 installiert: **Login funktioniert, erste Werte kommen
an** -- Erreichbarkeit OK, Aussentemperatur 14,4 °C, Vorlauftemperatur 31,2 °C,
Puffertemperatur oben 31,2 °C, Systemdruck 1,90 bar. Damit ist der camelCase-Fix (0.11.0)
UND die Kontingent-Sperrfrist (0.11.1) an echter Hardware bestaetigt -- der erste
tatsaechlich funktionierende Vaillant-Login im gesamten Modul.

**Zwei offene Punkte, NICHT geraten geloest:**
1. **Vorlauf- und Puffertemperatur sind bei ihm exakt identisch und aendern sich synchron.**
   Koennte an seiner Anlagentopologie liegen (Kaskade mit hydraulischer Weiche/Puffer, wo
   Systemvorlauf = Puffer-oben-Temperatur physikalisch plausibel gleich sein KOENNTE) oder
   an einer falschen Feldzuordnung im Code. Ohne sein rohes System-JSON nicht zu klaeren.
2. **Warmwasser/Betriebszustaende/Energie/Kaskaden-Einzelgeraete fehlen komplett**, obwohl
   in der myVAILLANT-App teilweise sichtbar. Beim Nachlesen im myPyllant-Datenmodell
   (models.py, 25.09.2026): DHW steckt in `System.domestic_hot_water` (eigene LISTE,
   eigene Klasse `DomesticHotWater` mit `current_dhw_temperature`/`tapping_setpoint`/
   `operation_mode_dhw`), Kreise in `System.circuits` (Liste), Kaskaden-Einzelgeraete in
   `System.devices` (Liste, `System.primary_heat_generator`), Energie ueber
   `Device.data`/`DeviceData`-Klassen (eigene History-Buckets). **WPHub liest bislang nur
   flache `state.system.*`-Felder** -- die reichhaltigeren Listen-Strukturen sind noch NICHT
   angebunden. Das ist ein groesserer Ausbau als die bisherigen Fixes (mehrere neue
   Unterstrukturen, nicht nur ein Feldname), noch keine Dietmar-Entscheidung dazu.

**Sofortmassnahme:** tli-Rohdaten gehen jetzt ebenso wie vrc700 per
`SendDebug('Vaillant/tli-Rohdaten', ...)` raus (vorher nur vrc700) -- Markus um seinen
Dump gebeten, um beide Punkte an echten Daten statt an weiteren Vermutungen zu klaeren
(genau das Muster, das bei IDM/Proxon/SamsungEhs/WPBsbLan schon funktioniert hat).
Pruefstand 311 -> 312.

## Verbund-Kontakt

Bei Rückfragen zur Kontraktform: HeishaMon-Sitzung direkt anschreiben
(`mcp__ccd_session_mgmt__send_message`, deren `session_id` über
`list_sessions` finden). Bei Verbund-weiten Architekturfragen: EMS-
Koordinationssitzung.


## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
