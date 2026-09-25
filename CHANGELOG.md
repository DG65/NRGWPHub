# Changelog — NRG-Stack WPHub

## 0.11.2 (Build 76) — 25.09.2026

- **Erste echte Vaillant-Bestätigung: Markus (m_rothenpieler) hat 0.11.1 an seiner sensoCOMFORT-Kaskade laufen** — Anmeldung und erste Messwerte funktionieren (Außentemperatur, Vorlauf, Puffertemperatur, Systemdruck). Zwei offene Punkte: Vorlauf- und Puffertemperatur kommen bei ihm identisch an (könnte an seiner Anlagentopologie liegen oder an einer noch ungenauen Feldzuordnung), Warmwasser/Betriebszustände/Energie/Kaskaden-Einzelgeräte fehlen noch komplett. Damit sich das an echten Daten statt an Vermutungen klären lässt: die komplette Rohantwort einer tli-Anlage geht jetzt ebenfalls über die Instanz-Debugausgabe raus (bisher nur für vrc700).
- Prüfstand: 312 Prüfungen (vorher 311), eine neue Mutation der Zielstelle geprüft.

## 0.11.1 (Build 75) — 25.09.2026

- **Vaillant: Sperrfrist nach einem API-Kontingent-Fehler.** m_rothenpieler (Forum-Post #24, aroTHERM-split-Kaskade über sensoCOMFORT) meldete nach einigen Aktualisierungszyklen `HTTP 403 "Out of call volume quota"` — die Erreichbarkeits-Variable kippte danach auf Alarm. WPHub versucht nach so einem Fehler jetzt nicht mehr bei jedem 60-Sekunden-Zyklus erneut (was die Sperre nur verlängert hätte), sondern liest die von Vaillant selbst genannte Wartezeit ("Quota will be replenished in HH:MM:SS") aus der Fehlermeldung und pausiert bis dahin, mit einer klar erkennbaren Protokollmeldung statt der bisherigen generischen Fehlermeldung.
- Prüfstand: 311 Prüfungen (vorher 305), sechs neue Mutationen der Zielstellen geprüft.

## 0.11.0 (Build 74) — 25.09.2026

- **Wichtiger Fix: Vaillant-Systemdaten wurden bislang gar nicht ausgewertet.** cbeham (Forum-Post #22, LWP VWF117 + recoVair365/4) hat sich als erster echter myVAILLANT-Tester angemeldet -- dabei kam ans Licht, dass die rohe Vaillant-API camelCase-Feldnamen liefert (z. B. `outdoorTemperature`), während der Code seit dem ersten Bau schon snake_case (`outdoor_temperature`) erwartet hatte, wie in myPyllants eigenen, bereits konvertierten Modellnamen -- ohne die dazwischenliegende Konvertierung blieben alle Temperaturfelder bislang leer, auch bei einer erfolgreich angemeldeten tli-Anlage. Jetzt wird jede Systemantwort mit einem 1:1-Nachbau von `myPyllant.utils.dict_to_snake_case()` konvertiert (Quelltext gegengelesen).
- **Regler-Typ „vrc700" (cbehams eigentlicher Fall) wird jetzt versucht statt komplett übersprungen.** Eigene Basis-URL (nicht die tli-Basis mit anderem Pfad) plus der von Vaillant selbst nötige Textersatz für Warmwasser-Felder, ebenfalls 1:1 aus der Referenz übernommen. Welche Felder eine echte vrc700-Anlage liefert, ist noch ungeprüft -- das komplette Rohsystem geht zusätzlich über die Debugausgabe raus, um die passenden Feldnamen von einem Tester zu bekommen. „scf"/iQconnect-Anlagen bleiben weiterhin übersprungen (laut Referenz strukturell ohne aggregiertes System).
- Prüfstand: 299 Prüfungen (vorher 288), Vaillant-Teil um camelCase-Fixtures und direkte Dekodier-Tests ergänzt, sechs neue Mutationen der Zielstellen geprüft.

## 0.10.7 (Build 73) — 21.09.2026

- **🔗-Zeile grün (Verbund-Regel „Wert kommt automatisch“, SUITE.md 64b5d1f).** Die automatisch übernommene App-Version wird grün dargestellt (Label `color` 0x2E8B3D), in allen anderen Zuständen (✏️, ℹ️) mit `-1` = Standardfarbe. Zwei neue Prüfungen am ausgelieferten Formular-JSON.

## 0.10.6 (Build 72) — 21.09.2026

- **App-Version: Eingabefeld ausblenden, wenn ein Wert automatisch kommt (Verbund-Regel „Wert kommt automatisch: Eingabefeld ersetzen“).** Statt einer Statuszeile über einem Feld, das man nicht braucht: Ist die Version automatisch ermittelt und das Feld leer, blendet WPHub das Eingabefeld aus und zeigt nur „🔗 App-Version: X (automatisch ermittelt, Quelle: Play Store bzw. AppBrain …)“. Das Feld erscheint nur, wenn nichts automatisch kommt (ℹ️ Modulstandard) oder bei eigener Angabe (✏️). Der Feldwert wird nie per Formular gesetzt, nur ein-/ausgeblendet.
- **Eigene Angabe hat jetzt wirklich Vorrang, ohne die Selbstheilung zu verlieren.** Bisher gewann eine früher automatisch ermittelte Version immer gegen einen später eingetippten Feldwert. Jetzt verwirft `ApplyChanges()` die automatische Version, sobald der Nutzer einen NEUEN Wert einträgt (neues Attribut `CC_AppVersionSeen`, beim ersten Lauf nach dem Update wird nur gemerkt, nichts verworfen). Lehnt die Comfort Cloud die eigene Version danach ab (4106), ermittelt WPHub wieder selbst eine, und die hat dann Vorrang; die Zeile sagt das offen. 13 Prüfungen am ausgelieferten Formular-JSON und an ApplyChanges, drei Mutationen werden erkannt.

## 0.10.5 (Build 71) — 21.09.2026

- **App-Version im Formular sichtbar: live berechnete Statuszeile statt „leer = automatisch“.** Neue Verbund-Regel (SUITE.md „Verbund-Verbindungen im Formular sichtbar machen“, 21.09.2026): automatisch ermittelte Werte dürfen im Formular nicht nur als statischer Satz stehen. Unter dem Feld „App-Version“ (jetzt „Notnagel, leer lassen“) zeigt WPHub jetzt, welche Comfort-Cloud-App-Version tatsächlich gilt und woher sie stammt: ✅ automatisch ermittelt (Vorrang, mit Hinweis, wenn der Feldwert deshalb nicht verwendet wird), ✅ aus dem Feld, ℹ️ Modulstandard 4.4.0. Vorrang und Zeile teilen sich eine Funktion (`effectiveAppVersion()`), können also nicht auseinanderlaufen. 6 neue Prüfungen am ausgelieferten Formular-JSON, für jeden Zustand.

## 0.10.4 (Build 70) — 16.09.2026

- **Erster Beta-Release.** `beta`-Branch angelegt (bisher gab es nur `ems-integration`). Vor dem Wechsel geprüft: `php -l` + voller Testlauf grün, Store-Review-Checkliste Punkt 12 (Neuinstallations-Simulation) durchgegangen — keine eigenen Objekt-/Variablen-IDs, PLZ/Adressen oder Kampagnennamen im Formular-Code, jede Hersteller-Nennung entweder als "z. B." oder (Panasonic/Vaillant-Panels) durch die eigene Hersteller-Auswahl explizit gerechtfertigt. `migrationsvergleich.php` (SUITE.md 9e) entfällt für diesen allerersten Beta-Stand — es gibt noch keinen vorherigen Beta/Main-Stand zum Vergleichen. `LICENSE_URL` zeigt jetzt auf `beta` statt `ems-integration` (aktive Entwicklung bleibt auf `ems-integration`, `beta` wird nur bei Bedarf nachgezogen).

## 0.10.3 (Build 69) — 16.09.2026

- **Korrektur: GUID-"Kollision" war ein Fehlalarm — GUIDs zurückgesetzt.** Der Store-Fehler aus 0.10.2 kam laut Dietmar daher, dass beim Einreichen das falsche Repo in den Module Store geladen wurde (nicht WPHub selbst) — keine echte Kollision. `library.json→id` und `WPHub/module.json→id`/`library` wieder auf die ursprünglichen, seit dem Scaffold vom 10.08.2026 verwendeten Werte gesetzt (`D81D0DE5-…`/`5BE429EA-…`). Die in 0.10.2 neu vergebenen GUIDs sind hinfällig. Dietmars laufende WPHub-Instanz bleibt dadurch unangetastet — die für 0.10.2 angekündigte Neuanlage der Instanz entfällt.

## 0.10.2 (Build 68) — 16.09.2026

- **Neue library-/Modul-GUID wegen echter Kollision beim Module-Store-Upload.** Der Store meldete beim Einreichen "Die verwendete GUID {D81D0DE5-…} wird bereits von einem anderen Modul verwendet". Geprüft: keine Dopplung in irgendeinem eigenen Repo, keine öffentliche GitHub-Fundstelle, kein vorheriger eigener Store-Eintrag (Dietmar bestätigt) — also eine echte externe Kollision, kein Copy-Paste-Fehler. `library.json→id` und `WPHub/module.json→id`/`library` neu generiert. **Achtung für Dietmar:** Symcon erkennt die geänderte Modul-GUID als anderes Modul — die bestehende WPHub-Instanz muss nach dem Update neu angelegt werden (Konfiguration/Zuordnungen gehen dabei verloren, siehe Ankündigung im Chat).

## 0.10.1 (Build 67) — 16.09.2026

- **Forum-Hinweis-Panel verlinkt den echten WPHub-Vorstellungsthread.** Der Thread ist seit heute live (Dietmar). Neues, einmalig dismissibles Panel „💬 Feedback im Symcon-Forum" (Muster MeterHub `ForumHint()`/`AckForumHint()`, ohne dessen Mehrinstanzen-Propagierung), eingehängt zwischen den Fachpanels und „🧡 Über dieses Modul" (SUITE.md „Einheitliche Formular-Optik"). Löst den bisherigen Platzhalter-Zustand ab (kein Forum-Panel, da noch kein Thread existierte). 7 neue Tests.

## 0.10.0 (Build 66) — 14.09.2026

- **Zweiter Hersteller: Vaillant myVAILLANT (aroTHERM/aroTHERM plus), neben Panasonic Comfort Cloud.** Dietmars Anstoß ("andere WP-Hersteller mit Cloud-Zugängen mitnehmen"), Architektur dafür am Vorbild InverterHub gebaut: neue Property `Manufacturer` (Select, Standard weiterhin „Panasonic" — jede bestehende Installation bleibt unverändert) schaltet Formular-Panel und Treiber-Zweig um. Vaillant-Login läuft über Keycloak/OIDC mit PKCE + einem ALTCHA-Proof-of-Work (Referenz: signalkraft/myPyllant, Python, aktiv gepflegt) — neue Klasse `WPHUB_VaillantClient` (`WPHub/libs/VaillantClient.php`). Liefert je Anlage Erreichbarkeit, Außentemperatur, Vorlauf-/Puffertemperatur, Warmwasser sowie neu einen Systemdruck-Wert (Panasonic liefert das nicht) — alles über dieselben Idents wie bei Panasonic, wodurch `WPHUB_GetFunctions()` (contractVersion unverändert 1.15) ohne Änderung auch Vaillant-Geräte trägt. **Bewusst nur lesend** (keine Steuerbefehle) und **Stand 14.09.2026 ungeprüft an einem echten Konto** — Dietmar hat keine Vaillant-Anlage zum Testen; nur der Regler-Typ „tli" (der häufigste) wird unterstützt, andere werden übersprungen statt geraten. Neues Panel „🏭 Hersteller" mit Feldhilfe. 33 neue Tests, u. a. ein Korrektheitsnachweis für den ALTCHA-Löser (nachgerechneter PBKDF2-Schlüssel, nicht nur „kein Fehler").

## 0.9.2 (Build 65) — 14.09.2026

- **Feldhilfe für die vier Steuerhoheit-Optionen ergänzt.** EMS-Bitte im Auftrag von Dietmar (Doku/Hilfe-Durchsicht vor dem Store-Launch): das Panel „🔀 Steuerhoheit" hatte nur eine kurze Info-Zeile, aber — anders als die übrigen Panels — keinen `PopupButton` dazu, was „WPHub"/„HeishaMon"/„Anderes Modul"/„Niemand — nur lesen" konkret bedeuten. Ergänzt nach dem etablierten Muster (RowLayout + `PopupButton`, 460px, volle konkrete Frage als Beschriftung). 1 neuer Test.

## 0.9.1 (Build 64) — 14.09.2026

- **Fix: Steuerhoheit-Auswahl blieb nach dem Klick im offenen Formular auf dem alten Stand.** Selbst-Audit gegen die Store-Review-Checkliste (Punkt 13, „Sichtbare Rückmeldung bei jeder Aktion") fand: `SetManagedBy()` hat die Auswahl gespeichert, aber das ⚠️-Präfix des betroffenen Select-Felds („Noch nicht zugeordnet") verschwand erst beim nächsten frischen Öffnen des Formulars, nicht sofort nach der Auswahl — gleicher Fehlertyp wie der ursprüngliche `DiscoverySummary`-Bug (Build 50). Jetzt zieht `SetManagedBy()` die Caption per `UpdateFormField()` sofort nach; die Berechnung ist in `managedBySelectCaption()` ausgelagert und wird von `GetConfigurationForm()` und `SetManagedBy()` gemeinsam genutzt. 1 neuer Test.

## 0.9.0 (Build 63) — 14.09.2026

- **Verbundweite Formular-Konvention nachgezogen: "👋 Wozu dieses Modul?" und "🧡 Über dieses Modul".** EMS-Store-Konventionsprüfung fand zwei Lücken gegenüber SUITE.md "Einheitliche Formular-Optik" (Referenz MeterHub). Neu ganz oben, VOR dem "Neu in Version"-Panel: „👋 Wozu dieses Modul?" — kurzer Zweck-/Nutzentext, standardmäßig aufgeklappt, einmalig ausblendbar (`PurposeIntroGone`-Attribut, nicht versionsgebunden — der Zweck eines Moduls ändert sich nicht mit jedem Release). Neu ganz unten: „🧡 Über dieses Modul" — Lizenzhinweis (PolyForm Noncommercial 1.0.0) + Spendenlink, verbundweit wortgleich, bewusst NICHT dismissible. Beide Link-Knöpfe nutzen `onClick: "echo '<URL>';", link: true` statt `link` direkt auf die URL zu setzen (bekannte Stolperfalle, sonst tut der Klick nichts). **„📖 Dokumentation & Hilfe" hatten wir entgegen einer ersten EMS-Einschätzung schon** (nur nicht als eigenständiges PHP wie bei MeterHub, sondern direkt in `form.json`) — der Forum-Hinweis fehlt weiterhin bewusst, da es noch keinen WPHub-Forumsthread gibt (unverändert seit Projektstart). 13 neue Tests.

## 0.8.1 (Build 62) — 13.09.2026

- **Fix: stille Steuerhoheits-Lücke nach dem 0.8.0-Umbau.** HeishaMon fand live an Dietmars Instanz (#57727): `DeviceManagedBy` stand nach dem Umstieg auf das neue Feld leer (`{}`), fiel also auf den Standard „WPHub" zurück — obwohl eine aktive HeishaMon-Instanz vorhanden war und Dietmars eigentliche Entscheidung „HeishaMon steuert" hier gegolten hätte. Der bisherige Info-Hinweis allein macht das nicht ausreichend sichtbar (man müsste das Formular gezielt öffnen). Jetzt: **neuer Instanzstatus 203** ("Steuerhoheit prüfen"), gesetzt in `Update()`, sobald eine aktive HeishaMon-Instanz existiert UND mindestens ein Gerät noch keine explizit gesetzte Steuerhoheit hat — sichtbar direkt in der Instanzliste, nicht erst im geöffneten Formular. Zusätzlich markiert das Auswahlfeld im Panel „🔀 Steuerhoheit" das betroffene Gerät jetzt mit „⚠️ Noch nicht zugeordnet". **Das tatsächliche Steuerverhalten ändert sich nicht** (Standard bleibt „WPHub", wie von Dietmar/EMS für frische Installationen gefordert) — dieser Fix macht nur die Ambiguität sichtbar, statt sie stillschweigend zu übergehen. Neue private Methoden `deviceManagedByIsExplicit()`/`managedByNeedsAttention()`. 9 neue Tests.

## 0.8.0 (Build 61) — 13.09.2026

- **Steuerhoheit je Gerät statt global: neues Vertragsfeld `managedBy` (contractVersion 1.14 → 1.15).** EMS hat Dietmars Vorrang-Entscheidung präzisiert ("wenn HeishaMon da ist, steuert HeishaMon") — sie gilt **je Wärmepumpe**, nicht pauschal für den ganzen Account: eine zweite, nur über WPHub eingebundene Anlage soll weiter normal steuerbar bleiben, auch wenn irgendwo im System eine aktive HeishaMon-Instanz existiert. Ersetzt den globalen HeishaMon-Erkennungs-Block aus 0.6.0 durch eine bewusste **Nutzerangabe je Gerät** (`managedBy`: `wphub`/`heishamon`/`other`/`none`, Standard `wphub`) — die Geräteidentität zwischen WPHub und HeishaMon lässt sich technisch nicht beweisen, deshalb keine automatische Ableitung. Feldname bewusst `managedBy` statt InverterHubs `controlAuthority` (dort geht es um EMS-Zugriff, hier um "welches Modul steuert dasselbe physische Gerät" — das EMS schreibt nie an die Wärmepumpe) — direkt am Quellcode von InverterHub/ChargerHub geprüft, mit EMS abgestimmt. Neues Formularpanel „🔀 Steuerhoheit" zeigt für jedes gefundene Gerät ein Auswahlfeld, vorbelegt mit dem aktuellen Wert; der bisherige HeishaMon-Koexistenz-Hinweis bleibt als reiner Vorschlag erhalten ("ℹ️" statt "⚠️", da er nicht mehr automatisch blockiert). `applyControl()`/`maintainDeviceVariables()` prüfen jetzt `managedBy` des jeweils betroffenen Geräts statt eines globalen Flags. 14 neue/angepasste Tests, inkl. explizitem Beleg, dass ein zweites Gerät mit `managedBy=wphub` unberührt steuerbar bleibt, während ein anderes blockiert ist.

## 0.7.1 (Build 60) — 13.09.2026

- **Neues Vertragsfeld `pollInterval` (contractVersion 1.13 → 1.14).** Dashboard fragte im Zuge von `lastSeenAt` nach, wie groß das Frische-Fenster für eine Wärmepumpen-Anzeige sein sollte, um WPHubs Abfragerate sicher zu überdecken. Statt einen Sicherheitsfaktor auf einen geratenen Wert draufschlagen zu müssen, liefert der Vertrag jetzt die reale Abfragerate in Sekunden (Vorbild MeterHub-Vertrag) — Standard 60 s, per Formular 30–600 s einstellbar, mit demselben `max(30, ...)`-Boden wie der echte Timer in `ApplyChanges()`, damit der gemeldete Wert nie vom tatsächlichen abweicht. 2 neue Tests.

## 0.7.0 (Build 59) — 13.09.2026

- **Neues Vertragsfeld `lastSeenAt` (contractVersion 1.11 → 1.13).** Dashboard-Anfrage: die Energiefluss-Kachel zeigt seit ChargerHub/OCPPHub-Vertrag 1.3 „keine aktuelle Messung" statt eines eingefrorenen Werts, wenn `lastSeenAt` älter als 5 Minuten ist — additiv jetzt auch bei uns. Unix-Zeitstempel der letzten erfolgreichen Cloud-Antwort je Gerät, gesetzt in `refreshDevices()` bei jedem erfolgreichen Abruf (nicht nur bei Wertänderung, wie von Dashboard gefordert), bleibt bei einem Cloud-Ausfall bewusst eingefroren (`markAllUnreachable()` fasst ihn nicht an) statt auf 0 zurückzufallen — Konsumenten können damit echte Aktualität von einem bloßen Erreichbarkeits-Flag unterscheiden. 2 neue Tests.

## 0.6.4 (Build 58) — 13.09.2026

- **Store-Review-Durchgang (InverterHub-Anstoß, Dietmars Zeitrahmen für den ersten Store-Start).** Komplette Checkliste in SUITE.md durchgeprüft, besonders die neuen Punkte 9b–9d. Einziger echter Fund: **Punkt 9c** (`ReadPropertyXXX()`/`ReadAttributeXXX()` nie ungecastet an `json_decode()` weiterreichen — dreimal unabhängig bei Tibber/OCPPHub/Dashboard aufgetreten). WPHub hat kein `declare(strict_types=1)`, daher aktuell kein akuter Absturz möglich, aber alle fünf Stellen (`GetFunctions()`, `discoverySummaryLine()`, `RequestAction()`, `ensureToken()`, `markAllUnreachable()`) vorsorglich auf `(string)`-Cast umgestellt. Alle übrigen Punkte bereits konform: 9d (IS_INACTIVE 104 statt Fehlercode, WPHub legt ohnehin nie eigene Instanzen an), 9b (in Build 57 behoben), 13/Sichtbare-Rückmeldung (alle vier Muster bereits korrekt umgesetzt), 1/5/6/8/10 (Selbstpersistenz, Variablenprofile, Modulname, library.json, `$id` statt `$_IPS['TARGET']`) ohne Befund.

## 0.6.3 (Build 57) — 13.09.2026

- **Echte Umlaute statt ae/oe/ue in nutzersichtbaren Texten** (EMS-Hinweis auf Verbund-Regel 9b, SUITE.md-Store-Checkliste). Betraf sechs alte, noch nie überarbeitete Textstellen im Doku-Panel und in den Hilfe-Popups (Sicherheitshinweis, Zustimmungs-Erklärung, App-Version-Erklärung) sowie den Vertrags-Fallback `Caption` in `WPHUB_GetFunctions()` und einen `SendDebug`-Themennamen. Datumsformat war bereits unauffällig (`discoverySummaryLine()` zeigt nur die Uhrzeit, keine Datums-ISO-Notation).
- **Nebenfund dabei behoben: Versionsnummer im Doku-Panel war seit Build 3 fest auf "0.1.0" eingefroren.** Liest jetzt bei jedem Formularaufruf live aus `library.json`, kann also nicht mehr veralten. 1 neuer Test.

## 0.6.2 (Build 56) — 13.09.2026

- **Hilfe-Fragen nachgeschärft, EMS-Feedback zur bestehenden SUITE.md-Konvention „Feld-Hilfestellung".** Die in Build 55 eingeführten Fragen waren zwei Punkte von der (bereits seit 01.09.2026 in SUITE.md stehenden, uns nur nicht bekannten) Regel entfernt: (1) Fragen brauchen einen konkreten Gegenstand statt generischer Formulierungen — „Wofür ist das gut?" → „Wofür sind externe Sensoren & Zähler gut?", „Wann brauche ich das?" → „Wann brauche ich „Aktualisierte Bedingungen akzeptieren"?", „Ist das sicher?" → „Ist die Speicherung der Zugangsdaten sicher?" ( „Wozu die App-Version?" war schon konkret genug, nur leicht geschärft). (2) `width` fest auf 460px statt weggelassen (EMS-Empfehlung: 460–480px für einzeilige Fragen, unter ~70px unbrauchbar).

## 0.6.1 (Build 55) — 13.09.2026

- **Hilfe-Schaltflächen zeigen jetzt die Frage direkt, statt nur "?".** Dietmars Vorgabe: der Nutzer soll schon am Formular sehen, welche Frage sich per Klick beantworten lässt ("Wofür ist das gut?", "Wann brauche ich das?", "Wozu die App-Version?", "Ist das sicher?" — Letztere neu so formuliert, hieß bisher nur "Hinweis zur Sicherheit"), statt erst auf ein anonymes "?" klicken zu müssen. Festes `width:"70px"` entfernt (war auf ein einzelnes Zeichen zugeschnitten). Rein `form.json`, kein Codewechsel.

## 0.6.0 (Build 54) — 13.09.2026

- **Vorrang-Entscheidung Dietmars umgesetzt: existiert eine aktive HeishaMon-Instanz, steuert WPHub gar nicht mehr.** Direkte Folge des Koexistenz-Hinweises aus Build 53 ("Im Grunde ist es doch ganz einfach, wenn es eine HeishaMon gibt, dann darf WPHub nichts steuern!"). Zwei Teile: (1) `applyControl()` (der einzige Codepfad für alle Steuerfelder) lehnt jeden Steuerbefehl ab, sobald `heishaMonCoexistenceWarning()` eine aktive HeishaMon-Instanz meldet — Protokollzeile erklärt warum, Variable bleibt wie bei jedem anderen Fehlschlag auf dem letzten bestätigten Stand. (2) `maintainDeviceVariables()` deaktiviert dafür zusätzlich sichtbar alle Steuerelemente (`DisableAction` statt `EnableAction`: Flüsterbetrieb, Leistungsbetrieb, Warmwasser-/Zonen-Sollwert, Urlaubstimer, Notbetriebe) statt sie anklickbar zu lassen und den Klick dann nur im Hintergrund abzulehnen — und aktiviert sie automatisch wieder, sobald keine aktive HeishaMon-Instanz mehr existiert. **Nur das Schreiben ist betroffen:** Messwerte/Status werden unverändert weiter angezeigt und aktualisiert. Bewusst ohne Geräte-Identitätsprüfung (Dietmar hat ohnehin nur eine Panasonic-Anlage). 3 neue Tests.

## 0.5.0 (Build 53) — 13.09.2026

- **Koexistenz-Hinweis für HeishaMon.** ChargerHub deckte im Rahmen einer verbundweiten Konfliktprüfung (Anlass: ihr eigener go-e-Hardlock mit OCPPHub) auf, dass WPHub (Panasonic Comfort Cloud) und HeishaMon (lokale MQTT-Bridge) bei Dietmar dieselbe physische Aquarea-Wärmepumpe ansteuern könnten — live bestätigt: `WPHUB_Active` stand tatsächlich auf `true`, während HeishaMon parallel lokal lief. Beide Kanäle können unabhängig voneinander widersprechende Befehle senden (Flüsterbetrieb, Leistungsbetrieb, Warmwasser-/Zonen-Sollwert vs. HeishaMons `main/Quiet_Mode_Schedule`/`main/DHW_Target_Temp`/`main/Z1_Water_Temp`), ohne dass eines der Module vom anderen weiß. Neue private Methode `heishaMonCoexistenceWarning()` zeigt jetzt, sobald eine aktive HeishaMon-Instanz existiert (`InstanceStatus === 102`, der von HeishaMon selbst genannte nächstliegende Signal-Ersatz — HeishaMon führt keine eigene "aktiv"-Eigenschaft), einen Warnhinweis ganz oben im Konfigurationsformular. **Bewusst rein informativ, kein Blockieren:** die Geräteidentität lässt sich zwischen beiden Verträgen nicht beweisen (kein gemeinsames Seriennummer-Feld), und Doppelbetrieb ist nicht zwangsläufig ein Fehler (z. B. HeishaMon nur Monitoring, WPHub nur unterwegs als Cloud-Fallback). Symmetrisches Gegenstück baut HeishaMon auf eigener Seite. 5 neue Tests (Block 4f). Dietmar direkt gefragt, welcher Kanal bei ihm führend sein soll.

## 0.4.4 (Build 52) — 12.09.2026

- **Modulname gekürzt: „NRG-Stack WPHub for IP-Symcon" → „NRG-Stack WPHub".** Dietmars Entscheidung, den „for IP-Symcon"-Zusatz zu entfernen (`library.json→name`). Rein kosmetisch, keine Auswirkung auf `module.json`/Klassennamen (`WPHub`, bleibt technisch unverändert), GUID oder Vertrag.

## 0.4.3 (Build 51) — 20.08.2026

- **Neuer Knopf „🔄 Übernehmen erzwingen (ohne Formularänderung)"** im Doku-Panel (Angebot von EMS, keine Pflicht-Konvention). Ruft direkt `IPS_ApplyChanges($id)` mit Bestätigungs-Popup auf — praktisch nach einem Modul-Update über die Modulverwaltung, ohne extra ein Feld anfassen und speichern zu müssen, damit z. B. neu registrierte Attribute/Properties sofort greifen. Reiner `form.json`-Zusatz (natives `onClick`, kein neuer Modulcode).

## 0.4.2 (Build 50) — 20.08.2026

- **Fix: Status-Kopfzeile blieb im bereits geöffneten Formular stehen.** EMS fand bei sich denselben Fehler (jetzt SUITE.md „IP-Symcon-Stolperfallen" Punkt 12): `GetConfigurationForm()` wird nach einer Formular-Aktion NICHT automatisch neu ausgeführt — wer das Formular offen ließ und auf „Anmelden und Wärmepumpen suchen" bzw. „Aktualisierte Bedingungen akzeptieren" klickte, sah die neue Kopfzeile (Build 49) trotz erfolgreicher Suche erst nach Schließen und erneutem Öffnen des Formulars. Betraf bei uns denselben Code-Pfad wie bei EMS: `Login()`, `doAcceptAgreements()` und den laufenden `Update()`-Zyklus riefen `refreshDevices()` zwar korrekt auf, schoben das Ergebnis aber nie per `UpdateFormField()` ins offene Formular. Neue private Methode `refreshDiscoverySummary()` kapselt den fehlenden Aufruf, jetzt an allen drei Erfolgsstellen ergänzt. 1 neuer Test (Block 4c, deckt den `doAcceptAgreements()`-Pfad ab — `Login()`/`Update()` bleiben aus denselben Gründen wie bisher ungetestet: die öffentlichen Wrapper bauen einen echten Cloud-Client auf, den der Prüfstand bewusst nicht mockt).

## 0.4.1 (Build 49) — 20.08.2026

- **Einheitliche Verbund-Status-Kopfzeile übernommen** (SUITE.md-Konvention vom 20.08.2026, Referenz EMS' `getDiscoverySummaryLine()`). Direkt unter dem Anmelden-Knopf steht jetzt eine einzige Zeile im Muster `<Icon> <Zahl> Wärmepumpe(n) gefunden (zuletzt HH:MM:SS Uhr).` — `✅` bei mindestens einem Fund, `⚠️` bei 0 trotz vorheriger Suche, `ℹ️` vor der ersten Suche. Neues Attribut `LastDiscoveryTs` wird bei jeder erfolgreichen Geräteabfrage (Login, Zustimmung, laufender Update()-Zyklus) aktualisiert — WPHub hat keinen separaten "Jetzt suchen"-Knopf, die Kopfzeile spiegelt daher den Stand der letzten erfolgreichen Aktualisierung statt eines einzelnen Klicks. Neue private Methode `discoverySummaryLine()`. 3 neue Tests.

## 0.4.0 (Build 48) — 20.08.2026

- **MeterHub-Zähler mit Funktionszuordnung „Wärmepumpe" wird automatisch erkannt.** Dietmars Anregung: Wer bereits einen MeterHub-Zähler an der Wärmepumpe hat (z. B. einen Shelly), muss den nicht mehr manuell im "🔌 Externe Sensoren & Zähler"-Panel heraussuchen. MeterHub führt dafür bereits ein eigenes Funktions-Vokabular (`heatpump` u. a.) und veröffentlicht die Zuordnung über seinen bestehenden `MHUB_GetFunctions($id)`-Vertrag (`assignments[].function`/`powerID`/`energyImportID`) — kein neues Vertragsfeld nötig, nur eine neue Konsumentenseite in WPHub. Neue private Methode `meterHubHeatpumpAssignment()` durchsucht alle installierten MeterHub-Instanzen (`IPS_GetInstanceListByModuleID`) nach einem Kanal mit `function === 'heatpump'`. Solange `Ext_PowerVariable`/`Ext_EnergyVariable` noch beide 0 sind, zeigt das Formular bei einem Fund einen Hinweis samt Schaltfläche „🔗 Von MeterHub übernehmen" — Übernahme setzt beide Properties **nur auf Klick**, nie automatisch im Update()-Zyklus (gleiches Prinzip wie „Aktualisierte Bedingungen akzeptieren": die Verknüpfung ist eine Nutzerentscheidung). Rein optionale Kopplung hinter `function_exists('MHUB_GetFunctions')` — ohne MeterHub bleibt alles unverändert wie bisher (manuelle Verknüpfung weiterhin möglich). 19 neue Tests (Block 4e), inkl. Teilübernahme (nur Energie ohne Leistungskanal) und Fehlerfall (keine Zuordnung gefunden).

## 0.3.0 (Build 47) — 18.08.2026

- **Repo umbenannt: `DG65/WPHub` → `DG65/NRGWPHub`.** Alle anderen Verbund-Repos folgen dem Muster `NRG<Modulname>` (NRGEMS, NRGMeterHub, NRGModbusSlave, NRGTibberGridRewards, NRGPrognose) — bei WPHub war das bei der Repo-Anlage (10.08.2026) übersehen worden. Da noch nicht veröffentlicht (kein Store-Eintrag, keine Nutzerinstanzen außer Dietmars eigener), gefahrlos nachgeholt: GitHub-Repo per `gh repo rename` umbenannt (GitHub leitet die alte URL automatisch weiter), lokaler `origin`-Remote sowie `library.json→url` und der Link im Formular-Doku-Panel angepasst. **Kein Einfluss auf den PHP-Klassennamen** — der bleibt technisch weiterhin `WPHub` (SUITE.md/CLAUDE.md-Konvention: Klassenname ≠ Markenname).

## 0.3.0 (Build 46) — 17.08.2026

- **Tages-Energiezähler jetzt archiviert + im Vertrag sichtbar (contractVersion 1.6 → 1.11).** Dashboard hat angefragt, ob die vier Tageszähler (`EnergieHeizenHeute`/`EnergieKuehlenHeute`/`EnergieWarmwasserHeute`/`EnergieGesamtHeute`) für HeatMonitor/HeatSchema als echte kWh-Datenquelle nutzbar gemacht werden können — bislang waren sie rein informativ, weder archiviert noch im `WPHUB_GetFunctions()`-Vertrag erreichbar. Zwei Änderungen: (1) alle vier Variablen werden jetzt automatisch archiviert (gleiches Muster wie `Aussentemperatur` seit Build 44); (2) vier neue Vertragsfelder `dailyEnergyHeatingID`/`dailyEnergyCoolingID`/`dailyEnergyDHWID`/`dailyEnergyTotalID`. **Bewusst nicht über `EnergyID`** — die Grundregel (SUITE.md, „Gemeinsame Variablenprofile") verlangt für `EnergyID` einen echten kumulativen Zähler, diese Werte springen aber um Mitternacht auf 0 zurück. Eigene, klar als „daily" benannte Felder analog zu HeishaMons `dailyPerformanceFactorID` verhindern, dass ein Konsument sie versehentlich wie einen Zähler diffed. Neuer Feldnamensvorschlag an EMS zur SUITE.md-Registrierung gemeldet. 8 neue Tests.

## 0.3.0 (Build 45) — 17.08.2026 — **Dringende Fehlerbehebung**

- **Update()-Zyklus stürzte seit Build 44 bei jedem Durchlauf ab.** Die neue Archivierungsfunktion (`ensureArchived()`) rief `AC_GetLoggingStatus()` mit nur einem Parameter auf — die echte Funktion braucht zwei (`AC_GetLoggingStatus(int $ArchiveID, int $VariableID): bool`). Das führte zu einem `ArgumentCountError`, den `@` (Fehlerunterdrückung) NICHT abfängt, da es sich um einen echten PHP-Error handelt — jeder Update()-Zyklus brach seitdem fatal ab, bevor irgendetwas aktualisiert wurde. Von Dashboard per Live-Systemprüfung gefunden und gemeldet. Behoben: Archiv-Instanz-ID wird jetzt korrekt aufgelöst (`IPS_GetInstanceListByModuleID`) und als erster Parameter mitgegeben. Zusätzlich als Lehre daraus: `ensureArchived()` läuft jetzt in einem `try`/`catch` — ein künftiger Fehler in der Archivierung (Komfortfeature) kann den Update()-Zyklus nie wieder mitreißen. Die Test-Attrappen hatten dieselbe falsche Signaturannahme wie der Modulcode und hätten den Fehler dadurch nie aufgedeckt — jetzt korrigiert, erzwingen die echte Signatur.

## 0.3.0 (Build 44) — 17.08.2026

- **Automatische Archivierung für die Außentemperatur.** Für Dashboards neue HeatMonitor-Verlaufskachel wird die Außentemperatur-Variable (`outsideTempID`) jetzt automatisch archiviert (IPS-Archiv-Handler), sobald sie angelegt wird. Bewusst **nur** für diese eigene Variable — die extern verknüpften Felder (`PowerID`/`mainInletTempID`/`mainOutletTempID`, siehe „Externe Sensoren & Zähler") zeigen auf Variablen anderer Module (z. B. eines Shellys); deren Archivierung bleibt Sache des jeweils besitzenden Moduls, WPHub schaltet dort nichts um.

## 0.3.0 (Build 43) — 17.08.2026

- **Namenskonflikt behoben: `outsideTempID` ist jetzt der kanonische Feldname** für die Außentemperatur (EMS-Entscheid, SUITE.md-Feldregister Commit e0f219e — Stilkonsistenz mit den übrigen `*TempID`-Kurzformen im gemeinsamen `heatpump`-Vertragstyp). Unser bisheriger, abweichender Name `outdoorTemperatureID` (seit 1.3) gilt als deprecated, wird aber bruchfrei parallel weiterhin geliefert (zeigt auf dieselbe Variable), bis alle Konsumenten umgestellt haben.

## 0.3.0 (Build 42) — 17.08.2026

- **Externe Sensoren & Zähler.** Neues, optionales Formularpanel: eine beliebige vorhandene Symcon-Variable (Shelly, MeterHub, HeishaMon, eigener 1-Wire-Fühler — WPHub setzt kein bestimmtes anderes Modul voraus) lässt sich als echter Stromzähler (`Ext_PowerVariable`/`Ext_EnergyVariable`) oder als Vor-/Rücklauf-/Puffertemperatur-Fühler (`Ext_MainInletTempVariable`/`Ext_MainOutletTempVariable`/`Ext_BufferTempVariable`) verknüpfen. Schließt die strukturellen Lücken der Comfort-Cloud-API (keine echte Leistung/Energie, keine Vor-/Rücklauftemperatur). Verknüpfte Werte erscheinen im Vertrag (contractVersion 1.4 → 1.5) als echte Messung: `PowerID`/`EnergyID` zeigen auf die verknüpfte Variable, `Measured` wird `true`, `mainInletTempID`/`mainOutletTempID`/`bufferTempID` (dieselben Feldnamen wie im gemeinsamen `heatpump`-Vertragstyp) werden befüllt. Ohne Verknüpfung bleibt alles wie bisher auf 0/false. Wird eine verknüpfte Variable gelöscht, fällt der Vertrag automatisch auf 0 zurück, statt eine tote ID zu melden. Muster von HeishaMon übernommen (DG65/NRGHeishaMon). **Bewusst nicht enthalten:** COP-/Arbeitszahl-Berechnung — dafür fehlt WPHub strukturell die erzeugte Wärmemenge (kein Durchfluss, keine Wassermenge), auch mit externem Stromzähler nicht ableitbar. 15 neue Tests.

## 0.2.0 (Build 41) — 13.08.2026

- **Verbund-weit normierte Betriebsart** (contractVersion 1.3 → 1.4, mit HeishaMon/EMS abgestimmt). Neue Variable „Betriebsart (normiert)" bildet unsere Panasonic-Betriebsart zusammen mit dem Warmwasser-Aktivstatus auf den gemeinsamen `heatpump`-Enum ab (0=Standby, 1=Heizen, 2=Kühlen, 3=Warmwasser, 4=Heizen+Warmwasser, 5=Kühlen+Warmwasser, -1=Unbekannt) — Dashboard/EMS müssen damit keine Herstellersemantik mehr kennen. Neue Vertragsfelder `operatingModeNormID` (normiert) und `operatingModeID` (unser rohes ExtendedOperationMode, optionales Diagnosefeld). Grund für die Umstellung: Dashboard hatte zu Recht eingewandt, dass rohe Hersteller-Enums (unser 0-4 vs. HeishaMons eigenes Schema) für Konsumenten nicht direkt vergleichbar sind.

## 0.2.0 (Build 40) — 13.08.2026

- **`WPHUB_GetFunctions()` additiv erweitert (contractVersion 1.2 → 1.3).** Bisher gab der Vertrag nur die Basisfelder zurück (Betriebsart war z.B. nur als lose IPS-Variable erreichbar, nicht über den Vertrag) — Dashboard hätte für die geplante Anlagenschema-Visualisierung direkt auf Idents zugreifen müssen, was der Verbund-Konvention widerspricht. Neu: `outdoorTemperatureID`, `z1WaterTempID`, `z2WaterTempID`, `z1WaterTargetTempID`, `z2WaterTargetTempID`, `dhwTempID`, `dhwTargetTempID`, `quietModeID`, `ecoComfortModeID`, `holidayTimerID` — jeweils 0, wenn WPHub den Wert nicht liefert (z.B. Zone 2 nicht vorhanden). `z1WaterTempID`/`z2WaterTempID`/`dhwTempID` nutzen bewusst dieselben Feldnamen wie der mit HeishaMon abgestimmte gemeinsame `heatpump`-Vertragstyp (identisches Konzept: Zonen-/Warmwasser-Isttemperatur) — Dashboard liest denselben Feldnamen unabhängig vom liefernden Modul.

## 0.2.0 (Build 39) — 13.08.2026

- **Geklärt: `/deviceHistoryData` für dieses Konto gesperrt.** Am echten Konto getestet: 403 Code 4300 „Have no authority to the request" — dieselbe Rechte-Sperre, auf die frühere Untersuchungen der `/hphw`-Endpunkte schon gestoßen waren (`a2wOwnerFlg:false`), keine Frage des Content-Type-Headers. Der Transfer-Proxy-Weg (`/remote/v1/api/consumption`, seit Build 35 in Betrieb) bleibt damit der einzige funktionierende Zugang zu Verbrauchsdaten. Diagnosefunktion `ProbeDeviceHistory` wieder entfernt.

## 0.2.0 (Build 38) — 13.08.2026

- **Diagnose `ProbeDeviceHistory` (temporär).** Fund im bereits bestehenden, aktiv gepflegten IP-Symcon-Modul `demel42/IPSymconPanasonicComfortCloud`: Es nutzt für die Verbrauchshistorie den direkten Endpunkt `/deviceHistoryData` statt des Transfer-Proxys — ein Weg, den WPHub noch nie mit dem korrigierten Content-Type-Header getestet hat. `ProbeDeviceHistory` schreibt die rohe Antwort ins Systemprotokoll, um zu prüfen, ob dieser Weg reichhaltigere Daten liefert. Wird nach der Klärung wieder entfernt.

## 0.2.0 (Build 37) — 13.08.2026

- **Geklärt: keine erzeugte (thermische) Energie verfügbar.** Die Diagnose aus Build 36 wurde am echten Konto ausgewertet: Die Verbrauchsantwort enthält je Stunde nur `heat-/cool-/tankConsumption` (elektrisch) + Kosten + Außentemperatur, kein Feld für erzeugte Wärmeenergie. Für eine echte Wärmemengenmessung (und damit eine COP-Berechnung) fehlen Vor-/Rücklauftemperatur und Durchfluss — der Standardadapter liefert offenbar nur den elektrischen Verbrauch. Diagnosefunktion `ProbeConsumption` wieder entfernt.

## 0.2.0 (Build 36) — 13.08.2026

- **Diagnose `ProbeConsumption` (temporär).** Frage: Liefert die Cloud neben dem elektrischen Verbrauch (heat/cool/tankConsumption) auch die erzeugte thermische Energie (für eine COP-Berechnung)? Keine der drei geprüften Referenzimplementierungen wertet ein solches Feld aus — `ProbeConsumption` schreibt die rohe, unverarbeitete Verbrauchsantwort ins Systemprotokoll, um das direkt am echten Konto zu prüfen. Wird nach der Klärung wieder entfernt.

## 0.2.0 (Build 35) — 13.08.2026

- **Weitere Betriebsdaten aus dem Transfer-Statusabruf.** Neu: Eco-/Komfortmodus (die Eco-/Komfort-Knöpfe aus der App), Betriebsrichtung (Ruht/Umwälzpumpe/Warmwasser), Abtaubetrieb, Fehleranzahl und Fehlertext. Zusätzlich **Energieverbrauch des laufenden Tages** (Heizen/Kühlen/Warmwasser/Gesamt in kWh, neuer Endpunkt `/remote/v1/api/consumption` über denselben Transfer-Proxy) — rein informativ, **kein** Bestandteil des EMS-Vertrags (PowerID/EnergyID bleiben 0), da Tageswerte um Mitternacht auf 0 zurückspringen und damit kein kumulativer Zähler sind. Neues gemeinsames Profil `NRG.kWh`. Bewusst nicht angebunden: Wasserdruck, Bivalentpunkt, Anodenstatus — bei Dietmars Anlage (Standardadapter STD_ADP-TAW1) durchgehend 0, vermutlich für diese Hardware nicht unterstützt; sowie die statisch konfigurierten Eco-/Komfort-Sollwertgrenzen je Zone (Installateur-Einstellung, kein Live-Messwert). 11 neue Tests, 87 Prüfungen insgesamt grün.

## 0.2.0 (Build 34) — 13.08.2026

- **Steuerung ergänzt.** Flüsterbetrieb, Leistungsbetrieb, Urlaubstimer (nur Ein/Aus, kein Datumsbereich), Notbetrieb Warmwasser/Heizung sowie die Warmwasser- und Zonen-Solltemperatur lassen sich jetzt direkt in Symcon ändern (`RequestAction`) — nicht nur auslesen. Jeder Befehl geht über den gleichen Transfer-Proxy-Aufruf wie der Statusabruf (ein einzelnes Feld je Aufruf, nach dem Muster der Referenzimplementierungen). Die Zonen-Solltemperatur wählt automatisch das passende Feld (Heiz- oder Kühl-Sollwert) je nach aktueller Betriebsart. Bei einem fehlgeschlagenen Befehl bleibt die Variable auf dem letzten bestätigten Cloud-Stand, keine unbestätigten Werte in der Anzeige; eine Protokollzeile erklärt den Grund. **Bewusst nicht enthalten:** volle Betriebsart-Umschaltung (Heizen/Kühlen/Auto/Aus) — die verlangt laut Referenz den kompletten Zonen- und Speicherstatus im selben Aufruf und würde bei falscher Umsetzung riskieren, andere Zonen versehentlich ab- oder umzuschalten; ein echter Wochenplan sowie Urlaubszeiten mit Datumsbereich sind über die Cloud-API nach aktuellem Kenntnisstand nicht erreichbar (in keiner der geprüften Referenzimplementierungen vorhanden).

## 0.2.0 (Build 33) — 12.08.2026

- **Profil für Betriebsart ergänzt.** Die Variable zeigte bisher nur eine rohe Zahl. Neues Profil `WPHUB.Betriebsart` (Aus/Heizen/Kühlen/Auto Heizen/Auto Kühlen, nach `ExtendedOperationMode` der Referenzimplementierungen) macht den Wert lesbar.

## 0.2.0 (Build 32) — 12.08.2026

- **Reichhaltige Aquarea-Betriebsdaten.** Zusätzlich zu den bisherigen Basisdaten (Betriebsart, Warmwasser, Zonen-Sollwerte) liefert WPHub jetzt je Wärmepumpe: **Außentemperatur**, **Ist-Temperatur je Heizzone** (mit dem echten Zonennamen, z. B. „HK1"), **Flüsterbetrieb** (Aus/Stufe 1–3), **Leistungsbetrieb** (Aus/30/60/90 Minuten), **Urlaubstimer** sowie **Notbetrieb Warmwasser**/**Not-Heizbetrieb**. Datenquelle ist der Transfer-Proxy (`/remote/v1/app/common/transfer`, live mit Cache-Rückfall) — der bereits länger bekannte Weg, der bislang durchgehend mit „Missing required header parameter" (Code 4000) scheiterte. Der eigentliche Fehler: WPHub sendete `Content-Type: application/json;charset=utf-8`, während die POST-lastigen Routen offenbar strikt den exakten String `application/json` (ohne Charset-Zusatz) verlangen — gefunden durch Abgleich mit einer seit Redaktionsschluss aktiv gepflegten Drittanbieter-Implementierung. Der Zusatzabruf ist bewusst fehlertolerant: Schlägt er fehl, bleiben die betroffenen Variablen auf dem letzten bekannten Stand, der Rest der Aktualisierung läuft unbeeinflusst weiter. Die temporäre Diagnosefunktion `ProbeFull` wurde entfernt.

## 0.1.0 (Build 31) — 12.08.2026

- **Content-Type korrigiert (`;charset=utf-8` entfernt).** Breitere Recherche nach Referenzen aus anderen Smart-Home-Ökosystemen (Homey, openHAB, ioBroker) fand eine seit gestern aktiv gepflegte Homey-App (`mathieuChamois/com.panasonic.aquarea.community`), deren Client für exakt denselben Transfer-Proxy-Aufruf einen `Content-Type: application/json` **ohne** Charset-Zusatz sendet — WPHub hatte bisher `application/json;charset=utf-8`. Da POST-Routen bei API-Gateways oft strikt gegen den exakten Content-Type-String validieren (GET-Routen wie `device/group` dagegen meist nicht), ist das ein plausibler Kandidat für die bisherige „Missing required header parameter"-Meldung. `ProbeFull` testet die bestehenden vier Kandidaten jetzt mit dem korrigierten Header.

## 0.1.0 (Build 30) — 12.08.2026

- **ProbeFull: neuer Anlauf für die Aquarea-Reichdaten, diesmal nach Abgleich mit einer aktiv gepflegten Referenz.** Die Home-Assistant-Integration `cjaliaga/aioaquarea` (produktiv im Einsatz) zeigte zwei Dinge: (1) WPHub sendete bislang **keinen `Accept`-Header** — jetzt bei jedem Cloud-Aufruf global ergänzt (`accept: application/json; charset=utf-8`), ein möglicher Kandidat für die bisherige „Missing required header parameter"-Fehlermeldung. (2) Der Transfer-Proxy (`/remote/v1/app/common/transfer`) wird von der Referenz mit einer **einfacheren Body-Form** angesprochen als in Build 14 vermutet — nur `apiName`/`requestMethod`, **kein** `headerParam`. `ProbeFull` testet jetzt genau diese Form (live + cached) sowie `/hphw/deviceStatus` mit dem neuen Accept-Header. Rein diagnostisch, keine funktionale Änderung an den bestehenden, verifizierten Basisdaten.

## 0.1.0 (Build 27) — 12.08.2026

- **„Erreichbar" korrigiert.** Bisher basierte die Erreichbarkeit auf `connectionStatus == 1` — das war falsch: `connectionStatus:0` ist der **Normalzustand** (die Comfort-Cloud-App zeigt die Wärmepumpe nie als offline, und die Cloud liefert durchgehend aktuelle Werte). Die Variable stand dadurch dauerhaft fälschlich auf „nicht erreichbar". Jetzt gilt ein Gerät als erreichbar, sobald es in `device/group` mit aktuellen Daten erscheint; nur ein kompletter Cloud-Ausfall setzt es auf „nicht erreichbar". (Die Builds 22–26 dazwischen waren Diagnose-Zwischenstände auf der Suche nach dem Aquarea-Detail-Endpunkt `/hphw/deviceStatus`.)

## 0.1.0 (Build 21) — 11.08.2026

- **Aufräumen nach Datenquellen-Analyse.** Untersuchung, ob sich die reichhaltige Aquarea-Telemetrie (Außentemperatur, Vor-/Rücklauf, COP, Energie) anbinden lässt: Der frühere Consumer-Host `aquarea-smart.panasonic.com` existiert nicht mehr (keine DNS-Auflösung), und der überlebende Dienst `ac.smartcloud.panasonic.com` nutzt ein eigenes Login (nicht Panasonic ID/Auth0) — mit dem Comfort-Cloud-Token nicht erreichbar. Ergebnis: Über den Comfort-Cloud-Zugang bleibt es bei den Basisdaten aus `device/group` (Build 17). Die temporären Diagnosefunktionen wurden wieder entfernt; das Modul ist im verifizierten, sauberen Stand.

## 0.1.0 (Build 17) — 11.08.2026

- **Messwerte werden ausgelesen — direkt aus `device/group`.** Eine Diagnose (Zwischenstände Build 15/16) zeigte: Die A2W-Betriebsdaten liefert die Comfort Cloud bereits inline in der Geräteliste; der Transfer-Proxy (`/remote/v1/app/common/transfer`) und `/deviceStatus/{guid}` sind für Wärmepumpen gar nicht freigegeben (400/403). WPHub wertet die Werte jetzt direkt aus der Geräteantwort aus und legt je Wärmepumpe an: Erreichbar (aus `connectionStatus`), Betriebsart, Warmwasser Ist/Soll und -Betrieb (Speicher) sowie je Heizzone Solltemperatur und Betrieb. Werte bleiben als letzter bekannter Stand erhalten, wenn das Gerät offline ist. Der tote Transfer-Proxy-Code und die temporäre Diagnosefunktion wurden entfernt.

## 0.1.0 (Build 14) — 10.08.2026

- **Geräteliste lädt, A2W-Statusabruf repariert.** Nach der (in der offiziellen App bestätigten) Zustimmung liefert `device/group` die Wärmepumpe. Der anschließende Aquarea-Statusabruf über den Transfer-Proxy scheiterte aber mit „Missing required header parameter“ (Code 4000): Der Proxy verlangt im Body neben `apiName`/`requestMethod` eine `headerParam`-Liste. WPHub sendet sie jetzt exakt wie die offizielle App (Accept + Content-Type + Platzhalter) und hängt die Geräte-GUID roh an den `apiName`-Query.

## 0.1.0 (Build 13) — 10.08.2026

- **Zustimmung: Status-GET direkt vor dem PUT** (wie die offizielle App). Der PUT meldet zwar Erfolg (`{"result":0}`), aber die Geräteliste blieb bei 4103 — die App liest unmittelbar vor dem PUT auf derselben Sitzung einmal den Zustimmungsstatus; genau das ergänzt WPHub jetzt. **Bekannte Einschränkung:** Sollte die programmatische Zustimmung weiterhin nicht greifen, genügt der zuverlässige Weg — einmal die offizielle Comfort-Cloud-App öffnen, dort die neuen Bedingungen bestätigen, danach in WPHub „Anmelden und Wärmepumpen suchen“. Die Zustimmung ist ohnehin eine seltene, einmalige Aktion pro Bedingungs-Aktualisierung.

## 0.1.0 (Build 12) — 10.08.2026

- **Zustimmung wirkt jetzt (gleiche Sitzung weiternutzen).** Der Protokoll-Mitschnitt zeigte: Die Zustimmung wird serverseitig angenommen (PUT → `{"result":0}`), aber Build 10 hat danach das Token erneuert und sich neu angemeldet — und genau diese **frische** Sitzung kannte die eben erteilte Zustimmung nicht, weshalb die Geräteliste weiter 4103 meldete. Die offizielle App macht es anders: Nach der Zustimmung läuft **dieselbe** Sitzung direkt zur Geräteliste weiter, ohne Token-Wechsel. WPHub macht das jetzt genauso; die Sitzungserneuerung bleibt nur als Rückfall, falls die Zustimmung ausnahmsweise nicht sofort greift.

## 0.1.0 (Build 11) — 10.08.2026

- **Diagnose-Mitschnitt fürs Systemprotokoll:** Da die Zustimmung serverseitig erfolgreich ist, die Geräteliste aber weiter 4103 meldet, schreibt WPHub in diesem Fall einen Mitschnitt der letzten Cloud-Aufrufe (Pfad ohne Query, Statuscode, gekürzter Antwortkörper — keine Zugangsdaten, Token stehen nur in nicht protokollierten Headern) ins Systemprotokoll. Damit lässt sich die genaue Ursache eingrenzen, ohne im Debug-Fenster mitlesen zu müssen.

## 0.1.0 (Build 10) — 10.08.2026

- **Nach der Zustimmung frische Sitzung herstellen.** Die Bedingungen wurden akzeptiert (PUT erfolgreich, alle drei Typen), aber die Geräteliste blieb bei 4103. Grund (aus dem App-Verhalten abgeleitet): Die offizielle App verwirft nach 4103 ihre Sitzung und meldet sich neu an — erst mit einer frischen Sitzung wird die Zustimmung serverseitig für die Geräte-API wirksam. WPHub erneuert nach erfolgreicher Zustimmung jetzt das Zugangstoken und die App-Anmeldung (neue clientId) und lädt erst dann die Geräteliste.

## 0.1.0 (Build 9) — 10.08.2026

- **Zustimmung: Dokumente je Typ abrufen (wie die App).** Der ungefilterte Dokumenten-Abruf lieferte 7 Einträge (alle Typen/Sprachvarianten gemischt, teils ohne Versionsnummer) — die offizielle App fragt statt dessen **je Typ (1/2/3) einzeln** ab und bestätigt genau die eine Version pro Typ. Genau das macht WPHub jetzt auch: Einträge ohne Versionsnummer werden übersprungen, ein einzelner nicht zutreffender Typ (z. B. Servicevertrag, nur Türkei) wird toleriert. Die rohe Dokumenten-Antwort und der exakte PUT-Body landen jetzt im Instanz-Debug, damit ein etwaiger Rest-Fehler eindeutig sichtbar ist.

## 0.1.0 (Build 8) — 10.08.2026

- **Zustimmung endgültig korrekt (echtes App-Schema):** Das Body-Format der v2-Zustimmung ist in keiner Community-Bibliothek gelöst; ich habe es aus der offiziellen Android-App (aktuelle Version, dekompiliert) direkt abgeleitet. Der richtige Ablauf: erst `GET /auth/v2/agreement/documents?language=0&includeContent=0` (liefert je Dokument Typ **und Versionsnummer**), dann `PUT /auth/v2/agreement/status` mit `{"agreementList":[{"type":<int>,"version":"<str>"}]}` — also die konkrete Version bestätigen, nicht ein generischer „akzeptiert“-Status. Genau das Fehlen der Version war der Grund für „Missing required body parameter“. Das ganze Raten aus Build 6/7 ist durch diesen einen belegten Aufruf ersetzt.

## 0.1.0 (Build 7) — 10.08.2026

- **Zustimmung v2 selbstlernend:** Build 6 fand die reale Route (`/auth/v2/agreement/status/`), deren Body-Schema aber nirgends dokumentiert ist (400, Code 4002). Die Schaltfläche liest jetzt zuerst den v2-Zustimmungsstatus (rein lesend — die Antwort verrät die Feldnamen), spiegelt daraus den Bestätigungs-Body und probiert erst danach wenige statische Kandidaten; weitergeschaltet wird nur bei nachweislich wirkungslosen Antworten (4002/Route unbekannt). Scheitert alles, enthält die Fehlermeldung die rohe v2-Statusantwort zur Analyse.

## 0.1.0 (Build 6) — 10.08.2026

- **Zustimmungs-Endpunkt repariert (Kandidatenleiter):** Der historisch dokumentierte Weg `PUT /auth/agreement/status/` (App-Ära 1.20) existiert an der heutigen API nicht mehr — AWS antwortet mit 403 „Missing Authentication Token“ (= Route unbekannt). Da der aktuelle Accept-Pfad der offiziellen App nirgends öffentlich mitgeschnitten ist, probiert die Schaltfläche jetzt eine kleine Kandidatenleiter (ohne Trailing-Slash, Typ-ID im Pfad, POST, v2-Pfad) — der nächste Versuch fährt nur, wenn der vorige eindeutig „Route unbekannt“ war und damit nichts bewirkt hat; bei Erfolg oder echtem Fehler stoppt die Leiter sofort. Das Debug-Protokoll der Instanz vermerkt, welche Variante funktioniert hat. Führt keine Variante zum Ziel, verweist die Meldung auf die Bestätigung in der offiziellen App.

## 0.1.0 (Build 5) — 10.08.2026

- **Zustimmung zu aktualisierten Panasonic-Bedingungen (Cloud-Fehlercode 4103):** Der dritte Live-Test bestand die komplette Anmeldung, scheiterte dann aber an „Terms and/or Policies have been updated“ — Panasonic verlangt nach Aktualisierungen von Nutzungsbedingungen/Datenschutzerklärung eine erneute Zustimmung des Kontos. Neu: Schaltfläche „📜 Aktualisierte Bedingungen akzeptieren“ im Formular (prüft beide Zustimmungstypen und bestätigt nur Offenes), eigener Instanzstatus 202 „Zustimmung erforderlich“ mit Protokollhinweis (einmalig, nicht je Zyklus). Bewusste Entscheidung: WPHub stimmt **nie automatisch** zu — die Zustimmung ist eine Entscheidung des Kontoinhabers, alternativ genügt ein Öffnen der offiziellen App. Prüfstand um die 4103-Szenarien erweitert (55 Prüfungen).

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
