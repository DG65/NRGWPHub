# Changelog — NRG-Stack WPHub

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
