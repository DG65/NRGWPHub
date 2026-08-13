# Changelog — NRG-Stack WPHub

## 0.2.0 (Build 40) — 13.08.2026

- **`WPHUB_GetFunctions()` additiv erweitert (contractVersion 1.2 → 1.3).** Bisher gab der Vertrag nur die Basisfelder zurück (Betriebsart war z.B. nur als lose IPS-Variable erreichbar, nicht über den Vertrag) — Dashboard hätte für die geplante Anlagenschema-Visualisierung direkt auf Idents zugreifen müssen, was der Verbund-Konvention widerspricht. Neu: `outdoorTemperatureID`, `z1WaterTempID`, `z2WaterTempID`, `z1WaterTargetTempID`, `z2WaterTargetTempID`, `dhwTempID`, `dhwTargetTempID`, `quietModeID`, `ecoComfortModeID`, `holidayTimerID` — jeweils 0, wenn WPHub den Wert nicht liefert (z.B. Zone 2 nicht vorhanden). `z1WaterTempID`/`z2WaterTempID`/`dhwTempID` nutzen bewusst dieselben Feldnamen wie der mit HeishaMon abgestimmte gemeinsame `heatpump`-Vertragstyp (identisches Konzept: Zonen-/Warmwasser-Isttemperatur) — Dashboard liest denselben Feldnamen unabhängig vom liefernden Modul.

## 0.2.0 (Build 39) — 13.08.2026

- **Geklärt: `/deviceHistoryData` für dieses Konto gesperrt.** Am echten Konto getestet: 403 Code 4300 „Have no authority to the request" — dieselbe Rechte-Sperre, auf die frühere Untersuchungen der `/hphw`-Endpunkte schon gestoßen waren (`a2wOwnerFlg:false`), keine Frage des Content-Type-Headers. Der Transfer-Proxy-Weg (`/remote/v1/api/consumption`, seit Build 35 in Betrieb) bleibt damit der einzige funktionierende Zugang zu Verbrauchsdaten. Diagnosefunktion `ProbeDeviceHistory` wieder entfernt.

## 0.2.0 (Build 38) — 13.08.2026

- **Diagnose `ProbeDeviceHistory` (temporär).** Fund im bereits bestehenden, aktiv gepflegten IP-Symcon-Modul `demel42/IPSymconPanasonicComfortCloud`: Es nutzt für die Verbrauchshistorie den direkten Endpunkt `/deviceHistoryData` statt des Transfer-Proxys — ein Weg, den WPHub noch nie mit dem korrigierten Content-Type-Header getestet hat. `ProbeDeviceHistory` schreibt die rohe Antwort ins Systemprotokoll, um zu prüfen, ob dieser Weg reichhaltigere Daten liefert. Wird nach der Klärung wieder entfernt.

## 0.2.0 (Build 37) — 13.08.2026

- **Geklärt: keine erzeugte (thermische) Energie verfügbar.** Die Diagnose aus Build 36 wurde am echten Konto ausgewertet: Die Verbrauchsantwort enthält je Stunde nur `heat-/cool-/tankConsumption` (elektrisch) + Kosten + Außentemperatur, kein Feld für erzeugte Wärmeenergie. Für eine echte Wärmemengenmessung (und damit eine COP-Berechnung) fehlen Vor-/Rücklauftemperatur und Durchfluss — der Standardadapter liefert offenbar nur den elektrischen Verbrauch. Diagnosefunktion `ProbeConsumption` wieder entfernt.

## 0.2.0 (Build 36) — 13.08.2026

- **Diagnose `ProbeConsumption` (temporär).** Frage: Liefert die Cloud neben dem elektrischen Verbrauch (heat/cool/tankConsumption) auch die erzeugte thermische Energie (für eine COP-Berechnung)? Keine der drei geprüften Referenzimplementierungen wertet ein solches Feld aus — `ProbeConsumption` schreibt die rohe, unverarbeitete Verbrauchsantwort ins Systemprotokoll, um das direkt am echten Konto zu prüfen. Wird nach der Klärung wieder entfernt.

## 0.2.0 (Build 35) — 13.08.2026

- **Weitere Betriebsdaten aus dem Transfer-Statusabruf.** Neu: Eco-/Komfortmodus (die Eco-/Komfort-Knöpfe aus der App), Betriebsrichtung (Ruht/Umwälzpumpe/Warmwasser), Abtaubetrieb, Fehleranzahl und Fehlertext. Zusätzlich **Energieverbrauch des laufenden Tages** (Heizen/Kühlen/Warmwasser/Gesamt in kWh, neuer Endpunkt `/remote/v1/api/consumption` über denselben Transfer-Proxy) — rein informativ, **kein** Bestandteil des EMS-Vertrags (PowerID/EnergyID bleiben 0), da Tageswerte um Mitternacht auf 0 zurückspringen und damit kein kumulativer Zähler sind. Neues gemeinsames Profil `NRG.kWh`. Bewusst nicht angebunden: Wasserdruck, Bivalentpunkt, Anodenstatus — bei Dietmars Anlage (Standardadapter STD_ADP-TAW1) durchgehend 0, vermutlich für diese Hardware nicht unterstützt; sowie die statisch konfigurierten Eco-/Komfort-Sollwertgrenzen je Zone (Installateur-Einstellung, kein Live-Messwert). 11 neue Tests, 87 Prüfungen insgesamt grün.

## 0.2.0 (Build 34) — 13.08.2026

- **Steuerung ergänzt.** Flüsterbetrieb, Leistungsbetrieb, Urlaubstimer (nur Ein/Aus, kein Datumsbereich), Notbetrieb Warmwasser/Heizung sowie die Warmwasser- und Zonen-Solltemperatur lassen sich jetzt direkt in Symcon ändern (`RequestAction`) — nicht nur auslesen. Jeder Befehl geht über den gleichen Transfer-Proxy-Aufruf wie der Statusabruf (ein einzelnes Feld je Aufruf, nach dem Muster der Referenzimplementierungen). Die Zonen-Solltemperatur wählt automatisch das passende Feld (Heiz- oder Kühl-Sollwert) je nach aktueller Betriebsart. Bei einem fehlgeschlagenen Befehl bleibt die Variable auf dem letzten bestätigten Cloud-Stand, keine unbestätigten Werte in der Anzeige; eine Protokollzeile erklärt den Grund. **Bewusst nicht enthalten:** volle Betriebsart-Umschaltung (Heizen/Kühlen/Auto/Aus) — die verlangt laut Referenz den kompletten Zonen- und Speicherstatus im selben Aufruf und würde bei falscher Umsetzung riskieren, andere Zonen versehentlich ab- oder umzuschalten; ein echter Wochenplan sowie Urlaubszeiten mit Datumsbereich sind über die Cloud-API nach aktuellem Kenntnisstand nicht erreichbar (in keiner der geprüften Referenzimplementierungen vorhanden).

## 0.2.0 (Build 33) — 12.08.2026

- **Profil für Betriebsart ergänzt.** Die Variable zeigte bisher nur eine rohe Zahl. Neues Profil `WPHUB.Betriebsart` (Aus/Heizen/Kühlen/Auto Heizen/Auto Kühlen, nach `ExtendedOperationMode` der Referenzimplementierungen) macht den Wert lesbar.

## 0.2.0 (Build 32) — 12.08.2026

- **Reichhaltige Aquarea-Betriebsdaten.** Zusätzlich zu den bisherigen Basisdaten (Betriebsart, Warmwasser, Zonen-Sollwerte) liefert WPHub jetzt je Wärmepumpe: **Außentemperatur**, **Ist-Temperatur je Heizzone** (mit dem echten Zonennamen, z. B. „HK1"), **Flüsterbetrieb** (Aus/Stufe 1–3), **Leistungsbetrieb** (Aus/30/60/90 Minuten), **Urlaubstimer** sowie **Notbetrieb Warmwasser**/**Not-Heizbetrieb**. Datenquelle ist der Transfer-Proxy (`/remote/v1/app/common/transfer`, live mit Cache-Rückfall) — der bereits länger bekannte Weg, der bislang durchgehend mit „Missing required header parameter" (Code 4000) scheiterte. Der eigentliche Fehler: WPHub sendete `Content-Type: application/json;charset=utf-8`, während die POST-lastigen Routen offenbar strikt den exakten String `application/json` (ohne Charset-Zusatz) verlangen — gefunden durch Abgleich mit einer seit Redaktionsschluss aktiv gepflegten Drittanbieter-Implementierung. Der Zusatzabruf ist bewusst fehlertolerant: Schlägt er fehl, bleiben die betroffenen Variablen auf dem letzten bekannten Stand, der Rest der Aktualisierung läuft unbeeinflusst weiter. Die temporäre Diagnosefunktion `ProbeFull` wurde entfernt.

## 0.1.0 (Build 31) — 12.08.2026

- **Content-Type korrigiert (`;charset=utf-8` entfernt).** Breitere Recherche nach Referenzen aus anderen Smart-Home-Ökosystemen (Homey, openHAB, ioBroker) fand eine seit gestern aktiv gepflegte Homey-App (`mathieuChamois/com.panasonic.aquarea.community`), deren Client für exakt denselben Transfer-Proxy-Aufruf einen `Content-Type: application/json` **ohne** Charset-Zusatz sendet — WPHub hatte bisher `application/json;charset=utf-8`. Da POST-Routen bei API-Gateways oft strikt gegen den exakten Content-Type-String validieren (GET-Routen wie `device/group` dagegen meist nicht), ist das ein plausibler Kandidat für die bisherige „Missing required header parameter"-Meldung. `ProbeFull` testet die bestehenden vier Kandidaten jetzt mit dem korrigierten Header.

## 0.1.0 (Build 30) — 12.08.2026

- **ProbeFull: neuer Anlauf für die Aquarea-Reichdaten, diesmal nach Abgleich mit einer aktiv gepflegten Referenz.** Die Home-Assistant-Integration `cjaliaga/aioaquarea` (produktiv im Einsatz) zeigte zwei Dinge: (1) WPHub sendete bislang **keinen `Accept`-Header** — jetzt bei jedem Cloud-Aufruf global ergänzt (`accept: application/json; charset=utf-8`), ein möglicher Kandidat für die bisherige „Missing required header parameter"-Fehlermeldung. (2) Der Transfer-Proxy (`/remote/v1/app/common/transfer`) wird von der Referenz mit einer **einfacheren Body-Form** angesprochen als in Build 14 vermutet — nur `apiName`/`requestMethod`, **kein** `headerParam`. `ProbeFull` testet jetzt genau diese Form (live + cached) sowie `/hphw/deviceStatus` mit dem neuen Accept-Header. Rein diagnostisch, keine funktionale Änderung an den bestehenden, verifizierten Basisdaten.

## 0.1.0 (Build 27) — 12.08.2026

- **„Erreichbar" korrigiert.** Bisher basierte die Erreichbarkeit auf `connectionStatus == 1` — das war falsch: `connectionStatus:0` ist der **Normalzustand** (die Comfort-Cloud-App zeigt die Wärmepumpe nie als offline, und die Cloud liefert durchgehend aktuelle Werte). Die Variable stand dadurch dauerhaft fälschlich auf „nicht erreichbar". Jetzt gilt ein Gerät als erreichbar, sobald es in `device/group` mit aktuellen Daten erscheint; nur ein kompletter Cloud-Ausfall setzt es auf „nicht erreichbar". (Die Builds 22–26 dazwischen waren Diagnose-Zwischenstände auf der Suche nach dem Aquarea-Detail-Endpunkt `/hphw/deviceStatus`.)

## 0.1.0 (Build 21) — 11.08.2026

- **Aufräumen nach Datenquellen-Analyse.** Untersuchung, ob sich die reichhaltige Aquarea-Telemetrie (Außentemperatur, Vor-/Rücklauf, COP, Energie) anbinden lässt: Der frühere Consumer-Host `aquarea-smart.panasonic.com` existiert nicht mehr (keine DNS-Auflösung), und der überlebende Dienst `ac.smartcloud.panasonic.com` nutzt ein eigenes Login (nicht Panasonic ID/Auth0) — mit dem Comfort-Cloud-Token nicht erreichbar. Ergebnis: Über den Comfort-Cloud-Zugang bleibt es bei den Basisdaten aus `device/group` (Build 17). Die temporären Diagnosefunktionen wurden wieder entfernt; das Modul ist im verifizierten, sauberen Stand.

## 0.1.0 (Build 17) — 11.08.2026

- **Messwerte werden ausgelesen — direkt aus `device/group`.** Eine Diagnose (Zwischenstände Build 15/16) zeigte: Die A2W-Betriebsdaten liefert die Comfort Cloud bereits inline in der Geräteliste; der Transfer-Proxy (`/remote/v1/app/common/transfer`) und `/deviceStatus/{guid}` sind für Wärmepumpen gar nicht freigegeben (400/403). WPHub wertet die Werte jetzt direkt aus der Geräteantwort aus und legt je Wärmepumpe an: Erreichbar (aus `connectionStatus`), Betriebsart, Warmwasser Ist/Soll und -Betrieb (Speicher) sowie je Heizzone Solltemperatur und Betrieb. Werte bleiben als letzter bekannter Stand erhalten, wenn das Gerät offline ist. Der tote Transfer-Proxy-Code und die temporäre Diagnosefunktion wurden entfernt.

## 0.1.0 (Build 14) — 10.08.2026

- **Geräteliste lädt, A2W-Statusabruf repariert.** Nach der (in der offiziellen App bestätigten) Zustimmung liefert `device/group` die Wärmepumpe. Der anschließende Aquarea-Statusabruf über den Transfer-Proxy scheiterte aber mit „Missing required header parameter“ (Code 4000): Der Proxy verlangt im Body neben `apiName`/`requestMethod` eine `headerParam`-Liste. WPHub sendet sie jetzt exakt wie die offizielle App (Accept + Content-Type + Platzhalter) und hängt die Geräte-GUID roh an den `apiName`-Query.

## 0.1.0 (Build 13) — 10.08.2026

- **Zustimmung: Status-GET direkt vor dem PUT** (wie die offizielle App). Der PUT meldet zwar Erfolg (`{"result":0}`), aber die Geräteliste blieb bei 4103 — die App liest unmittelbar vor dem PUT auf derselben Sitzung einmal den Zustimmungsstatus; genau das ergänzt WPHub jetzt. **Bekannte Einschränkung:** Sollte die programmatische Zustimmung weiterhin nicht greifen, genügt der zuverlässige Weg — einmal die offizielle Comfort-Cloud-App öffnen, dort die neuen Bedingungen bestätigen, danach in WPHub „Anmelden und Wärmepumpen suchen“. Die Zustimmung ist ohnehin eine seltene, einmalige Aktion pro Bedingungs-Aktualisierung.

## 0.1.0 (Build 12) — 10.08.2026

- **Zustimmung wirkt jetzt (gleiche Sitzung weiternutzen).** Der Protokoll-Mitschnitt zeigte: Die Zustimmung wird serverseitig angenommen (PUT → `{"result":0}`), aber Build 10 hat danach das Token erneuert und sich neu angemeldet — und genau diese **frische** Sitzung kannte die eben erteilte Zustimmung nicht, weshalb die Geräteliste weiter 4103 meldete. Die offizielle App macht es anders: Nach der Zustimmung läuft **dieselbe** Sitzung direkt zur Geräteliste weiter, ohne Token-Wechsel. WPHub macht das jetzt genauso; die Sitzungserneuerung bleibt nur als Rückfall, falls die Zustimmung ausnahmsweise nicht sofort greift.

## 0.1.0 (Build 11) — 10.08.2026

- **Diagnose-Mitschnitt fürs Systemprotokoll:** Da die Zustimmung serverseitig erfolgreich ist, die Geräteliste aber weiter 4103 meldet, schreibt WPHub in diesem Fall einen Mitschnitt der letzten Cloud-Aufrufe (Pfad ohne Query, Statuscode, gekürzter Antwortkörper — keine Zugangsdaten, Token stehen nur in nicht protokollierten Headern) ins Systemprotokoll. Damit lässt sich die genaue Ursache eingrenzen, ohne im Debug-Fenster mitlesen zu müssen.

## 0.1.0 (Build 10) — 10.08.2026

- **Nach der Zustimmung frische Sitzung herstellen.** Die Bedingungen wurden akzeptiert (PUT erfolgreich, alle drei Typen), aber die Geräteliste blieb bei 4103. Grund (aus dem App-Verhalten abgeleitet): Die offizielle App verwirft nach 4103 ihre Sitzung und meldet sich neu an — erst mit einer frischen Sitzung wird die Zustimmung serverseitig für die Geräte-API wirksam. WPHub erneuert nach erfolgreicher Zustimmung jetzt das Zugangstoken und die App-Anmeldung (neue clientId) und lädt erst dann die Geräteliste.

## 0.1.0 (Build 9) — 10.08.2026

- **Zustimmung: Dokumente je Typ abrufen (wie die App).** Der ungefilterte Dokumenten-Abruf lieferte 7 Einträge (alle Typen/Sprachvarianten gemischt, teils ohne Versionsnummer) — die offizielle App fragt statt dessen **je Typ (1/2/3) einzeln** ab und bestätigt genau die eine Version pro Typ. Genau das macht WPHub jetzt auch: Einträge ohne Versionsnummer werden übersprungen, ein einzelner nicht zutreffender Typ (z. B. Servicevertrag, nur Türkei) wird toleriert. Die rohe Dokumenten-Antwort und der exakte PUT-Body landen jetzt im Instanz-Debug, damit ein etwaiger Rest-Fehler eindeutig sichtbar ist.

## 0.1.0 (Build 8) — 10.08.2026

- **Zustimmung endgültig korrekt (echtes App-Schema):** Das Body-Format der v2-Zustimmung ist in keiner Community-Bibliothek gelöst; ich habe es aus der offiziellen Android-App (aktuelle Version, dekompiliert) direkt abgeleitet. Der richtige Ablauf: erst `GET /auth/v2/agreement/documents?language=0&includeContent=0` (liefert je Dokument Typ **und Versionsnummer**), dann `PUT /auth/v2/agreement/status` mit `{"agreementList":[{"type":<int>,"version":"<str>"}]}` — also die konkrete Version bestätigen, nicht ein generischer „akzeptiert“-Status. Genau das Fehlen der Version war der Grund für „Missing required body parameter“. Das ganze Raten aus Build 6/7 ist durch diesen einen belegten Aufruf ersetzt.

## 0.1.0 (Build 7) — 10.08.2026

- **Zustimmung v2 selbstlernend:** Build 6 fand die reale Route (`/auth/v2/agreement/status/`), deren Body-Schema aber nirgends dokumentiert ist (400, Code 4002). Die Schaltfläche liest jetzt zuerst den v2-Zustimmungsstatus (rein lesend — die Antwort verrät die Feldnamen), spiegelt daraus den Bestätigungs-Body und probiert erst danach wenige statische Kandidaten; weitergeschaltet wird nur bei nachweislich wirkungslosen Antworten (4002/Route unbekannt). Scheitert alles, enthält die Fehlermeldung die rohe v2-Statusantwort zur Analyse.

## 0.1.0 (Build 6) — 10.08.2026

- **Zustimmungs-Endpunkt repariert (Kandidatenleiter):** Der historisch dokumentierte Weg `PUT /auth/agreement/status/` (App-Ära 1.20) existiert an der heutigen API nicht mehr — AWS antwortet mit 403 „Missing Authentication Token“ (= Route unbekannt). Da der aktuelle Accept-Pfad der offiziellen App nirgends öffentlich mitgeschnitten ist, probiert die Schaltfläche jetzt eine kleine Kandidatenleiter (ohne Trailing-Slash, Typ-ID im Pfad, POST, v2-Pfad) — der nächste Versuch fährt nur, wenn der vorige eindeutig „Route unbekannt“ war und damit nichts bewirkt hat; bei Erfolg oder echtem Fehler stoppt die Leiter sofort. Das Debug-Protokoll der Instanz vermerkt, welche Variante funktioniert hat. Führt keine Variante zum Ziel, verweist die Meldung auf die Bestätigung in der offiziellen App.

## 0.1.0 (Build 5) — 10.08.2026

- **Zustimmung zu aktualisierten Panasonic-Bedingungen (Cloud-Fehlercode 4103):** Der dritte Live-Test bestand die komplette Anmeldung, scheiterte dann aber an „Terms and/or Policies have been updated“ — Panasonic verlangt nach Aktualisierungen von Nutzungsbedingungen/Datenschutzerklärung eine erneute Zustimmung des Kontos. Neu: Schaltfläche „📜 Aktualisierte Bedingungen akzeptieren“ im Formular (prüft beide Zustimmungstypen und bestätigt nur Offenes), eigener Instanzstatus 202 „Zustimmung erforderlich“ mit Protokollhinweis (einmalig, nicht je Zyklus). Bewusste Entscheidung: WPHub stimmt **nie automatisch** zu — die Zustimmung ist eine Entscheidung des Kontoinhabers, alternativ genügt ein Öffnen der offiziellen App. Prüfstand um die 4103-Szenarien erweitert (55 Prüfungen).

## 0.1.0 (Build 4) — 10.08.2026

- **App-Version wird jetzt automatisch ermittelt:** Der zweite Live-Test scheiterte am Cloud-Fehlercode 4106 — die im Code hinterlegte App-Version 1.21.0 war der Cloud zu alt (aktuell ist 4.4.0). WPHub ermittelt die aktuelle Versionsnummer der offiziellen App jetzt bei Bedarf selbst (Play Store, ersatzweise AppBrain), merkt sie sich und wiederholt den abgelehnten Aufruf genau einmal. Das Formularfeld „App-Version“ ist nur noch Notnagel (leer = automatisch); eine automatisch ermittelte Version hat Vorrang. Prüfstand um die 4106-Szenarien erweitert (49 Prüfungen).

## 0.1.0 (Build 3) — 10.08.2026

- **Behoben:** Die Anmeldung brach mit einem Fatal Error ab (`resetCookies()` aufgerufen, aber nicht definiert) — beim ersten Live-Test an Dietmars Installation gefunden. Der Prüfstand prüft jetzt zusätzlich statisch, dass jeder `$this->…()`-Aufruf in Modul und Cloud-Client tatsächlich definiert ist (41 Prüfungen), damit diese Fehlerklasse nicht wieder an `php -l` und den Netz-los-Tests vorbeikommt.

## 0.1.0 (Build 2) — 10.08.2026

Erster funktionsfähiger Stand (Panasonic Comfort Cloud), noch nicht am echten Konto verifiziert:

- **Anmeldung an der Comfort Cloud** über den Auth0/PKCE-Handshake der offiziellen App (`WPHUB_Login`). Passwort nur für die einmalige Anmeldung, danach automatisch geleert; gespeichert bleibt nur das Token-Bündel (Attribut, mit automatischer Erneuerung über das Refresh-Token). Konten mit Zwei-Faktor-Authentifizierung werden noch nicht unterstützt und bekommen eine klare Fehlermeldung.
- **Automatische Gerätesuche:** Aquarea-Wärmepumpen des Kontos werden erkannt (Klimageräte bewusst übersprungen) und zyklisch über die A2W-Schnittstelle abgefragt (live, mit Rückfall auf den Cloud-Zwischenstand).
- **Variablen je Wärmepumpe:** Erreichbarkeit, Betrieb, Außentemperatur, Warmwasser Ist/Soll, Heizzonen Ist/Soll (gemeinsames Profil `NRG.Celsius`, wird nur angelegt, wenn es fehlt). Der Cloud-Marker 126 („kein Messwert“) wird herausgefiltert; bei Cloud-Ausfall bleiben Variablen und Historie unangetastet, nur die Erreichbarkeit fällt auf „nein“.
- **EMS-Vertrag:** `WPHUB_GetFunctions()` liefert je Gerät einen `Type=>'heatpump'`-Eintrag in HeishaMon-Form (contractVersion 1.2). `PowerID`/`EnergyID` bleiben 0, bis eine vertragstaugliche (kumulative) Energiequelle verifiziert ist — keine Hochrechnung aus Tageswerten.
- **Formular:** „🆕 Neu in Version“-Panel (pro Version ausblendbar), Anmelde-Schaltfläche mit Ergebnisanzeige, Sicherheits-Hinweis, App-Versionsfeld (für den Cloud-Fehlercode 4106 „App-Version zu alt“), Statuscode 201 „Anmeldung erforderlich“.
- **Prüfstand** `.tools/test-module.php` (39 Prüfungen, ohne Netzzugriff): Lebenszyklus/Status, Gerätesuche und Variablenpflege, Vertragsform, Client-Hilfsfunktionen (API-Schlüssel gegen die Python-Referenz abgeglichen).

## 0.1.0 (Build 1) — 10.08.2026

- Initiales Scaffold (von der EMS-Koordinationssitzung angelegt): library.json/module.json, Modul-Lebenszyklus, `GetFunctions()`-Gerüst, Formular mit Zugangsdaten-Panel, LICENSE (PolyForm Noncommercial 1.0.0).
