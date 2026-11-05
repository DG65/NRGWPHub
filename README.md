🇬🇧 [English version](README.en.md)

# WPHub — Wärmepumpen-Cloud-Anbindung für Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.17.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Übersicht

WPHub verbindet Symcon mit Wärmepumpen-Herstellerclouds und stellt gefundene Geräte dem NRG-Stack-Verbund (z. B. EMS) über den `WPHUB_GetFunctions()`-Vertrag (`Type=>'heatpump'`) zur Verfügung — konsistent zu [HeishaMon](https://github.com/DG65/NRGHeishaMon), das dieselbe Vertragsform für die lokale/MQTT-Anbindung von Panasonic-Wärmepumpen mit HeishaMon-Platine liefert.

**Unterstützte Hersteller:** Eine Property „Hersteller" wählt je Instanz zwischen **Panasonic Comfort Cloud** (Aquarea) und **Vaillant myVAILLANT** (aroTHERM/aroTHERM plus) — als Cloud-Alternative zu HeishaMon für Nutzer ohne HeishaMon-Platine. Weitere Herstellerclouds (Mitsubishi MELCloud, Viessmann, ...) folgen später über die Community, sobald sich Nutzer mit passender Hardware zum Testen finden — anders als bei Modbus-Zählern (MeterHub) hat praktisch jeder Herstellercloud-Anbieter ein eigenes, meist undokumentiertes Auth-/API-Schema, daher „ein Hersteller nach dem anderen" statt eines gemeinsamen Treibers. Lokale Anbindungen ohne Cloud: [WPModbusHub](https://github.com/DG65/NRGWPModbusHub) (Modbus), [HeishaMon](https://github.com/DG65/NRGHeishaMon) (MQTT).

## Status

Panasonic läuft seit mehreren Builds an einem echten Konto (Aquarea, Standardadapter STD_ADP-TAW1). Vaillant ist an zwei echten Anlagen von Testern bestätigt (tli-Regler und vrc700-Regler).

- **Anmeldung** an der Panasonic Comfort Cloud (Auth0/PKCE-Handshake wie die offizielle App; Konto wie in der App). Das Passwort dient nur der einmaligen Anmeldung und wird danach automatisch geleert — gespeichert bleibt nur der Zugangsschlüssel (Hinweis: Symcon verschlüsselt Attribute nicht at rest). Konten mit Zwei-Faktor-Authentifizierung werden noch nicht unterstützt.
- **Gerätesuche:** Aquarea-Wärmepumpen des Kontos werden automatisch gefunden; Klimageräte bindet WPHub bewusst nicht ein.
- **Reichhaltige Variablen je Wärmepumpe:** Erreichbarkeit, Betrieb (roh + normiertes Verbund-Enum), Außentemperatur, Warmwasser (Ist/Soll), Heizzonen (Ist/Soll), Flüsterbetrieb, Leistungsbetrieb, Eco-/Komfortmodus, Abtaubetrieb, Urlaubstimer, Notbetrieb, Fehleranzahl/-text sowie Tagesenergie (Heizen/Kühlen/Warmwasser/Gesamt, rein informativ).
- **Steuerhoheit je Gerät** (`managedBy`): koexistiert bewusst mit [HeishaMon](https://github.com/DG65/NRGHeishaMon) an derselben Anlage — je Wärmepumpe wählbar, wer tatsächlich regelt.
- **EMS-Vertrag:** `WPHUB_GetFunctions()` liefert je Gerät einen `Type=>'heatpump'`-Eintrag (contractVersion 1.15). `PowerID`/`EnergyID` bleiben 0, sofern nicht manuell mit einer externen Zähler-Variable (z. B. aus MeterHub) verknüpft — die Comfort Cloud selbst liefert keine Momentanleistung und keine kumulativen Zähler, und nach Verbund-Regel wird Energie nie aus Tageswerten hochgerechnet.
- **Vaillant myVAILLANT:** eigenes Formular-Panel „☁️ Vaillant myVAILLANT", eigener Login (Keycloak/OIDC + PKCE + ALTCHA-Proof-of-Work). Liefert Erreichbarkeit, Außentemperatur, Vorlauf-/Puffertemperatur, Warmwasser (Ist/Soll), Heizkreise 1 und 2, Raumtemperatur/-feuchte, Systemdruck, Betriebszustände sowie Tagesenergie (Strom, Wärmemenge, Umweltenergie) aus der Energie-Schnittstelle des Herstellers (nur alle vier Stunden abgefragt, das Kontingent des Herstellers ist begrenzt). **Bewusst nur lesend** (keine Steuerbefehle). Gebaut nach der aktiv gepflegten Referenz [signalkraft/myPyllant](https://github.com/signalkraft/myPyllant).

Offen: 2FA-Unterstützung (Panasonic), Steuerung bei Vaillant. Siehe [CLAUDE.md](CLAUDE.md) für den vollständigen Übergabe-Kontext.

## Sprachen

Das Modul ist zweisprachig (Deutsch/Englisch): Formular, Meldungen, Hinweise und die Namen neu angelegter Variablen folgen der Symcon-Sprache. Die Quelltexte sind englisch, die deutsche Fassung steht in `.tools/locale-de.json` (`php .tools/locale-bauen.php` schreibt daraus `WPHub/locale.json`). Bereits angelegte Variablen werden nicht umbenannt.

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65 (Marke, Lizenz, Formular-Stil, Vertragsversionierung und weitere verbindliche Konventionen sind intern dokumentiert).

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
