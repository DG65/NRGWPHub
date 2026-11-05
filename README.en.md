🇩🇪 [Deutsche Fassung](README.md)

# WPHub — heat pump cloud connection for Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.17.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Overview

WPHub connects Symcon to heat pump manufacturer clouds and makes the devices it finds available to the NRG-Stack family (e.g. EMS) through the `WPHUB_GetFunctions()` contract (`Type=>'heatpump'`) — consistent with [HeishaMon](https://github.com/DG65/NRGHeishaMon), which provides the same contract for the local/MQTT connection of Panasonic heat pumps with the HeishaMon board.

**Supported manufacturers:** a "Manufacturer" property selects per instance between **Panasonic Comfort Cloud** (Aquarea) and **Vaillant myVAILLANT** (aroTHERM/aroTHERM plus) — a cloud alternative to HeishaMon for users without the HeishaMon board. Further manufacturer clouds (Mitsubishi MELCloud, Viessmann, ...) will follow through the community as soon as users with matching hardware are found for testing — unlike Modbus meters (MeterHub), practically every manufacturer cloud has its own, mostly undocumented authentication/API scheme, hence "one manufacturer at a time" instead of one common driver. Local connections without a cloud: [WPModbusHub](https://github.com/DG65/NRGWPModbusHub) (Modbus), [HeishaMon](https://github.com/DG65/NRGHeishaMon) (MQTT).

## Status

Panasonic has been running on a real account for several builds (Aquarea, standard adapter STD_ADP-TAW1). Vaillant is confirmed on two real systems by testers (tli controller and vrc700 controller).

- **Login** to the Panasonic Comfort Cloud (Auth0/PKCE handshake like the official app; same account as in the app). The password is only used for the one-time login and is cleared automatically afterwards — only the access key is kept (note: Symcon does not encrypt attributes at rest). Accounts with two-factor authentication are not supported yet.
- **Device search:** the Aquarea heat pumps of the account are found automatically; WPHub deliberately does not include air conditioners.
- **Rich variables per heat pump:** reachability, operation (raw + normalized family enum), outdoor temperature, hot water (actual/setpoint), heating zones (actual/setpoint), quiet mode, powerful mode, eco/comfort mode, defrost mode, holiday timer, emergency modes, error count/text and daily energy (heating/cooling/hot water/total, informational only).
- **Control authority per device** (`managedBy`): deliberately coexists with [HeishaMon](https://github.com/DG65/NRGHeishaMon) on the same system — per heat pump you choose who actually regulates.
- **EMS contract:** `WPHUB_GetFunctions()` returns a `Type=>'heatpump'` entry per device (contractVersion 1.15). `PowerID`/`EnergyID` stay 0 unless linked manually to an external meter variable (e.g. from MeterHub) — the Comfort Cloud itself provides neither instantaneous power nor cumulative counters, and by the family rule energy is never extrapolated from daily values.
- **Vaillant myVAILLANT:** its own form panel "☁️ Vaillant myVAILLANT", its own login (Keycloak/OIDC + PKCE + ALTCHA proof of work). Provides reachability, outdoor temperature, flow/buffer temperature, hot water (actual/setpoint), heating circuits 1 and 2, room temperature/humidity, system pressure, operating states and daily energy (electricity, heat generated, environmental energy) from the manufacturer's energy interface (queried only every four hours, the manufacturer's quota is limited). **Read-only by design** (no control commands). Built after the actively maintained reference [signalkraft/myPyllant](https://github.com/signalkraft/myPyllant).

Open: 2FA support (Panasonic), control for Vaillant. See [CLAUDE.md](CLAUDE.md) for the full handover context (German).

## Languages

The module is bilingual (German/English): form, messages, notes and the names of newly created variables follow the Symcon language. The source texts are English, the German version lives in `.tools/locale-de.json` (`php .tools/locale-bauen.php` writes `WPHub/locale.json` from it). Variables that already exist are not renamed.

## Part of the NRG-Stack

Part of the **NRG-Stack** — DG65's energy module family (brand, license, form style, contract versioning and further binding conventions are documented internally).

## License

PolyForm Noncommercial 1.0.0 — see [LICENSE](LICENSE). Free for private use, commercial use requires a license.
