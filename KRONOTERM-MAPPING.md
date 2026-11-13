# Kronoterm mapping and uncovered WPHub functions

> **Historische Zwischenstands-Dokumentation (v2).** Die gültige technische Beschreibung für den kumulativen Stand v5 steht in [KRONOTERM-TECHNISCHE-DOKUMENTATION.md](KRONOTERM-TECHNISCHE-DOKUMENTATION.md). Insbesondere gelten elektrische Leistung und kumulierte elektrische Energie ausschließlich aus externen Variablen; die älteren Aussagen zur Übernahme elektrischer Cloud-Zähler sind überholt.

API reference: https://github.com/Favio25/Kronoterm-homeassistant
(`custom_components/kronoterm/const.py`, `coordinator.py`, `identifiers.py`).
All cloud-specific fields remain **subject to confirmation on the actual VERSI-X**.

| Cloud source | WPHub variable | Rule |
|---|---|---|
| main.ModbusReg[2103] | Aussentemperatur | °C, plausible range |
| main.ModbusReg[2101] | KTWPEintritt | WP inlet; not a proven buffer sensor |
| main.ModbusReg[2104] | KTWPAustritt | WP outlet; not mixed-loop flow |
| main.ModbusReg[2001] | BetriebsartNorm | mapped enum, unknown = -1 |
| main.ModbusReg[2011] | Abtaubetrieb | boolean |
| main.ModbusReg[2006] | Fehlerstatus | 0 no error / 1 warning / 2 error |
| main.ModbusReg[2327] | WPAuslastung | % |
| main.ModbusReg[2329] | KTThermischeLeistung | thermal W, not electric PowerID |
| main.ModbusReg[2371/2372] | KTCOP/KTSCOP | normalized factor, plausible 0–20 |
| main.ModbusReg[2361:2362] | KTEnergieElektrischKumuliert | 32-bit counter; validate on device before linking |
| main.ModbusReg[2363:2364] | KTEnergieThermischKumuliert | 32-bit heat counter, scale 0.1 |
| main.ModbusReg[2028] | KTUmwaelzpumpe, KTWarmwasserUmwaelzpumpe | bit flags |
| main.ModbusReg[2045/2055/2065/2075] | KTKreisN Pumpe | only for configured loops |
| main.ModbusReg[2160–2163] | KTKreisN Raumtemperatur | only for configured loops |

| main.ModbusReg[2090/2091] | KTVerdichterstundenHeizen/WW | h |
| main.ModbusReg[2155/2156/2158] | KT...Zaehler | count |
| loopN.TemperaturesAndConfig | KTKreisNTemperatur | only if configured |
| loopN.HeatingCircleData.circle_temp | KTKreisNSoll | do not assume circuit water sensor |
| dhw.TemperaturesAndConfig.tap_water_temp | Warmwasser | only if configured |
| dhw.HeatingCircleData.circle_temp | WarmwasserSoll | only if configured |
| pool.TemperaturesAndConfig.pool_temp | KTPoolTemperatur | only if active |
| consumption.trend_consumption.CompHeating | EnergieHeizenHeute | kWh daily |
| consumption.trend_consumption.CompTapWater | EnergieWarmwasserHeute | kWh daily |
| consumption.trend_consumption.CPLoops | KTPumpenEnergieHeute | kWh daily |
| consumption.trend_consumption.CPAddSource | KTZusatzEnergieHeute | kWh daily |
| sum of all four daily components | EnergieGesamtHeute | only when complete |
| verified Cloud buffer measurement or external sensor | KTHeizpufferOben/Unten etc. | manual selection |
| ext power / ext energy meter | PowerID / EnergyID | unchanged; independent |

Not proven equivalent in the heating-heat-pump cloud implementation:
`Fluesterbetrieb`, `Leistungsbetrieb`, `Urlaubstimer`,
`NotbetriebWarmwasser`, `NotHeizbetrieb`, `Fehleranzahl`, `Fehlertext`,
`EnergieKuehlenHeute`, `Raumfeuchte`, `WarmwasserSonderfunktion`.
Missing from the reference implementation is **not** proof of missing API data.

Puffer: the reference has contradictory semantics for register 2101 and
`HeatingCircleData.circle_calc_temp`; neither is assigned to a physical probe.
When physical inventory is absent, no buffer measurement is created.

## Live-Abdeckung und nicht genutzte Cloud-Werte (v2)

Die Auswertung erfolgt laufend pro Cloud-Snapshot in
`WPHUB_KronotermMeasurementCatalog::report()`:

| Status | Bedeutung |
|---|---|
| `mapped` | Cloud-Feld wird von WPHub-Messwert-Mapping gelesen und als Variable bereitgestellt |
| `buffer` | Manuell ausgewählter Cloud-Pfad eines Pufferfühlers |
| `configuration` | Wird intern zur Anlagen-/Reglerinventarisierung verwendet, aber nicht als Messwert veröffentlicht |
| `unused` | Cloud hat den Wert geliefert; die vorhandene WPHub-Implementierung verwendet ihn derzeit nicht |

Der Mapper liefert den genauen Quellpfad. Bitfelder, zusammengesetzte
32-Bit-Zähler und Tagesenergie-Zeitreihen werden den tatsächlichen
Ursprungsfeldern zugeordnet. Die Datenpunktliste steht in der
Symcon-Konfiguration sowie als JSON über
`WPHUB_GetKronotermCloudInventory($instanceID)` bereit.
Nur tatsächlich empfangene Felder werden gezählt, nicht theoretisch
mögliche Register. Nicht erfasste oder ausgefallene API-Seiten sind
im Seitenstatus als solche unterscheidbar.
