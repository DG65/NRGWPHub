# WPHub — Wärmepumpen-Cloud-Anbindung für IP-Symcon

## Übersicht

WPHub verbindet IP-Symcon mit Wärmepumpen-Herstellerclouds und stellt gefundene Geräte dem NRG-Stack-Verbund (z. B. EMS) über den `WPHUB_GetFunctions()`-Vertrag (`Type=>'heatpump'`) zur Verfügung — konsistent zu [HeishaMon](https://github.com/DG65/NRGHeishaMon), das dieselbe Vertragsform für die lokale/MQTT-Anbindung von Panasonic-Wärmepumpen mit HeishaMon-Platine liefert.

**Start-Umfang:** Panasonic Comfort Cloud — als Cloud-Alternative zu HeishaMon für Nutzer ohne HeishaMon-Platine, testbar an der eigenen Anlage. Weitere Herstellerclouds (Mitsubishi MELCloud, Viessmann, Vaillant myVaillant, Stiebel Eltron ISG, ...) folgen später über die Community, sobald sich Nutzer mit passender Hardware zum Testen finden — anders als bei Modbus-Zählern (MeterHub) hat praktisch jeder Herstellercloud-Anbieter ein eigenes, meist undokumentiertes Auth-/API-Schema, daher "ein Hersteller nach dem anderen" statt eines gemeinsamen Treibers.

## Status

Frühes Scaffold (Stand 10.08.2026) — Grundgerüst (Formular, Modul-Lebenszyklus, `GetFunctions()`-Vertragsform) steht, die eigentliche Panasonic-Comfort-Cloud-Anbindung (Login-Handshake, Geräte-/Messwertabfrage) ist noch nicht implementiert. Siehe [CLAUDE.md](CLAUDE.md) für den vollständigen Übergabe-Kontext.

## Verbund

Teil des [NRG-Stack](https://github.com/DG65/NRGEMS) — verbindliche Konventionen (Marke, Lizenz, Formular-Stil, Vertragsversionierung, Credentials-Handhabung) stehen in [EMS/SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
