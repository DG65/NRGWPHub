# WPHub — Wärmepumpen-Cloud-Anbindung für IP-Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.4.2-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Übersicht

WPHub verbindet IP-Symcon mit Wärmepumpen-Herstellerclouds und stellt gefundene Geräte dem NRG-Stack-Verbund (z. B. EMS) über den `WPHUB_GetFunctions()`-Vertrag (`Type=>'heatpump'`) zur Verfügung — konsistent zu [HeishaMon](https://github.com/DG65/NRGHeishaMon), das dieselbe Vertragsform für die lokale/MQTT-Anbindung von Panasonic-Wärmepumpen mit HeishaMon-Platine liefert.

**Start-Umfang:** Panasonic Comfort Cloud — als Cloud-Alternative zu HeishaMon für Nutzer ohne HeishaMon-Platine, testbar an der eigenen Anlage. Weitere Herstellerclouds (Mitsubishi MELCloud, Viessmann, Vaillant myVaillant, Stiebel Eltron ISG, ...) folgen später über die Community, sobald sich Nutzer mit passender Hardware zum Testen finden — anders als bei Modbus-Zählern (MeterHub) hat praktisch jeder Herstellercloud-Anbieter ein eigenes, meist undokumentiertes Auth-/API-Schema, daher "ein Hersteller nach dem anderen" statt eines gemeinsamen Treibers.

## Status

Erster funktionsfähiger Stand (0.1.0, 10.08.2026), noch nicht am echten Konto verifiziert:

- **Anmeldung** an der Panasonic Comfort Cloud (Auth0/PKCE-Handshake wie die offizielle App; Konto wie in der App). Das Passwort dient nur der einmaligen Anmeldung und wird danach automatisch geleert — gespeichert bleibt nur der Zugangsschlüssel (Hinweis: IP-Symcon verschlüsselt Attribute nicht at rest). Konten mit Zwei-Faktor-Authentifizierung werden noch nicht unterstützt.
- **Gerätesuche:** Aquarea-Wärmepumpen des Kontos werden automatisch gefunden; Klimageräte bindet WPHub bewusst nicht ein.
- **Variablen je Wärmepumpe:** Erreichbarkeit, Betrieb, Außentemperatur, Warmwasser (Ist/Soll), Heizzonen (Ist/Soll).
- **EMS-Vertrag:** `WPHUB_GetFunctions()` liefert je Gerät einen `Type=>'heatpump'`-Eintrag (contractVersion 1.2). `PowerID`/`EnergyID` sind derzeit 0 — die Comfort Cloud liefert keine Momentanleistung und keine kumulativen Zähler, und nach Verbund-Regel wird Energie nie aus Tageswerten hochgerechnet.

Offen: Verifikation am echten Konto, Verbrauchsdaten-Endpunkt (experimentell vorbereitet), 2FA. Siehe [CLAUDE.md](CLAUDE.md) für den vollständigen Übergabe-Kontext.

## Verbund

Teil des [NRG-Stack](https://github.com/DG65/NRGEMS) — verbindliche Konventionen (Marke, Lizenz, Formular-Stil, Vertragsversionierung, Credentials-Handhabung) stehen in [EMS/SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
