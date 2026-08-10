# Changelog — NRG-Stack WPHub

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
