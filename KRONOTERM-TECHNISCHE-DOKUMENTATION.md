# NRGWPHub – Kronoterm-Cloud-Integration (Gesamtstand v5)

**Dokumentationsstand:** 13.11.2026
**Ausgangsbasis:** Original-NRGWPHub von DG65, aus dem für die Entwicklung bereitgestellten Iststand der Version 0.17.1.
**Erweiterungsstand:** v2-Gesamtpatch → korrigiertes v3 → v4 → v5.
**System:** IP-Symcon 9, PHP-Modul `WPHub`.
**Umfang:** Direkte, lesende Kronoterm-Cloud-Anbindung zusätzlich zu Panasonic Comfort Cloud und Vaillant myVAILLANT.

> Diese Dokumentation beschreibt die **tatsächlich implementierte** Erweiterung. Sie trennt dokumentierte Registerbedeutungen, erkannte API-Namen und noch nicht verifizierte Interpretationen. Kronoterm verwendet **nicht** die Panasonic Comfort Cloud.

## 1. Ziel und Abgrenzung

Die Erweiterung integriert Kronoterm-Wärmepumpen, darunter die getestete VERSI-X, in die bestehende NRGWPHub-Modulbibliothek. Messwerte, Zustände und ein Cloud-Dateninventar werden in Symcon bereitgestellt und an den vorhandenen NRG-Stack-Verbundvertrag übergeben. Für die Kronoterm-Anbindung werden **keine Stellbefehle oder Regleränderungen** gesendet.

Die bestehende Panasonic- und Vaillant-Anbindung bleibt in ihren eigenen Pfaden erhalten. Der Hersteller wird über die vorhandene `Manufacturer`-Property gewählt (`kronoterm`). Eine Instanz repräsentiert die vom Cloud-Konto ausgewählte Wärmepumpe; Mehrgeräteauswahl ist in dieser Kronoterm-Implementierung nicht enthalten.

**Verbindliche Messquellenregeln:**

| Messgröße | Quelle / Verhalten |
|---|---|
| Elektrische Momentanleistung | Ausschließlich `Ext_PowerVariable` (externe Symcon-Variable, W) |
| Elektrische kumulierte Energie | Ausschließlich `Ext_EnergyVariable` (externe Symcon-Variable, kWh) |
| Wärmepumpen-Eintritt / Rücklauf | `main.ModbusReg[2101]` → Float `KTWPEintritt` |
| Wärmepumpen-Austritt / Vorlauf | `main.ModbusReg[2104]` → Float `KTWPAustritt` |
| Außentemperatur | `main.ModbusReg[2103]` → `Aussentemperatur` |
| Puffertemperatur oben | `info.TemperaturesAndConfig.reservoir_temp` → `Puffertemperatur` |
| Thermische Leistung | `main.ModbusReg[2329]` → `KTThermischeLeistung`; **nicht** elektrische Leistung |
| Elektrischer Cloud-Energiezähler (2361/2362) | **Nicht** auf `EnergyID` gelegt und nicht als kumulierte elektrische Messvariable übernommen |
| Thermischer Cloud-Energiezähler (2363/2364) | Separater, prüfpflichtiger thermischer Diagnosewert (`KTEnergieThermischKumuliert`), kein elektrischer Energiezähler |

Der obere Puffersensor wurde **für die untersuchte Anlage** durch den Nutzer zugeordnet. Diese anlagenspezifische Zuordnung darf nicht ungeprüft auf andere Kronoterm-Installationen übertragen werden. Wärmepumpen-Eintritt und -Austritt sind ausdrücklich **keine** Messwerte des separat gemischten Heizkreises.

## 2. Architektur und Datenfluss

```text
Kronoterm Cloud
  https://cloud.kronoterm.com/jsoncgi.php
      │ HTTP(S), lesende JSON-Seiten / lesende Verbrauchsabfrage
      ▼
WPHUB_KronotermClient
  ├─ Anmeldung: Basic → Web-Session als Fallback → bestätigter Menu-Handshake
  ├─ Snapshot: menu / info / main + optionale Seiten
  └─ Tagesverbrauch: separater Lese-POST
      │
      ├─ WPHUB_KronotermDataMapper    → feste WP-Messwerte / Status
      ├─ WPHUB_KronotermBufferMapper  → optionale zusätzliche Puffersensoren
      ├─ WPHUB_KronotermEnergyMapper  → informative Tagesenergie
      └─ WPHUB_KronotermMeasurementCatalog → vollständiges bereinigtes Inventar
                                              │
                                              ▼
                                     WPHUB_KronotermExtraCatalog
                                       ├─ sichere Dublettenfilterung
                                       ├─ Zuordnungen / Beschreibungen
                                       ├─ Aktivierung / Archivierung
                                       └─ WPHUB_KronotermModbusProfiles
                                              │
                                              ▼
                                   Symcon-Variablen / Profile / Archiv
                                              │
                                              ▼
                               WPHUB_GetFunctions() (Vertrag 1.15)
```

### Komponenten

| Datei | Aufgabe |
|---|---|
| `WPHub/module.php` | Symcon-Lebenszyklus, Herstellerumschaltung, Formulardialog, Abruf, Variablenpflege, Archiv, Verbundvertrag |
| `WPHub/libs/KronotermClient.php` | Anmeldung, Sitzung, Seitenabrufe, optionale Daten, lesende Verbrauchsabfrage |
| `WPHub/libs/KronotermDataMapper.php` | Plausibilisierte feste Messpunkte und Zustände; numerische Konvertierung |
| `WPHub/libs/KronotermBufferMapper.php` | Konfiguration und Auswertung zusätzlicher Pufferfühler |
| `WPHub/libs/KronotermEnergyMapper.php` | Elektrische Tagesenergiekomponenten, nur zu Informationszwecken |
| `WPHub/libs/KronotermMeasurementCatalog.php` | Nicht sensitive Cloud-Felder, Status `mapped/buffer/configuration/unused`, Zeitstempel |
| `WPHub/libs/KronotermExtraCatalog.php` | Zusatztabelle, konservative Duplikatbereinigung, aktive Zusatzwerte |
| `WPHub/libs/KronotermModbusProfiles.php` | Registerwerte, Skalierungen, Profile, Code- und Bitfeldtexte |
| `WPHub/libs/KronotermModbusDefinitions.json` | Dokumentierte BMS-Registerdefinitionen |
| `WPHub/libs/KronotermFieldDescriptions.json` | Beschreibung und Evidenz für Cloud-Datenpunkte |
| `WPHub/libs/KronotermVerifiedAliases.json` | Gesicherte registerbezogene Cloud-Entsprechungen |
| `WPHub/form.json`, `WPHub/locale.json`, `.tools/locale-de.json` | Konfigurationsoberfläche und Übersetzungen |

## 3. Anmeldung und Aktualisierung

`WPHUB_KronotermClient` versucht zunächst Basic-Authentifizierung. Bei Misserfolg erfolgt die Web-Session-Anmeldung; erst ein gültiger JSON-`menu`-Handshake bestätigt den Zugriff. Nach abgelaufener Sitzung wird **einmal** neu authentifiziert. Cookies werden während der Laufzeit in einer temporären Datei gehalten und beim Zerstören des Clients entfernt.

**Abrufseiten:** `menu`, `info`, `main`, außerdem optional `shortcuts`, `system_data`, `main_settings`, `reservoir`, `loop1` bis `loop4`, `dhw` und `pool`. Bei eindeutig als deaktiviert erkannten Kreisen/Warmwasser wird die entsprechende Seite übersprungen. Unbekannte Installationseigenschaften bleiben unbekannt. Fehler optionaler Seiten unterbrechen einen erfolgreichen Hauptabruf nicht.

Die reguläre Aktualisierung erfolgt über den WPHub-Timer; für Kronoterm wird ein Mindestintervall von **300 Sekunden** erzwungen. Die gesonderte Tagesenergie-Abfrage verwendet `KR_EnergyIntervalMinutes` (effektiv 15–1440 Minuten) beziehungsweise einen Tageswechsel. Die Verbrauchsabfrage verwendet aus API-Gründen einen **POST als Leseanforderung**; dies ist kein Steuerbefehl.

`KR_LastSuccessTs` und für die separate Energieabfrage `KR_EnergyLastSuccessDay` und Statusinformationen dienen der Frische-/Fehleranzeige. Bei einem Cloud-Ausfall werden die Geräte als nicht erreichbar markiert; vorhandene Historienwerte werden nicht gelöscht. Externe zusätzliche Pufferfühler können weiterhin aktualisiert werden.

**Zugangsdaten:** `KR_Username` und `KR_Password` sind Symcon-Instanzproperties; sie werden für erneute Anmeldungen benötigt. Keine Zugangsdaten in Git-Dokumentation oder Inventar exportieren. Symcon-Backups entsprechend schützen.

## 4. Messwerte, Normalisierung und Qualität

Der feste Mapper akzeptiert numerische PHP-Werte und Zahlenstrings, darunter `33.3 °C` und Dezimalkomma. Gültige Werte werden als Symcon-Float, Integer oder Boolean angelegt. Messbereiche und Enumerationen werden vor Übernahme plausibilisiert; unbrauchbare Werte werden ausgelassen und nicht als Nullwert erfunden.

Beispiele:

| Cloud-Eintrag | Symcon-Ident | Hinweise |
|---|---|---|
| `main.ModbusReg[2101]` | `KTWPEintritt` | WP-Rücklauf, Float °C |
| `main.ModbusReg[2104]` | `KTWPAustritt` | WP-Vorlauf, Float °C |
| `main.ModbusReg[2103]` | `Aussentemperatur` | Float °C |
| `info.TemperaturesAndConfig.reservoir_temp` | `Puffertemperatur` | Oberer Puffersensor dieser Anlage, Float °C |
| `main.ModbusReg[2001]` | `KTBetriebsfunktion`, `BetriebsartNorm` | Roher Betriebsfunktionscode und normalisierter Verbundstatus |
| `main.ModbusReg[2006]` | `Fehlerstatus` | 0 kein Fehler, 1 Warnung, 2 Fehler, **3 Benachrichtigung** |
| `main.ModbusReg[2007]` | `KTBetriebsrichtung` | Dokumentierte Heiz-/Kühlrichtung |
| `main.ModbusReg[2011]` | `Abtaubetrieb` | Boolean |
| `main.ModbusReg[2327]` | `WPAuslastung` | Prozent |
| `main.ModbusReg[2371]`, `[2372]` | `KTCOP`, `KTSCOP` | Plausibilisierte Verhältniswerte |
| `main.ModbusReg[2090]`, `[2091]` | Verdichterbetriebsstunden | Integer |
| `main.ModbusReg[2028]` | Kreislaufpumpenstatus | Einzelbits, keine willkürliche Enum-Interpretation |

Einrichtungsspezifische Heizkreise (`loop1` bis `loop4`), Warmwasser und Pool werden nur berücksichtigt, soweit der entsprechende Cloud-/Reglerstatus dies erlaubt. Die Cloud-Felder dürfen nicht automatisch als Messstellen des separat geregelten Heizkreises umgedeutet werden.

### Tagesenergie – ausdrücklich kein Gesamtzähler

Der `consumption`-Endpunkt liefert, sofern verfügbar, Tagesverläufe für `CompHeating`, `CompTapWater`, `CPLoops`, `CPAddSource`. Je Komponente wird der **letzte Tages-Verlaufswert** verwendet, nicht die Summe aller Verlaufspunkte. `EnergieGesamtHeute` wird nur ausgegeben, wenn alle vier Komponenten valide sind; fehlende Werte gelten nicht als Null. Diese Werte sind **nicht** die Datenquelle für `EnergyID`.

### NRG-Stack-Verbundvertrag

`WPHUB_GetFunctions()` bleibt bei `contractVersion = 1.15`. Für Kronoterm gilt:

- `PowerID` = ID der `Ext_PowerVariable`; `EnergyID` = ID der `Ext_EnergyVariable`.
- `mainInletTempID` → `KTWPEintritt`, `mainOutletTempID` → `KTWPAustritt`, `bufferTempID` → `Puffertemperatur`.
- `outsideTempID` → `Aussentemperatur`; `outdoorTemperatureID` bleibt als veralteter Alias.
- `operatingModeNormID` → `BetriebsartNorm`, einschließlich `-1 = unbekannt`.
- `reachable`, `lastSeenAt`, `pollInterval`, `managedBy` bleiben Teil des Vertrags.

Die Auswahl einer externen Energiequelle ersetzt **keine Einheitenprüfung**: Leistung muss in W und kumulierte Energie in kWh vorliegen.

## 5. Pufferspeicher und Installationsinventar

`KR_BufferLayout` unterstützt `none`, `heating`, `cooling`, `separate`, `combined` und `auto`. Bei fehlendem eindeutigen Nachweis leitet `auto` keine physische Speicherbestückung ab. Für die vier optionalen Positionen `HeatTop`, `HeatBottom`, `CoolTop`, `CoolBottom` können Quelltyp `off`, `cloud` oder `external`, Cloud-Pfad beziehungsweise externe Symcon-Variablen-ID gewählt werden.

Die **feste** obere Puffertemperatur wird bereits aus `info.TemperaturesAndConfig.reservoir_temp` eingelesen. Dieser Wert wird nicht zusätzlich über den allgemeinen Sensor-Selektor benötigt. Der optionale Puffermapper unterdrückt daher doppelte obere Puffervariablen. `circle_calc_temp` und Sollwerte dürfen nicht als physische Sensoren verwendet werden.

## 6. Cloud-Dateninventar und Zusatzvariablen

### Inventarisierung

Nach erfolgreichem Abruf wird aus empfangenen JSON-Seiten ein bereinigtes Inventar erzeugt (`KR_CloudCatalog`). Statt roher API-Antworten werden nur nicht sensitive Einzelwerte gespeichert. Passwort-, Token-, Session-, Benutzer-, Seriennummern- und andere sensible Daten werden herausgefiltert. Obergrenze: **6000 Einträge** pro Bericht.

Jeder Eintrag erhält stabilen JSON-Pfad – bei `ModbusReg` mit der **Registeradresse**, nicht der Arrayposition –, Wert, Einheit/Typ, Herkunftsstatus und Empfangszeit. Der Report unterscheidet `mapped`, `buffer`, `configuration`, `unused` und kennzeichnet gerade nicht abgefragte bzw. ausgefallene Seiten. Der Bestand ist per `WPHUB_GetKronotermCloudInventory($instanceID)` als JSON abfragbar.

### Filterung

Die Zusatzwerteliste zeigt nur noch **ungenutzte** Werte. Wiederholte komplette API-Blöcke werden nur dann unterdrückt, wenn Feldmenge, Typ, Einheit **und sämtliche Werte** identisch sind; Blöcke mit weniger als drei unabhängigen Feldern sind kein hinreichender Dublettennachweis. Gleiche Einzelzahlen sind **kein** Beweis für denselben Sensor. Dokumentierte Register-/Cloud-Aliasse werden nur bei überprüfter numerischer Übereinstimmung berücksichtigt.

Im analysierten Export waren **891** Rohfelder enthalten; **357** wiederholte Felder wurden anhand vollständiger Blöcke ausgefiltert, **484** blieben als derzeit nicht zugeordnete Kandidaten übrig. Die aktuellen Zahlen sind vom jeweils empfangenen Snapshot und der konfigurierten Anlage abhängig.

### Zusätzliche Werte (Konfigurationsliste `KR_ExtraValues`)

| Spalte | Bedeutung |
|---|---|
| `Name` / `Path` | Cloud-Feldname und stabiler Datenpfad |
| `Purpose` | Feldzweck; fachliche Bedeutung nur, soweit aus Quelle belegt |
| `Evidence` | Kronoterm-BMS-Register und Seite oder explizit eingeschränkte Cloud-Namensableitung |
| `Unit` | Auswählbare Einheit, bei dokumentierten Registern automatische Vorgabe |
| `Active` | Aktivierung als typisierte Symcon-Instanzvariable |
| `Archive` | Pro Variable Archivierung aktivieren, nur bei aktivem Wert |
| `LastValue` | Letzter bekannter Wert bzw. Klartextstatus |
| `LastReceived` | Letzter tatsächlicher Empfangszeitpunkt |

Die Konfiguration wird als JSON-Property `KR_ExtraValues` gespeichert. Aktivierte Felder werden nur bei **frischem** Abruf aktualisiert; das Übernehmen eines Formulars schreibt nicht fälschlich zwischengespeicherte Altwerte als aktuelle Messung.

Die Variable erhält einen stabilen Ident `KRExtra` + erste 20 hexadezimale Zeichen aus SHA-256 des Cloud-Pfads. Bereits vorhandene Variablen bleiben bei Deaktivierung erhalten; sie werden **ausgeblendet**, die weitere Wertaktualisierung und Archivierung werden beendet. Dadurch bleiben IDs und Archivhistorien erhalten. Die Archivumschaltung geschieht über Symcons `AC_GetLoggingStatus` / `AC_SetLoggingStatus` mit anschließendem `IPS_ApplyChanges` der Archivinstanz. Die lesbare Begleitvariable eines Bitfelds ist nicht separat archiviert; die Checkbox betrifft den Original-Rohwert.

### Bedeutung und Nachweisgrad

Dokumentierte Modbus-Register erhalten Beschreibung und Quellenverweis aus `KronotermModbusDefinitions.json`. Für nicht offiziell dokumentierte Cloud-Pfade wird die Bezeichnung aus der API **nicht als bestätigte physische Zuordnung ausgegeben**. Wenn Codierung oder Zweck unbekannt sind, wird dies ausdrücklich angezeigt. Beispiel: `info.InfoData.C1_thermostat_fw` hat im Export den Wert `0`; eine offizielle Definition dieses Feldes wurde nicht nachgewiesen.

## 7. Automatische Variablenprofile (v4/v5)

Die Profillogik basiert auf dem Kronoterm-BMS-Handbuch **17-20-4023-04**. Die mitgelieferte Tabelle umfasst **168 eindeutig definierte Register**, davon 79 Mengen-/Messgrößen, 51 Einzelcodefelder, 30 Bitfelder, 6 Rohwörter und 2 Verhältnisgrößen. Folgende Regeln gelten:

1. Dokumentierte Einheit und Skalierungsfaktor werden automatisch berücksichtigt; eine explizit gewählte Einheit hat in der Zusatztabelle Vorrang.
2. Bereits physikalisch formatierte Werte wie `33.3 °C` dürfen **nicht** nochmals skaliert werden. Rohe Werte wie `374` können registerabhängig mit `0,1` zu `37,4 °C` konvertiert werden.
3. Statuscodes (ein einziges gültiges Enum je Registerwert) erhalten textuelle Profilassoziationen. Dazu gehören auch bereits **fest zugeordnete** Integer-Statusvariablen (`KTBetriebsfunktion`, `Fehlerstatus`, `KTBetriebsrichtung`).
4. **Bitfelder** bleiben numerisch, weil mehrere Bits gleichzeitig gesetzt sein können. Bei aktivierter zusätzlicher Bitfeldvariable entsteht eine Begleitvariable `<Ident>Bedeutung` mit Text für **alle** dokumentierten aktiven Bits. Undokumentierte aktive Bits erscheinen als „Bedeutung nicht dokumentiert“.
5. Rohwörter für High-/Low-Anteile zusammengesetzter Zähler werden **nicht** einzeln als kWh-Zähler beschriftet.
6. Profile nutzen modulbezogene Namen `WPHUB.KRMB.R<Register>.<Hash>`; globale NRG-Standardprofile werden nicht überschrieben.

**Beispielcodes laut BMS-Dokumentation:**

| Register | Code | Bedeutung |
|---|---:|---|
| 2001 | 5 | Standby |
| 2001 | 0 | Heizen |
| 2006 | 0 | Kein Fehler |
| 2006 | 1 | Warnung |
| 2006 | 2 | Fehler |
| 2006 | 3 | Benachrichtigung |
| 2007 | 0 | Kühlen |
| 2007 | 1 | Heizen |
| 2007 | 2 | Heizen und Kühlen aus |

Beispielbitfeld `main.ModbusReg[2117]`: dokumentierte Warn-Bits werden über `KronotermModbusDefinitions.json` in Einzeltexte umgesetzt. Mehrere aktive Bits werden gemeinsam angezeigt; ein nicht dokumentiertes Bit bekommt keinen erfundenen Fehlertext.

## 8. Anlagenkonfiguration und Betrieb

Die zusätzliche Kronoterm-Konfiguration liegt in der bestehenden WPHub-Instanz:

- `KR_Username`, `KR_Password`: Cloud-Zugangsdaten.
- `KR_EnergyIntervalMinutes`: getrenntes Abfrageintervall für informative Tagesenergie.
- `KR_ExtraValues`: aktivierte und optional archivierte Zusatzwerte.
- `KR_BufferLayout`, `KR_HeatTop*`, `KR_HeatBottom*`, `KR_CoolTop*`, `KR_CoolBottom*`: Pufferspeicher-Konfiguration.
- `Ext_PowerVariable`, `Ext_EnergyVariable`: **einzige** Quellen für elektrische Momentanleistung und kumulierte elektrische Energie.

Kronoterm-spezifisch werden die allgemeinen externen Eingabefelder für WP-Eintritt, WP-Austritt und den bestätigten oberen Puffer nicht erneut angeboten; Panasonic/Vaillant behalten ihr bestehendes Verhalten. Kronoterm erhält zusätzlich ein Diagnose-/Inventarpanel und den Bereich für nicht zugeordnete Cloud-Werte.

## 9. Fehlerbehandlung und Grenzen

- Externe Cloud-Verfügbarkeit ist Voraussetzung für aktuelle Kronoterm-Werte. Wiederanmeldung erfolgt kontrolliert; optionale Seiten dürfen ausfallen.
- Unbekannte Installationsausstattung wird nicht aus fehlenden Antworten als „nicht vorhanden“ abgeleitet.
- Ein Cloud-Feld mit passendem Namen ist noch kein Nachweis für seinen physischen Messpunkt.
- Die Duplikatbereinigung ist **konservativ** und snapshotabhängig: Sie beansprucht nicht, sämtliche semantischen Duplikate unabhängig von Messwerten zu erkennen.
- Die Inhalte von `KronotermFieldDescriptions.json` enthalten neben offiziell dokumentierten Registern auch Feldnamen-basierte Beschreibungen. Im Dialog wird der jeweilige Nachweisgrad benannt.
- Für die produktive Anlage sind insbesondere Einheiten, Messwertfrische und kumulative Zähler gegenüber realen Messgeräten zu verifizieren.
- Es ist kein Automatismus enthalten, der die Wärmepumpenregelung oder die separat geregelten Heizkreise steuert.

## 10. Verifikation und Teststand

**Kumulativer Patch:** auf Basis der vier unveränderten Ausgangsdateien aus dem gelieferten Original-Iststand erstellt; Zwischenstände `v2` → `v3 korrigiert` → `v4` → `v5` wurden nacheinander angewendet. Der Diff wurde über **Original → aktueller Stand** erzeugt, nicht durch Aneinanderhängen der Einzelpatches.

**Primäre Regressionstests:**

```powershell
php -l WPHub/module.php
php tests/test_kronoterm_v3.php
php tests/test_kronoterm_v4.php
php tests/test_kronoterm_v5.php
```

Die v5-Testdatei enthält **263 erfolgreich durchlaufende Prüfungen** einschließlich der v3-/v4-Regressionsfälle. Die älteren `.tools/test-kronoterm-mapper.php` und `.tools/test-kronoterm-cloud-inventory.php` stammen aus v2 und prüfen teilweise die **später ausdrücklich abgeschaffte** automatische Übernahme des elektrischen Cloud-Gesamtzählers; diese Altprüfungen sind für den v5-Stand in diesem Punkt nicht mehr gültig. Weitere `.tools`-Tests für Client, Energie und Puffer bestehen offline.

Die vollständige Laufzeitprüfung in deiner eigenen Symcon3-Instanz ist von der Offline-Prüfung zu unterscheiden. Verwendete Bestandsinstanz während der Entwicklung: **20198**. Variable-IDs und Archivhistorien werden bei aktualisierender Installation nicht absichtlich verändert.

## 11. Anwendung des Gesamtpatches und Rückwärtskompatibilität

Der Gesamtpatch **ist ausschließlich für die Originalbasis** des WPHub von DG65 bestimmt, die den ausgelieferten v2-Ausgangsdateien entspricht. Er ersetzt die nacheinander gelieferten Teilpatches. Auf einem bereits bis v5 aktualisierten Fork darf er **nicht erneut angewendet** werden.

1. In einem separaten, unveränderten Klon der betreffenden Originalversion den vorgesehenen Entwicklungsbranch erstellen.
2. Gesamtpatch in das Rechner-Dateisystem herunterladen.
3. Mit `git apply --check --ignore-whitespace <Patchdatei>` prüfen.
4. Mit `git apply --ignore-whitespace <Patchdatei>` anwenden.
5. `php tests/test_kronoterm_v5.php` und PHP-/JSON-Syntaxprüfungen ausführen.
6. Erst nach erfolgreichen Tests committen und bei Bedarf auf Gitea pushen.

Keine produktive Instanz oder Originaldatei muss für diesen Vergleich zurückgesetzt werden. Der Diff enthält die Kronoterm-Erweiterung, alle zugehörigen Dateien und diese technische Dokumentation.

## 12. Quellen und Nachvollziehbarkeit

1. **DG65/NRGWPHub** – Originalbibliothek und NRG-Stack-Verbundvertrag: <https://github.com/DG65/NRGWPHub>.
2. **Kronoterm** – *BMS system – Installation and operating manual*, Dokument 17-20-4023-04 (im Projekt als PDF bereitgestellt), Registertabellen und Code-/Bitdefinitionen.
3. **Favio25/Kronoterm-homeassistant** – öffentlich zugängliche API-Strukturreferenz: <https://github.com/Favio25/Kronoterm-homeassistant> (als Informationsquelle, keine Python-Implementierung übernommen).
4. **Projektinterner Cloud-Snapshot** – 891 Feldpfade vom November 2026; dokumentierter Register- und Wertevergleich.
5. **Projektpatches** – ausgeliefert: v2-Gesamtpatch, v3 korrigiert, v4 Profile und v5 Status/Beschreibungen; automatisiert auf derselben Originalbasis rekonstruiert.
