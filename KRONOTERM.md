# WPHub — Kronoterm-Cloud (technische Beschreibung)

Stand 0.18.0, 13.11.2026. Grundlage: die Kronoterm-Anbindung von **Dirk Allard** (Forum: dallard), gegen WPHub 0.17.1 gebaut, ihr Gesamtpatch steht als erster Commit der Historie (Zweig `kronoterm`). Danach nach den NRG-Stack-Konventionen überarbeitet (siehe unten). Bisher an **einer** Anlage getestet (VERSI-X).

## Umfang

- **Nur lesend.** Es gibt keine Stellbefehle. Die Cloud-Anfragen sind GET-Seiten; ein POST dient nur dem Anmeldeformular und der Verbrauchsabfrage (die Schnittstelle verlangt dort POST).
- **Inoffizielle Schnittstelle.** Kronoterm dokumentiert die Cloud-API (`cloud.kronoterm.com/jsoncgi.php`) nicht. Sie ist nach der öffentlichen Home-Assistant-Integration Favio25/Kronoterm-homeassistant nachgebaut (ohne Code zu übernehmen) und kann sich jederzeit ändern.
- Eine Instanz = die im Kronoterm-Konto ausgewählte Wärmepumpe. Hersteller-Auswahl „Kronoterm“, Mindest-Abfrageintervall **300 s**.
- Der **lokale Weg** (Modbus RS485 über das Symcon-ModBus-Gateway) ist der Kronoterm-Karte im WPModbusHub vorbehalten; die liefert auch Leistung und Energie. Siehe dort.

## Messwerte

| Cloud-Quelle | Variable | Hinweis |
|---|---|---|
| `main.ModbusReg[2103]` | Außentemperatur | |
| `main.ModbusReg[2101]` / `[2104]` | WP-Eintritt / WP-Austritt | nicht automatisch die Heizkreis-Vor-/Rücklauftemperatur |
| `info.TemperaturesAndConfig.reservoir_temp` | Puffertemperatur oben | für die untersuchte Anlage vom Nutzer zugeordnet |
| `main.ModbusReg[2001/2006/2007/2011]` | Betriebsfunktion (normiert), Fehlerstatus, Richtung, Abtauen | Codes laut BMS-Handbuch |
| `main.ModbusReg[2327/2329/2371/2372]` | Auslastung, thermische Leistung, COP, SCOP | thermische Leistung ist **keine** elektrische Leistung |
| Heizkreise 1–4, Warmwasser, Pool | je Seite `loop1`–`loop4`, `dhw`, `pool` | nur, wenn der Regler sie als vorhanden meldet |

- **Leistung und Energie (elektrisch)** kommen im Vertrag (`PowerID`/`EnergyID`) ausschließlich aus den externen Variablen `Ext_PowerVariable`/`Ext_EnergyVariable`. Der elektrische Cloud-Zähler (Register 2361/2362) wird nicht übernommen.
- **Tagesenergie** (`consumption`): je Komponente der letzte Tagesverlaufswert, nie die Summe; `EnergieGesamtHeute` nur, wenn alle vier Komponenten gültig sind. Abfrage getrennt (`KR_EnergyIntervalMinutes`).
- **Zusatzwerte:** In der Liste „Zusätzliche Cloud-Werte“ lassen sich beliebige weitere empfangene Felder als Variablen aktivieren. Dokumentierte BMS-Register bekommen Einheit, Faktor und Klartext (Codes, Bitfelder) aus `KronotermModbusDefinitions.php`; Codes/Bitmasken sind Integer-Variablen, Maße Float. Das Abschalten eines Wertes beendet nur die Aktualisierung, die Variable und ihre Historie bleiben.
- **Pufferspeicher:** Layout und weitere Fühler (Cloud-Pfad oder externe Symcon-Variable) im Bereich „Pufferspeicher und Fühler“.

## Anmeldung und Zugangsdaten

Die Cloud kennt kein Zugriffstoken: jede Anfrage braucht Benutzername und Passwort (Basic Auth, ersatzweise Web-Session mit bestätigtem Menü-Handshake). Das Passwort muss deshalb gespeichert werden, **als Attribut `KR_Credentials`**, nie als Property. IPS verschlüsselt Attribute nicht: „sicher“ heißt nur „nicht im Formular und Protokoll sichtbar“. Ablauf: Benutzername und Passwort eintragen, „Übernehmen“, „An der Kronoterm-Cloud anmelden“ (`WPHUB_KronotermLogin`). Das Modul prüft die Anmeldung, speichert beide Werte und leert danach das Passwortfeld. Cookies hält der Client nur im Speicher (curl-Share), es gibt keine Temp-Datei.

## Was gegenüber der Vorlage geändert wurde

| Punkt | Vorlage | jetzt |
|---|---|---|
| Passwort | Property `KR_Password` | Attribut `KR_Credentials`, Eingabefeld wird geleert |
| Cookies | Temp-Datei | nur im Speicher (curl-Share) |
| Texte im Code | teils deutsch, `t()` mit Variablen | englische Quelltexte, `quelle()`/`t()`, deutsche Fassung in `locale-de.json` |
| Beschreibungen der Cloud-Felder | 75 KB deutsche, aus Feldnamen geratene Texte | entfallen; nur belegte Register erklärt |
| BMS-Register-Tabelle | deutsche Texte, wörtlich aus dem Handbuch | eigene englische Formulierungen, als PHP-Array (kein Dateizugriff zur Laufzeit) |
| Archivierung | Haken je Zeile, `AC_SetLoggingStatus` | nur der Knopf „Logging für die Messwerte einschalten“ |
| Sichtbarkeit | `IPS_SetHidden` bei jedem Zyklus | keine Eingriffe in Nutzereigenschaften |
| Codes als Variablen | Float mit festen Werte-Zuordnungen | Integer mit Assoziationsprofil (Stolperstein 29) |
| Formular | drei eigene Fachpanels | ein Fachpanel „Kronoterm“ mit zwei Unterbereichen |
| Code | ~470 Zeilen in `module.php` | Trait `WPHUB_KronotermTrait` |
| Prüfstand | `tests/` mit `eval()`, Gitea-Workflow | Block 13 in `.tools/test-module.php`, Klassentests in `.tools/kronoterm/` |

## Prüfstand

`php .tools/test-module.php` (Block 13): Klassentests (Client, Mapper, Puffer, Energie, Inventar), Anmeldung (abgelehnt/erfolgreich, Attribut statt Property, kein UpdateFormField nach ApplyChanges), Vertrag, Ausfall und Wiederkehr, nur lesende Anfragen, kein Logging. Offen: Test an weiteren Anlagen, Verhalten der Cloud bei vielen Abfragen (Grenzen unbekannt).
