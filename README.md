# WPHub — Wärmepumpen-Cloud-Anbindung für IP-Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.11.2-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Übersicht

WPHub verbindet IP-Symcon mit Wärmepumpen-Herstellerclouds und stellt gefundene Geräte dem NRG-Stack-Verbund (z. B. EMS) über den `WPHUB_GetFunctions()`-Vertrag (`Type=>'heatpump'`) zur Verfügung — konsistent zu [HeishaMon](https://github.com/DG65/NRGHeishaMon), das dieselbe Vertragsform für die lokale/MQTT-Anbindung von Panasonic-Wärmepumpen mit HeishaMon-Platine liefert.

**Unterstützte Hersteller:** Eine Property „Hersteller" wählt je Instanz zwischen **Panasonic Comfort Cloud** (Aquarea) und **Vaillant myVAILLANT** (aroTHERM/aroTHERM plus) — als Cloud-Alternative zu HeishaMon für Nutzer ohne HeishaMon-Platine. Weitere Herstellerclouds (Mitsubishi MELCloud, Viessmann, Stiebel Eltron ISG, ...) folgen später über die Community, sobald sich Nutzer mit passender Hardware zum Testen finden — anders als bei Modbus-Zählern (MeterHub) hat praktisch jeder Herstellercloud-Anbieter ein eigenes, meist undokumentiertes Auth-/API-Schema, daher "ein Hersteller nach dem anderen" statt eines gemeinsamen Treibers.

## Status

Seit mehreren Builds live an Dietmars echtem Panasonic-Konto (Aquarea, Standardadapter STD_ADP-TAW1) verifiziert, aktuell 0.10.0:

- **Anmeldung** an der Panasonic Comfort Cloud (Auth0/PKCE-Handshake wie die offizielle App; Konto wie in der App). Das Passwort dient nur der einmaligen Anmeldung und wird danach automatisch geleert — gespeichert bleibt nur der Zugangsschlüssel (Hinweis: IP-Symcon verschlüsselt Attribute nicht at rest). Konten mit Zwei-Faktor-Authentifizierung werden noch nicht unterstützt.
- **Gerätesuche:** Aquarea-Wärmepumpen des Kontos werden automatisch gefunden; Klimageräte bindet WPHub bewusst nicht ein.
- **Reichhaltige Variablen je Wärmepumpe:** Erreichbarkeit, Betrieb (roh + normiertes Verbund-Enum), Außentemperatur, Warmwasser (Ist/Soll), Heizzonen (Ist/Soll), Flüsterbetrieb, Leistungsbetrieb, Eco-/Komfortmodus, Abtaubetrieb, Urlaubstimer, Notbetrieb, Fehleranzahl/-text sowie Tagesenergie (Heizen/Kühlen/Warmwasser/Gesamt, rein informativ).
- **Steuerhoheit je Gerät** (`managedBy`): koexistiert bewusst mit [HeishaMon](https://github.com/DG65/NRGHeishaMon) an derselben Anlage — je Wärmepumpe wählbar, wer tatsächlich regelt.
- **EMS-Vertrag:** `WPHUB_GetFunctions()` liefert je Gerät einen `Type=>'heatpump'`-Eintrag (contractVersion 1.15). `PowerID`/`EnergyID` bleiben 0, sofern nicht manuell mit einer externen Zähler-Variable (z. B. aus MeterHub) verknüpft — die Comfort Cloud selbst liefert keine Momentanleistung und keine kumulativen Zähler, und nach Verbund-Regel wird Energie nie aus Tageswerten hochgerechnet.
- **Vaillant myVAILLANT (neu, 0.10.0):** eigenes Formular-Panel „☁️ Vaillant myVAILLANT", eigener Login (Keycloak/OIDC + PKCE + ALTCHA-Proof-of-Work). Liefert Erreichbarkeit, Außentemperatur, Vorlauf-/Puffertemperatur, Warmwasser sowie einen Systemdruck-Wert. **Bewusst nur lesend** (keine Steuerbefehle) und **noch ungeprüft an einem echten Konto** — gebaut nach der aktiv gepflegten Referenz [signalkraft/myPyllant](https://github.com/signalkraft/myPyllant), aber ohne eigene Vaillant-Anlage zum Testen. Wer eine hat: Rückmeldungen sehr willkommen (Forumsthread, siehe unten).

Offen: 2FA-Unterstützung (Panasonic), Verifikation der Vaillant-Anbindung an einem echten Konto, Forum-Hinweis-Panel (folgt mit dem ersten Forumsthread). Siehe [CLAUDE.md](CLAUDE.md) für den vollständigen Übergabe-Kontext.

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65 (Marke, Lizenz, Formular-Stil, Vertragsversionierung und weitere verbindliche Konventionen sind intern dokumentiert).

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
