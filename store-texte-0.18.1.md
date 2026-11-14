# Store-Texte WPHub 0.18.1 (Beta, 14.11.2026)

Beschreibung unverändert gegenüber 0.18.0, nur die Versionsinformation ist neu.

## Beschreibung (de)

WPHub bindet Wärmepumpen über die Cloud ihres Herstellers an Symcon an. Unterstützt werden Panasonic Comfort Cloud (Aquarea-Wärmepumpen), Vaillant myVAILLANT (aroTHERM) und Kronoterm (Cloud, nur lesend). Die Anmeldung erfolgt wie in der Hersteller-App. Bei Panasonic und Vaillant wird das Passwort nur für die einmalige Anmeldung gebraucht und danach verworfen, gespeichert bleibt nur der Zugangsschlüssel. Die Kronoterm-Cloud kennt keinen Zugangsschlüssel, deshalb bleiben dort Benutzername und Passwort gespeichert (nicht verschlüsselt, aber weder im Formular noch im Protokoll sichtbar).

Je Wärmepumpe liefert das Modul Erreichbarkeit, Betriebszustand, Außen-, Vorlauf- und Warmwassertemperatur, Heizkreise mit Ist- und Sollwert und, soweit der Hersteller sie meldet, Tagesenergie, Fehler und Sonderbetriebe (Panasonic: Flüsterbetrieb, Leistungsbetrieb, Urlaubstimer, Notbetriebe, mit Bedienung). Vaillant und Kronoterm sind bewusst nur lesend. Bei Kronoterm lassen sich zusätzlich beliebige weitere Cloud-Werte als Variablen auswählen. Für Energiemanagement und Visualisierung stellt WPHub die Wärmepumpen in einem einheitlichen Format bereit; pro Gerät lässt sich festlegen, ob WPHub oder ein anderes Modul (z. B. HeishaMon) die Wärmepumpe regelt. Das Logging im Archiv schaltet das Modul nur auf Knopfdruck ein.

Panasonic ist an einem echten Konto geprüft, Vaillant an zwei Anlagen von Testern (Regler tli und vrc700), Kronoterm an einer Anlage (VERSI-X). Die Kronoterm-Cloud-Schnittstelle ist vom Hersteller nicht dokumentiert und kann sich ändern. Die Kronoterm-Anbindung entstand aus einem Beitrag von Dirk Allard. Weitere Hersteller sind willkommen, sobald sich Tester mit passender Hardware finden.

Benötigt Symcon 9.0 oder neuer. Das Modul ist zweisprachig (Deutsch/Englisch) und folgt der Sprache von Symcon.

Lizenz: PolyForm Noncommercial 1.0.0. Private, nicht-kommerzielle Nutzung ist frei, gewerbliche Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber.

## Release notes (de)

- Kronoterm: Das im Formular eingestellte Abfrageintervall gilt jetzt (mindestens 60 Sekunden). Bisher wurde es bei Kronoterm stillschweigend auf mindestens 300 Sekunden angehoben. Kronoterm nennt für die Cloud keine Grenzen, ein Hinweis im Formular weist darauf hin. Anregung von Dirk Allard beim Beta-Test.

## Description (en)

WPHub connects heat pumps to Symcon through their manufacturer's cloud. Supported are Panasonic Comfort Cloud (Aquarea heat pumps), Vaillant myVAILLANT (aroTHERM) and Kronoterm (cloud, read only). You sign in the same way as in the manufacturer's app. With Panasonic and Vaillant the password is only needed for the one-time sign-in and is discarded afterwards, only the access key is kept. The Kronoterm cloud has no access key, so username and password stay stored there (not encrypted, but neither visible in the form nor in the log).

For each heat pump the module provides reachability, operating state, outdoor, flow and hot water temperature, heating circuits with actual and setpoint values and, where the manufacturer reports them, daily energy, errors and special modes (Panasonic: quiet mode, powerful mode, holiday timer, emergency modes, with controls). Vaillant and Kronoterm are read-only by design. With Kronoterm you can additionally choose any other cloud values as variables. For energy management and visualization WPHub offers the heat pumps in a common format; per device you can choose whether WPHub or another module (e.g. HeishaMon) controls the heat pump. The module enables archive logging only when you press a button.

Panasonic is tested on a real account, Vaillant on two systems of testers (controllers tli and vrc700), Kronoterm on one system (VERSI-X). Kronoterm's cloud interface is not documented by the manufacturer and may change. The Kronoterm connection grew out of a contribution by Dirk Allard. More manufacturers are welcome as soon as testers with matching hardware are found.

Requires Symcon 9.0 or newer. The module is bilingual (German/English) and follows the language of Symcon.

License: PolyForm Noncommercial 1.0.0. Private, non-commercial use is free; commercial use requires a separate license from the rights holder.

## Release notes (en)

- Kronoterm: The update interval set in the form now applies (at least 60 seconds). Before, it was silently raised to at least 300 seconds for Kronoterm. Kronoterm does not publish limits for the cloud, a note in the form says so. Suggested by Dirk Allard during the beta test.
