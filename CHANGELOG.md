# Changelog — NRG-Stack WPHub

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
