<?php

require_once __DIR__ . '/libs/ComfortCloudClient.php';

// NRG-Stack WPHub -- Waermepumpen-Cloud-Anbindungen, Start mit Panasonic
// Comfort Cloud (Cloud-Alternative zu HeishaMon fuer Nutzer ohne HeishaMon-
// Platine). Weitere Hersteller (Mitsubishi MELCloud, Viessmann, Vaillant
// myVaillant, Stiebel Eltron ISG, ...) folgen spaeter ueber die Community,
// sobald sich Nutzer mit passender Hardware zum Testen finden -- analog dazu,
// wie Tessie/TibberGridReward auch mit einem Hersteller/Dienst gestartet sind.
//
// Vertrag WPHUB_GetFunctions() liefert Type=>'heatpump', konsistent zu
// HeishaMons Form (siehe DG65/NRGHeishaMon, ems-integration-Branch,
// HeishaMon/module.php::GetFunctions(), dort contractVersion 1.2) -- damit ist
// fuer EMS die Datenquelle (lokal via HeishaMon vs. Cloud via WPHub)
// austauschbar. PowerID/EnergyID sind derzeit 0: Die Comfort Cloud liefert
// keine Momentanleistung, und ihre Verbrauchswerte sind Tageswerte (springen
// auf 0 zurueck) -- nach Verbund-Regel "Energie nur aus kumulativen Zaehlern"
// wird die Groesse dann weggelassen, nicht hochgerechnet.
//
// Credentials-Konvention (SUITE.md): Handshake/Token bevorzugt, Passwort nur
// einmalig fuer den Login-Handshake, danach NICHT speichern -- nur das
// resultierende Token-Buendel in RegisterAttributeString (NICHT Property,
// Attribute erscheinen nicht im Formular). IPS verschluesselt Attribute NICHT
// at rest -- "sicher" heisst hier nur "nicht im Formular/Log sichtbar", so
// auch gegenueber dem Nutzer kommunizieren.

class WPHub extends IPSModule
{
    // Stand des "Neu in Version"-Panels; bei jeder Version mit Neuigkeiten
    // hochziehen, dann erscheint das Panel wieder (pro Version dismissible).
    const NEWS_VERSION = '0.2.0';

    // Comfort Cloud meldet 126 als "kein gueltiger Messwert".
    const CC_INVALID_TEMPERATURE = 126;

    // Zustimmungstypen der Comfort Cloud (Typ 3 = Servicevertrag nur Tuerkei).
    const AGREEMENT_TERMS   = 1;
    const AGREEMENT_PRIVACY = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('WPHUB_Active', false);
        $this->RegisterPropertyInteger('WPHUB_Interval', 60);

        // Panasonic Comfort Cloud Login (E-Mail + Passwort NUR fuer den
        // einmaligen Handshake-Aufruf, siehe Login(), danach geleert).
        $this->RegisterPropertyString('CC_Email', '');
        $this->RegisterPropertyString('CC_Password', ''); // PasswordTextBox im Formular
        // Versionsnummer der offiziellen Comfort-Cloud-App: Die API weist zu
        // alte Versionen ab (Fehlercode 4106). Das Modul ermittelt die
        // aktuelle Version dann selbst (Play Store/AppBrain) und merkt sie
        // sich im Attribut CC_AppVersionAuto -- dieses Feld ist nur der
        // Notnagel, falls die automatische Ermittlung nicht funktioniert.
        $this->RegisterPropertyString('CC_AppVersion', '');

        // Ergebnis des Handshakes -- NICHT das Passwort selbst.
        $this->RegisterAttributeString('CC_Token', '');
        $this->RegisterAttributeString('CC_DeviceList', '[]');
        // Zuletzt automatisch ermittelte App-Version (hat Vorrang).
        $this->RegisterAttributeString('CC_AppVersionAuto', '');
        // Zuletzt bestaetigter Stand des "Neu in Version"-Panels.
        $this->RegisterAttributeString('SeenNews', '');

        $this->RegisterTimer('WPHUB_UpdateTimer', 0, 'WPHUB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureSharedProfiles();

        $active   = $this->ReadPropertyBoolean('WPHUB_Active');
        $interval = max(30, $this->ReadPropertyInteger('WPHUB_Interval'));
        $hasToken = $this->tokenBundle() !== null;

        if (!$active) {
            $this->SetTimerInterval('WPHUB_UpdateTimer', 0);
            $this->SetStatus(104);
        } elseif (!$hasToken) {
            // Aktiv geschaltet, aber noch nie (erfolgreich) angemeldet.
            $this->SetTimerInterval('WPHUB_UpdateTimer', 0);
            $this->SetStatus(201);
        } else {
            $this->SetTimerInterval('WPHUB_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // "Neu in Version"-Panel vorn einhaengen, solange diese Version noch
        // nicht bestaetigt wurde (Dismiss NUR via Attribut + UpdateFormField,
        // kein IPS_SetProperty/ApplyChanges -- Store-Review-Regel).
        if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {
            array_unshift($form['elements'], [
                'type'     => 'ExpansionPanel',
                'name'     => 'NewsPanel',
                'caption'  => '🆕 Neu in Version ' . self::NEWS_VERSION,
                'expanded' => true,
                'items'    => [
                    ['type' => 'Label', 'caption' => '• Reichhaltige Betriebsdaten: Aussentemperatur, Ist-Temperatur je Heizzone, Fluester- und Leistungsbetrieb, Urlaubstimer, Notbetriebe Warmwasser/Heizung'],
                    ['type' => 'Label', 'caption' => '• Diese Werte kommen zusaetzlich zu den bisherigen Basisdaten (Betriebsart, Warmwasser, Sollwerte); der Zusatzabruf kann in seltenen Faellen ausbleiben, dann bleibt der letzte bekannte Stand erhalten'],
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPHUB_AckNews($id);'],
                ],
            ]);
        }

        return json_encode($form);
    }

    // Bestaetigt das "Neu in Version"-Panel fuer die aktuelle Version.
    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * Fuehrt den Comfort-Cloud-Login-Handshake aus (Auth0/PKCE), speichert
     * danach NUR das Token-Buendel im Attribut und leert das Passwort-
     * Property. Anschliessend werden die Geraete des Kontos abgerufen.
     * Rueckmeldungen gehen ins Formularfeld und (ohne Geheimnisse) ins
     * Systemprotokoll -- Passwort/Token nie in Log oder Anzeige.
     */
    public function Login()
    {
        $say = function (string $m) {
            $this->UpdateFormField('CC_Result', 'caption', $m);
            $this->UpdateFormField('CC_Result', 'visible', true);
            trigger_error('WPHUB_Login #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        };

        $email = trim($this->ReadPropertyString('CC_Email'));
        $pass  = (string)$this->ReadPropertyString('CC_Password');
        if ($email === '' || $pass === '') {
            $say('❌ Bitte zuerst E-Mail und Passwort eintragen und übernehmen, dann anmelden.');
            return;
        }

        $client = $this->ccClient();
        $bundle = $client->login($email, $pass);
        if ($bundle === null) {
            $say('❌ ' . $client->getLastError());
            return;
        }
        $ok = $client->accLogin($bundle);
        if (!$ok && $this->tryAppVersionRefresh($client)) {
            $ok = $client->accLogin($bundle);
        }
        if (!$ok) {
            $say('❌ ' . $client->getLastError());
            return;
        }

        // Erfolg: Token-Buendel sichern, Passwort verwerfen (Property UND
        // offenes Formular), dann Status/Timer neu aufsetzen.
        $this->WriteAttributeString('CC_Token', json_encode($bundle));
        IPS_SetProperty($this->InstanceID, 'CC_Password', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('CC_Password', 'value', '');

        $devices = $this->refreshDevices($bundle, $client);
        if ($devices === null) {
            if ($client->agreementRequired()) {
                $this->SetStatus(202);
                $say("✅ Angemeldet, Zugangsschlüssel gespeichert, Passwort verworfen.\n📜 Panasonic hat aber Nutzungsbedingungen/Datenschutzerklärung aktualisiert und verlangt eine erneute Zustimmung Deines Kontos. Bitte unten „Aktualisierte Bedingungen akzeptieren“ klicken (oder einmal die offizielle Comfort-Cloud-App öffnen und dort bestätigen).");
                return;
            }
            $say('✅ Angemeldet, Zugangsschlüssel gespeichert, Passwort verworfen. Die Geräteliste konnte aber noch nicht geladen werden (' . $client->getLastError() . ') — sie wird beim nächsten Aktualisierungslauf erneut versucht.');
            return;
        }
        if (count($devices) === 0) {
            $say('✅ Angemeldet, Zugangsschlüssel gespeichert, Passwort verworfen. Im Konto wurde aber keine Aquarea-Wärmepumpe gefunden. Klimageräte bindet WPHub bewusst nicht ein.');
            return;
        }
        $lines = ['✅ Angemeldet, Zugangsschlüssel gespeichert, Passwort verworfen. Gefundene Wärmepumpen:'];
        foreach ($devices as $d) {
            $lines[] = '   • ' . $d['name'] . ($d['reachable'] ? '' : ' (derzeit nicht erreichbar)');
        }
        $say(implode("\n", $lines));
    }

    /**
     * Bestaetigt Panasonics aktualisierte Nutzungsbedingungen/Datenschutz-
     * erklaerung fuer das angemeldete Konto -- ausschliesslich auf Klick der
     * Formular-Schaltflaeche, nie automatisch: Die Zustimmung ist eine
     * Entscheidung des Kontoinhabers, nicht des Moduls.
     */
    public function AcceptAgreements()
    {
        $say = function (string $m) {
            $this->UpdateFormField('CC_Result', 'caption', $m);
            $this->UpdateFormField('CC_Result', 'visible', true);
            trigger_error('WPHUB_AcceptAgreements #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        };
        $bundle = $this->ensureToken();
        if ($bundle === null) {
            $say('❌ Keine gültige Anmeldung — bitte zuerst anmelden.');
            return;
        }
        $this->doAcceptAgreements($this->ccClient(), $bundle, $say);
    }

    /** Kern von AcceptAgreements, testbar mit injiziertem Client. */
    private function doAcceptAgreements(WPHUB_ComfortCloudClient $client, array $bundle, callable $say): void
    {
        // Die aktuellen Bedingungs-Dokumente inkl. Versionsnummern holen
        // (genau wie die offizielle App: je Typ ein Abruf) und exakt diese
        // Versionen bestätigen.
        $docs = $client->collectAgreementVersions($bundle);
        if ($docs === null) {
            $say('❌ Die aktuellen Bedingungen konnten nicht abgerufen werden: ' . $client->getLastError());
            return;
        }
        if (count($docs) === 0) {
            $say('ℹ️ Die Cloud meldet keine offenen Bedingungen. Versuche direkt, die Geräteliste zu laden …');
        } elseif (!$client->putAgreementStatus($bundle, $docs)) {
            $say('❌ Die Zustimmung konnte nicht übermittelt werden: ' . $client->getLastError());
            return;
        }

        $names = [
            self::AGREEMENT_TERMS   => 'Nutzungsbedingungen',
            self::AGREEMENT_PRIVACY => 'Datenschutzerklärung',
            3                        => 'Servicevertrag',
        ];
        $accepted = [];
        foreach ($docs as $d) {
            $accepted[] = $names[$d['type']] ?? ('Dokument ' . $d['type']);
        }

        // Wie die offizielle App: nach der Zustimmung läuft dieselbe Sitzung
        // weiter zur Geräteliste — KEIN Token-Wechsel (die Zustimmung ist an
        // die Sitzung gebunden, die sie übermittelt hat). Das Token-Bündel im
        // Attribut aktualisieren, damit der reguläre Update-Zyklus dieselbe
        // Sitzung nutzt.
        $this->WriteAttributeString('CC_Token', json_encode($bundle));
        $devices = $this->refreshDevices($bundle, $client);

        if ($devices === null && $client->agreementRequired()) {
            // Fallback (nur falls die Zustimmung nicht sofort greift): eine
            // frische Sitzung herstellen und noch einmal versuchen.
            $refreshed = $client->refresh($bundle);
            if ($refreshed !== null) {
                $bundle = $refreshed;
            }
            $client->accLogin($bundle);
            $this->WriteAttributeString('CC_Token', json_encode($bundle));
            $devices = $this->refreshDevices($bundle, $client);
        }

        if ($devices === null) {
            // Diagnose ins Systemprotokoll (keine Geheimnisse) -- macht sichtbar,
            // welche Versionen bestaetigt wurden und wie die Cloud geantwortet hat.
            $this->LogMessage("WPHub-Diagnose Zustimmung:\n" . $client->getApiTrace(), KL_WARNING);
            $say('⚠️ Zustimmung übermittelt (' . (count($accepted) ? implode(', ', $accepted) : 'nichts offen') . '), aber die Geräteliste lässt sich weiterhin nicht laden: ' . $client->getLastError() . ' — Details stehen im Systemprotokoll.');
            return;
        }
        $this->SetStatus(102);
        $lines = [count($accepted)
            ? '✅ Bestätigt: ' . implode(', ', $accepted) . '. Gefundene Wärmepumpen:'
            : '✅ Es war laut Cloud nichts mehr offen. Gefundene Wärmepumpen:'];
        if (count($devices) === 0) {
            $lines[] = '   (keine Aquarea-Wärmepumpe im Konto gefunden)';
        }
        foreach ($devices as $d) {
            $lines[] = '   • ' . $d['name'] . ($d['reachable'] ? '' : ' (derzeit nicht erreichbar)');
        }
        $say(implode("\n", $lines));
    }

    /**
     * Zyklische Aktualisierung: Token pruefen/erneuern, Geraeteliste und
     * A2W-Status abrufen, Variablen pflegen.
     */
    public function Update()
    {
        if (!$this->ReadPropertyBoolean('WPHUB_Active')) {
            return;
        }
        $bundle = $this->ensureToken();
        if ($bundle === null) {
            return; // Status 201 gesetzt, Meldung im Protokoll
        }
        $client = $this->ccClient();
        if ($this->refreshDevices($bundle, $client) === null) {
            // Cloud nicht erreichbar: vorhandene Geraete als unerreichbar
            // markieren, Variablen/Historie bleiben unangetastet.
            $this->markAllUnreachable();
            if ($client->agreementRequired()) {
                // Nur beim Statuswechsel protokollieren, nicht in jedem Zyklus.
                if ($this->GetStatus() !== 202) {
                    $this->LogMessage('Panasonic verlangt eine erneute Zustimmung zu Nutzungsbedingungen/Datenschutzerklärung — im WPHub-Formular „Aktualisierte Bedingungen akzeptieren“ klicken oder einmal die offizielle App öffnen.', KL_WARNING);
                }
                $this->SetStatus(202);
                return;
            }
            $this->LogMessage('Aktualisierung fehlgeschlagen: ' . $client->getLastError(), KL_WARNING);
            return;
        }
        $this->SetStatus(102);
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu HeishaMons
     * GetFunctions() (Type=>'heatpump', contractVersion 1.2). Ein Eintrag je
     * gefundener Waermepumpe. PowerID/EnergyID = 0: siehe Kopfkommentar --
     * die Cloud liefert (noch) keine vertragstaugliche Leistung/Energie.
     */
    public function GetFunctions()
    {
        $devices = json_decode($this->ReadAttributeString('CC_DeviceList'), true);
        if (!is_array($devices)) {
            $devices = [];
        }

        $out = [];
        foreach ($devices as $d) {
            $reachableID = @$this->GetIDForIdent(($d['prefix'] ?? '') . 'Erreichbar');
            $out[] = [
                'contractVersion' => '1.2',
                'Type'            => 'heatpump',
                'Caption'         => $d['name'] ?? 'Waermepumpe',
                'PowerID'         => 0,
                'EnergyID'        => 0,
                'Measured'        => false,
                'unit'            => 'W',
                'reachable'       => ($reachableID === false) ? (bool)($d['reachable'] ?? false) : (bool)GetValue($reachableID),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function ccClient(): WPHUB_ComfortCloudClient
    {
        // Vorrang: zuletzt automatisch ermittelte Version -> manueller
        // Notnagel aus dem Formular -> Code-Standard (Stand 08/2026).
        $appVersion = trim($this->ReadAttributeString('CC_AppVersionAuto'));
        if ($appVersion === '') {
            $appVersion = trim($this->ReadPropertyString('CC_AppVersion'));
        }
        if ($appVersion === '') {
            $appVersion = '4.4.0';
        }
        return new WPHUB_ComfortCloudClient($appVersion, function (string $topic, string $text) {
            $this->SendDebug('ComfortCloud/' . $topic, $text, 0);
        });
    }

    /**
     * Nach einer 4106-Ablehnung (App-Version zu alt): aktuelle Version der
     * offiziellen App ermitteln, merken und true liefern -- der Aufrufer
     * wiederholt dann genau einen Versuch.
     */
    private function tryAppVersionRefresh(WPHUB_ComfortCloudClient $client): bool
    {
        if (!$client->versionRejected()) {
            return false;
        }
        $new = $client->refreshAppVersion();
        if ($new === null) {
            return false;
        }
        $this->WriteAttributeString('CC_AppVersionAuto', $new);
        $this->LogMessage('Comfort-Cloud-App-Version automatisch auf ' . $new . ' aktualisiert.', KL_NOTIFY);
        return true;
    }

    /** Token-Buendel aus dem Attribut, null wenn (noch) keines da ist. */
    private function tokenBundle(): ?array
    {
        $bundle = json_decode($this->ReadAttributeString('CC_Token'), true);
        if (!is_array($bundle) || ($bundle['accessToken'] ?? '') === '') {
            return null;
        }
        return $bundle;
    }

    /**
     * Liefert ein gueltiges Token-Buendel; erneuert es bei Bedarf ueber das
     * Refresh-Token. Schlaegt das fehl, ist eine Neuanmeldung noetig ->
     * Status 201 + Protokollhinweis (das Modul kann sie mangels Passwort
     * bewusst nicht selbst ausloesen).
     */
    private function ensureToken(): ?array
    {
        $bundle = $this->tokenBundle();
        if ($bundle === null) {
            $this->SetStatus(201);
            return null;
        }
        if ((int)($bundle['expiresAt'] ?? 0) - 300 > time()) {
            return $bundle;
        }

        $client = $this->ccClient();
        $new = $client->refresh($bundle);
        if ($new === null) {
            $this->LogMessage('Comfort-Cloud-Zugangsschlüssel abgelaufen und Erneuerung fehlgeschlagen (' . $client->getLastError() . ') — bitte im Formular neu anmelden.', KL_WARNING);
            $this->SetStatus(201);
            $this->SetTimerInterval('WPHUB_UpdateTimer', 0);
            return null;
        }
        if (($new['clientId'] ?? '') === '') {
            $client->accLogin($new); // best effort, Fehler ist hier nicht fatal
        }
        $this->WriteAttributeString('CC_Token', json_encode($new));
        return $new;
    }

    /**
     * Geraeteliste laden und je Aquarea-Waermepumpe die Variablen pflegen.
     * Die Betriebsdaten stehen INLINE in der device/group-Antwort (kein
     * separater Statusabruf). Liefert die Geraeteliste oder null bei Cloud-
     * Fehler (dann bleibt der letzte bekannte Stand unangetastet).
     */
    private function refreshDevices(array $bundle, WPHUB_ComfortCloudClient $client): ?array
    {
        $groups = $client->getGroups($bundle);
        if ($groups === null && $this->tryAppVersionRefresh($client)) {
            $groups = $client->getGroups($bundle);
        }
        if ($groups === null) {
            return null;
        }

        $devices = [];
        foreach (($groups['groupList'] ?? []) as $group) {
            foreach (($group['deviceList'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $guid = (string)($entry['deviceGuid'] ?? '');
                if ($guid === '') {
                    continue;
                }
                // Nur Aquarea-Waermepumpen: deviceType "2" bzw. Eintraege mit
                // Zonen-/Speicherstatus. Klimageraete (anderer deviceType, mit
                // 'parameters') bindet WPHub bewusst nicht ein.
                $isA2W = ((string)($entry['deviceType'] ?? '') === '2')
                    || isset($entry['zoneStatus']) || isset($entry['tankStatus']);
                if (!$isA2W) {
                    $this->SendDebug('Geraete', 'Übersprungen (kein A2W): ' . ($entry['deviceName'] ?? $guid), 0);
                    continue;
                }

                $name = (string)($entry['deviceName'] ?? ('Wärmepumpe ' . substr($guid, 0, 8)));
                $prefix = $this->devicePrefix($guid);
                // Erreichbar = Geraet ist in device/group vorhanden und liefert
                // aktuelle Daten. connectionStatus:0 ist der NORMALZUSTAND (die
                // App zeigt das Geraet nie als "offline"), taugt also NICHT als
                // Erreichbarkeitsindikator. Faellt der ganze Cloud-Abruf aus,
                // setzt markAllUnreachable() die Variablen auf false.
                $reachable = true;

                // Reichhaltiger Status (Aussentemperatur, Zonen-Ist, Fluester-/
                // Leistungsbetrieb, Urlaubstimer, Notbetriebe) ueber den
                // Transfer-Proxy -- zusaetzlich zu den Basisdaten aus
                // device/group. Schlaegt der Zusatzabruf fehl (das ist eine
                // inoffizielle Route, kann instabil sein), bleiben die
                // betroffenen Variablen einfach auf dem letzten bekannten
                // Stand; der Rest der Aktualisierung ist davon nicht betroffen.
                $status = $client->getDeviceStatus($bundle, $guid);

                $this->maintainDeviceVariables($prefix, $name, $entry, $reachable, $status);

                $devices[] = [
                    'guid'      => $guid,
                    'name'      => $name,
                    'prefix'    => $prefix,
                    'reachable' => $reachable,
                ];
            }
        }

        $this->WriteAttributeString('CC_DeviceList', json_encode($devices));
        return $devices;
    }

    /**
     * Variablen eines Geraets anlegen/pflegen. $dev ist der Geraeteeintrag aus
     * der device/group-Antwort. Vorhandene Messwerte werden auch bei
     * connectionStatus 0 als letzter bekannter Stand geschrieben; die
     * Erreichbarkeit spiegelt connectionStatus wider.
     */
    private function maintainDeviceVariables(string $prefix, string $name, array $dev, bool $reachable, ?array $status = null): void
    {
        $pos = 0;
        $this->MaintainVariable($prefix . 'Erreichbar', $name . ': Erreichbar', VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue($prefix . 'Erreichbar', $reachable);

        if (isset($dev['operationMode'])) {
            $this->MaintainVariable($prefix . 'Betriebsart', $name . ': Betriebsart', VARIABLETYPE_INTEGER, 'WPHUB.Betriebsart', $pos++, true);
            $this->SetValue($prefix . 'Betriebsart', (int)$dev['operationMode']);
        }

        if (is_array($status) && isset($status['outdoorNow']) && $this->isValidTemperature($status['outdoorNow'])) {
            $this->MaintainVariable($prefix . 'Aussentemperatur', $name . ': Außentemperatur', VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Aussentemperatur', (float)$status['outdoorNow']);
        }

        // Warmwasserspeicher: temperatureNow = Ist, temperature = Sollwert.
        $tank = $dev['tankStatus'] ?? null;
        if (is_array($tank)) {
            if (isset($tank['temperatureNow']) && $this->isValidTemperature($tank['temperatureNow'])) {
                $this->MaintainVariable($prefix . 'Warmwasser', $name . ': Warmwasser', VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'Warmwasser', (float)$tank['temperatureNow']);
            }
            if (isset($tank['temperature']) && $this->isValidTemperature($tank['temperature'])) {
                $this->MaintainVariable($prefix . 'WarmwasserSoll', $name . ': Warmwasser Sollwert', VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'WarmwasserSoll', (float)$tank['temperature']);
            }
            if (isset($tank['operationStatus'])) {
                $this->MaintainVariable($prefix . 'WarmwasserBetrieb', $name . ': Warmwasser aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'WarmwasserBetrieb', (int)$tank['operationStatus'] === 1);
            }
        }

        // Ist-Temperaturen je Zone aus dem Transfer-Statusabruf, nach zoneId
        // zugeordnet (dort steckt auch der echte Zonenname, z.B. "HK1").
        $statusZones = [];
        foreach ((is_array($status) ? ($status['zoneStatus'] ?? []) : []) as $sz) {
            if (is_array($sz) && isset($sz['zoneId'])) {
                $statusZones[(int)$sz['zoneId']] = $sz;
            }
        }

        // Heizzonen: temperature = Solltemperatur der Zone, operationStatus = aktiv.
        foreach (($dev['zoneStatus'] ?? []) as $zone) {
            if (!is_array($zone) || !isset($zone['zoneId'])) {
                continue;
            }
            $zid = (int)$zone['zoneId'];
            $sz = $statusZones[$zid] ?? null;
            $zname = (is_array($sz) && ($sz['zoneName'] ?? '') !== '') ? (string)$sz['zoneName'] : ('Zone ' . $zid);
            if (isset($zone['temperature']) && $this->isValidTemperature($zone['temperature'])) {
                $this->MaintainVariable($prefix . 'Zone' . $zid . 'Soll', $name . ': ' . $zname . ' Solltemperatur', VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'Zone' . $zid . 'Soll', (float)$zone['temperature']);
            }
            if (is_array($sz) && isset($sz['temperatureNow']) && $this->isValidTemperature($sz['temperatureNow'])) {
                $this->MaintainVariable($prefix . 'Zone' . $zid . 'Ist', $name . ': ' . $zname . ' Isttemperatur', VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'Zone' . $zid . 'Ist', (float)$sz['temperatureNow']);
            }
            if (isset($zone['operationStatus'])) {
                $this->MaintainVariable($prefix . 'Zone' . $zid . 'Betrieb', $name . ': ' . $zname . ' aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'Zone' . $zid . 'Betrieb', (int)$zone['operationStatus'] === 1);
            }
        }

        // Betriebsmodi aus dem Transfer-Statusabruf (geraeteweit, nicht je Zone).
        if (is_array($status)) {
            if (isset($status['quietMode'])) {
                $this->MaintainVariable($prefix . 'Fluesterbetrieb', $name . ': Flüsterbetrieb', VARIABLETYPE_INTEGER, 'WPHUB.Fluesterbetrieb', $pos++, true);
                $this->SetValue($prefix . 'Fluesterbetrieb', (int)$status['quietMode']);
            }
            if (isset($status['powerful'])) {
                $this->MaintainVariable($prefix . 'Leistungsbetrieb', $name . ': Leistungsbetrieb', VARIABLETYPE_INTEGER, 'WPHUB.Leistungsbetrieb', $pos++, true);
                $this->SetValue($prefix . 'Leistungsbetrieb', (int)$status['powerful']);
            }
            if (isset($status['holidayTimer'])) {
                $this->MaintainVariable($prefix . 'Urlaubstimer', $name . ': Urlaubstimer aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'Urlaubstimer', (int)$status['holidayTimer'] === 1);
            }
            if (isset($status['forceDHW'])) {
                $this->MaintainVariable($prefix . 'NotbetriebWarmwasser', $name . ': Notbetrieb Warmwasser aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'NotbetriebWarmwasser', (int)$status['forceDHW'] === 1);
            }
            if (isset($status['forceHeater'])) {
                $this->MaintainVariable($prefix . 'NotHeizbetrieb', $name . ': Not-Heizbetrieb aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'NotHeizbetrieb', (int)$status['forceHeater'] === 1);
            }
        }
    }

    /** Bei Cloud-Ausfall: alle bekannten Geraete als unerreichbar markieren. */
    private function markAllUnreachable(): void
    {
        $devices = json_decode($this->ReadAttributeString('CC_DeviceList'), true);
        if (!is_array($devices)) {
            return;
        }
        foreach ($devices as &$d) {
            $d['reachable'] = false;
            $ident = ($d['prefix'] ?? '') . 'Erreichbar';
            if (@$this->GetIDForIdent($ident) !== false) {
                $this->SetValue($ident, false);
            }
        }
        unset($d);
        $this->WriteAttributeString('CC_DeviceList', json_encode($devices));
    }

    /** Stabiler Ident-Praefix je Geraet, abgeleitet aus der Geraete-GUID. */
    private function devicePrefix(string $guid): string
    {
        return 'HP' . strtoupper(substr(md5($guid), 0, 6)) . '_';
    }

    /** 126 ist der Comfort-Cloud-Marker fuer "kein Messwert". */
    private function isValidTemperature($value): bool
    {
        return is_numeric($value) && (int)$value !== self::CC_INVALID_TEMPERATURE && (float)$value > -100 && (float)$value < 200;
    }

    /**
     * Gemeinsame NRG.*-Profile: nur anlegen, wenn sie fehlen -- ein anderes
     * NRG-Stack-Modul koennte sie bereits fuehren, dann wird dessen
     * Definition NICHT ueberschrieben (Verbund-Konvention 24.07.2026).
     */
    private function ensureSharedProfiles(): void
    {
        if (!IPS_VariableProfileExists('NRG.Celsius')) {
            IPS_CreateVariableProfile('NRG.Celsius', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Celsius', '', ' °C');
            IPS_SetVariableProfileDigits('NRG.Celsius', 1);
        }
        // Modulspezifisch (kein NRG.*-Praefix): Werte aus dem A2W-Transfer-
        // Statusabruf, die kein anderes NRG-Stack-Modul teilt.
        if (!IPS_VariableProfileExists('WPHUB.Betriebsart')) {
            IPS_CreateVariableProfile('WPHUB.Betriebsart', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 1, 'Heizen', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 2, 'Kühlen', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 3, 'Auto Heizen', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 4, 'Auto Kühlen', '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.Fluesterbetrieb')) {
            IPS_CreateVariableProfile('WPHUB.Fluesterbetrieb', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 1, 'Stufe 1', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 2, 'Stufe 2', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 3, 'Stufe 3', '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.Leistungsbetrieb')) {
            IPS_CreateVariableProfile('WPHUB.Leistungsbetrieb', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 1, '30 Minuten', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 2, '60 Minuten', '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 3, '90 Minuten', '', -1);
        }
    }
}
