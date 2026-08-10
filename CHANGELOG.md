# Changelog — NRG-Stack WPHub

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
