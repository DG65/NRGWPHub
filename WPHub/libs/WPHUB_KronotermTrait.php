<?php

/**
 * Kronoterm-Cloud-Zweig des WPHub (Anmeldung, Abruf, Variablenpflege, Zusatzwerte).
 * Als Trait ausgelagert, damit module.php nicht weiter waechst; die Methoden
 * greifen auf die Modulumgebung (Properties, Attribute, MaintainVariable) zu.
 * Grundlage: Kronoterm-Anbindung von Dirk Allard (Forum: dallard), ueberarbeitet.
 */
trait WPHUB_KronotermTrait
{
    // ------------------------------------------------------------------
    // Kronoterm Cloud
    // ------------------------------------------------------------------
    /**
     * Gespeicherte Zugangsdaten (Attribut, nie Property): [Benutzername, Passwort] oder null.
     * IPS verschluesselt Attribute nicht: "sicher" heisst nur "nicht im Formular/Log sichtbar".
     */
    /**
     * Abfrageintervall der Kronoterm-Cloud in Sekunden: der im Formular
     * eingestellte Wert, mindestens 60 s. Kronoterm nennt keine Grenzen; die
     * 300 s der ersten Fassung stammten aus der Home-Assistant-Referenz und
     * wurden dem Nutzer verschwiegen angehoben (0.18.1: Formularwert gilt).
     */
    private function kronotermPollSeconds(): int
    {
        return max(60, $this->ReadPropertyInteger('WPHUB_Interval'));
    }

    private function kronotermCredentials(): ?array
    {
        $data = json_decode($this->ReadAttributeString('KR_Credentials'), true);
        if (!is_array($data) || trim((string)($data['u'] ?? '')) === '' || (string)($data['p'] ?? '') === '') {
            return null;
        }
        return [trim((string)$data['u']), (string)$data['p']];
    }

    /** Cloud-Client; Testnaht wie bei Vaillant: $GLOBALS['ips']['kronotermClientFactory'](user, passwort). */
    private function kronotermClient(string $user, string $pass): WPHUB_KronotermClient
    {
        $factory = $GLOBALS['ips']['kronotermClientFactory'] ?? null;
        return $factory !== null ? $factory($user, $pass) : new WPHUB_KronotermClient($user, $pass);
    }

    private function kronotermCredentialsPresent(): bool
    {
        return $this->kronotermCredentials() !== null;
    }

    /**
     * Anmeldung aus dem Formular: prueft Benutzername/Passwort gegen die Cloud, speichert beide als Attribut,
     * liest einmal die Anlage und verwirft zuletzt das Passwort-Feld (ApplyChanges laedt das Formular neu,
     * deshalb kein UpdateFormField danach -- Store-Review-Checkliste 9l).
     */
    public function KronotermLogin(): void
    {
        if (!$this->isKronoterm()) {
            return;
        }
        $say = function (string $message): void {
            $this->UpdateFormField('KR_TestResult', 'caption', $message);
        };
        $user = trim($this->ReadPropertyString('KR_Username'));
        $pass = $this->ReadPropertyString('KR_Password');
        if ($user === '' || $pass === '') {
            $say(WPHUB_Text::t('❌ Please enter username and password first and apply, then log in.'));
            return;
        }
        try {
            $this->kronotermClient($user, $pass)->login();
        } catch (\Throwable $e) {
            $say(WPHUB_Text::t('❌ Kronoterm Cloud login failed. Check username and password.'));
            return;
        }
        $this->WriteAttributeString('KR_Credentials', json_encode(['u' => $user, 'p' => $pass]));
        try {
            $this->fetchKronoterm();
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            // Anmeldung gelungen, Abruf nicht: Zugangsdaten bleiben gespeichert, der Timer versucht es erneut.
            $this->LogMessage(WPHUB_Text::t('Kronoterm Cloud login worked, but reading the installation failed.'), KL_WARNING);
        }
        $this->discardLoginPassword('KR_Password');
    }

    /** Manual connectivity/inventory test, called from the config panel. */
    public function TestKronoterm(): void
    {
        if (!$this->isKronoterm()) {
            return;
        }
        try {
            $this->fetchKronoterm();
            $this->UpdateFormField('KR_TestResult', 'caption', WPHUB_Text::t('Kronoterm Cloud connection successful.'));
            $this->UpdateFormField('KR_LastSuccess', 'caption', WPHUB_Text::t('Last successful Kronoterm update: %s', date('d.m.Y H:i:s', $this->ReadAttributeInteger('KR_LastSuccessTs'))));
            $this->UpdateFormField('KR_InventorySummary', 'caption', $this->kronotermInventorySummary());
            $this->UpdateFormField('KR_BufferCandidates', 'caption', $this->kronotermBufferCandidateSummary());
            $this->refreshKronotermCloudForm();
            $this->UpdateFormField('KR_EnergyStatus', 'caption', WPHUB_Text::t('Kronoterm energy: %s (day: %s)', $this->ReadAttributeString('KR_EnergyLastStatus'), $this->ReadAttributeString('KR_EnergyLastSuccessDay') ?: '?'));
            $this->refreshDiscoverySummary();
            if ($this->ReadPropertyBoolean('WPHUB_Active')) {
                $this->SetTimerInterval('WPHUB_UpdateTimer', $this->kronotermPollSeconds() * 1000);
                $this->SetStatus(102);
            }
        } catch (\Throwable $e) {
            $this->UpdateFormField('KR_TestResult', 'caption', WPHUB_Text::t('Kronoterm Cloud connection failed.'));
            if ($this->ReadPropertyBoolean('WPHUB_Active')) {
                $this->SetStatus(201);
            }
        }
    }

    private function updateKronoterm(): void
    {
        try {
            $this->fetchKronoterm();
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $oldStatus = $this->GetStatus();
            $this->markAllUnreachable();
            $this->updateKronotermExternalBuffers();
            $this->SetStatus(201);
            if ($oldStatus !== 201) {
                $this->LogMessage(WPHUB_Text::t('Kronoterm Cloud connection failed.'), KL_WARNING);
            }
        }
    }

    /** The snapshot is transient; only non-sensitive inventory metadata is retained. */
    private function fetchKronoterm(): void
    {
        $credentials = $this->kronotermCredentials();
        if ($credentials === null) {
            throw new \RuntimeException('Kronoterm credentials missing.');
        }
        $client = $this->kronotermClient($credentials[0], $credentials[1]);
        $snapshot = $client->readSnapshot();
        $id = $snapshot['hp_id'] ?? null;
        if (!is_scalar($id) || trim((string)$id) === '') {
            throw new \RuntimeException('Kronoterm heat pump ID missing.');
        }
        $guid = 'kronoterm:' . trim((string)$id);
        $prefix = $this->devicePrefix($guid);
        $name = 'Kronoterm ' . trim((string)$id);
        $this->MaintainVariable($prefix . 'Erreichbar', $name . ': ' . WPHUB_Text::t('Reachable'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', 0, true);
        $this->SetValue($prefix . 'Erreichbar', true);
        $measurements = WPHUB_KronotermDataMapper::mapSnapshot($snapshot);
        $bufferMeasurements = WPHUB_KronotermBufferMapper::mapSnapshot(
            $snapshot,
            $this->kronotermBufferConfiguration(),
            function (int $id): ?float { return $this->kronotermExternalTemperature($id); }
        );
        // The upper buffer sensor has a verified, direct Cloud mapping.
        // Do not create it a second time via the old manual buffer selector.
        unset($bufferMeasurements['KTHeizpufferOben'], $bufferMeasurements['KTKombipufferOben']);
        $measurements = array_merge($measurements, $bufferMeasurements);
        // Daily electricity is a separate read-only POST request. No energy
        // value is mapped to EnergyID (only real cumulative meters qualify).
        $now = time();
        $today = date('Y-m-d', $now);
        $lastAttempt = $this->ReadAttributeInteger('KR_EnergyLastAttemptTs');
        $lastDay = $this->ReadAttributeString('KR_EnergyLastSuccessDay');
        $minSeconds = max(15, min(1440, $this->ReadPropertyInteger('KR_EnergyIntervalMinutes'))) * 60;
        if ($lastDay !== $today || $lastAttempt === 0 || ($now - $lastAttempt) >= $minSeconds) {
            $this->WriteAttributeInteger('KR_EnergyLastAttemptTs', $now);
            try {
                $consumptionResponse = $client->getDailyConsumption();
                $snapshot['pages']['consumption'] = $consumptionResponse;
                $snapshot['page_status']['consumption'] = 'available';
                $energy = WPHUB_KronotermEnergyMapper::mapDaily($consumptionResponse);
                if ($energy === []) {
                    $this->WriteAttributeString('KR_EnergyLastStatus', 'no usable values');
                } else {
                    $measurements = array_merge($measurements, $energy);
                    $measurements['KTEnergieDatum'] = [
                        'value' => $today,
                        'type' => 'string',
                        'profile' => '',
                        'label' => WPHUB_Text::quelle('Kronoterm daily energy date'),
                        'source' => 'date of consumption request',
                    ];
                    $this->WriteAttributeString('KR_EnergyLastSuccessDay', $today);
                    $this->WriteAttributeString('KR_EnergyLastStatus',
                        isset($energy['EnergieGesamtHeute']) ? 'complete' : 'partial');
                }
            } catch (\Throwable $e) {
                $snapshot['page_status']['consumption'] = 'unavailable';
                // Energy endpoint is optional; do not downgrade valid main status.
                $this->WriteAttributeString('KR_EnergyLastStatus', 'unavailable');
            }
        }
        if (!isset($snapshot['page_status']['consumption'])) {
            $snapshot['page_status']['consumption'] = 'not_queried_this_cycle';
        }
        $this->maintainKronotermMeasurements($prefix, $name, $measurements);
        $this->writeDeviceList([[
            'guid' => $guid,
            'name' => $name,
            'prefix' => $prefix,
            'reachable' => true,
            'operationMode' => $measurements['BetriebsartNorm']['value'] ?? null,
            'lastSeenAt' => time(),
        ]]);
        // Inventory every received scalar field. Mark fields as mapped, selected
        // buffer sources, used for configuration, or still unused in WPHub.
        $lastReport = $this->kronotermCloudReport();
        $this->WriteAttributeString('KR_CloudCatalog', json_encode(
            WPHUB_KronotermMeasurementCatalog::report($snapshot, $measurements, $lastReport),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        // Active optional values become typed instance variables (no logging, no visibility changes).
        $this->synchronizeKronotermExtra(true);
        // Read-only diagnostic catalog for manual mapping; never infer which
        // physically installed buffer sensor a field represents.
        $infoData = $snapshot['pages']['info']['InfoData'] ?? [];
        $catalog = $this->kronotermCloudReport();
        $candidates = array_keys(array_filter(
            WPHUB_KronotermMeasurementCatalog::temperatures($catalog),
            static fn($entry) => str_starts_with((string)($entry['path'] ?? ''), 'reservoir.')
        ));
        $this->WriteAttributeString('KR_Inventory', json_encode([
            'hp_id' => (string)$id,
            'model' => is_array($infoData) ? ($infoData['pumpModel'] ?? null) : null,
            'firmware' => is_array($infoData) ? ($infoData['firmware'] ?? null) : null,
            'inventory' => $snapshot['inventory'],
            'page_status' => $snapshot['page_status'],
            'reservoir_candidates' => $candidates,
        ], JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeInteger('KR_LastSuccessTs', time());
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
    }

    /** Create/update only documented, valid Cloud measurements; never delete old archive values. */
    private function maintainKronotermMeasurements(string $prefix, string $name, array $measurements): void
    {
        $position = 10;
        foreach ($measurements as $ident => $point) {
            if (!is_array($point) || !isset($point['type'], $point['label']) || !array_key_exists('value', $point)) {
                continue;
            }
            $types = [
                'boolean' => VARIABLETYPE_BOOLEAN,
                'integer' => VARIABLETYPE_INTEGER,
                'float'   => VARIABLETYPE_FLOAT,
                'string'  => VARIABLETYPE_STRING,
            ];
            $type = $types[$point['type']] ?? null;
            if ($type === null) {
                continue;
            }
            $label = WPHUB_Text::ausFormular($point['label']);
            if (strpos($label, '%d') !== false && preg_match('/^KTKreis([1-4])/', $ident, $match)) {
                $label = sprintf($label, (int)$match[1]);
            }
            $profile = (string)($point['profile'] ?? '');
            $source = (string)($point['source'] ?? '');
            if ($type === VARIABLETYPE_INTEGER) {
                $documented = WPHUB_KronotermModbusProfiles::definition($source);
                if ($documented !== null && ($documented['kind'] ?? '') === 'code'
                    && !empty($documented['choices'])) {
                    $profile = $this->ensureKronotermMappedIntegerProfile($source);
                }
            }
            $this->MaintainVariable(
                $prefix . $ident,
                $name . ': ' . $label,
                $type,
                $profile,
                $position++,
                true
            );
            $this->SetValue($prefix . $ident, $point['value']);
        }
    }

    /**
     * Create and update only explicitly selected additional Cloud variables.
     * Deactivating a value only stops its updates: the variable and its archive history stay untouched
     * (visibility and logging belong to the user, Store review). Logging is switched on only by the
     * "Enable logging" button of the general panel, never here.
     * Cached reports during ApplyChanges are used for schema only, not SetValue.
     */
    private function synchronizeKronotermExtra(bool $isFreshPoll): void
    {
        $saved = WPHUB_KronotermExtraCatalog::savedRows($this->ReadPropertyString('KR_ExtraValues'));
        if ($saved === []) return;
        $report = $this->kronotermCloudReport();
        $entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
        $permitted = [];
        foreach (WPHUB_KronotermExtraCatalog::displayRows($report, $saved) as $row) {
            $permitted[(string)$row['Path']] = true;
        }
        $points = $isFreshPoll ? WPHUB_KronotermExtraCatalog::livePoints($report, $saved) : [];
        foreach ($saved as $path => $config) {
            $ident = WPHUB_KronotermExtraCatalog::ident($path);
            if (!$config['Active'] || !isset($permitted[$path])) {
                continue;
            }
            $entry = $entries[$path] ?? null;
            if (!is_array($entry)) continue; // Retain a saved, temporarily absent field.
            $interpreted = WPHUB_KronotermModbusProfiles::interpret($path, $entry);
            $type = $interpreted['type'];
            if ($type === null) continue;
            $ipsType = ['float' => VARIABLETYPE_FLOAT, 'integer' => VARIABLETYPE_INTEGER, 'boolean' => VARIABLETYPE_BOOLEAN, 'string' => VARIABLETYPE_STRING][$type];
            $unit = WPHUB_KronotermModbusProfiles::effectiveUnit($path, $config['Unit']);
            $profile = '';
            if ($type === 'integer') {
                $profile = $this->ensureKronotermMappedIntegerProfile($path); // nur bei Einzelcodes, sonst ''
            } elseif ($type === 'float' && WPHUB_KronotermModbusProfiles::definition($path) !== null) {
                // Register definition includes a verified unit, factor and/or
                // value associations from the BMS manual, even without a
                // manual choice in the extra-values table.
                $profile = $this->ensureKronotermModbusProfile($path, $unit);
            } elseif ($type === 'float' && $unit !== '') {
                $profile = $this->ensureKronotermExtraProfile($unit);
            } elseif ($type === 'boolean') {
                $profile = '~Switch';
            }
            $this->MaintainVariable($ident, WPHUB_KronotermModbusProfiles::variableLabel($path), $ipsType, $profile, 500, true);
            // A bitmask can contain several statuses at once; an enum profile
            // would be misleading. Preserve the original numeric variable and
            // maintain an independent readable companion for documented masks.
            $definition = WPHUB_KronotermModbusProfiles::definition($path);
            if (($definition['kind'] ?? '') === 'bits' && !empty($definition['bits'])) {
                $meaningIdent = $ident . 'Bedeutung';
                $this->MaintainVariable($meaningIdent,
                    WPHUB_Text::t('%s: set bits', WPHUB_KronotermModbusProfiles::variableLabel($path)),
                    VARIABLETYPE_STRING, '', 501, true);
                if ($isFreshPoll && isset($points[$path])) {
                    $message = WPHUB_KronotermModbusProfiles::bitfieldText($path, $points[$path]['value']);
                    if ($message !== null) $this->SetValue($meaningIdent, $message);
                }
            }
            if ($isFreshPoll && isset($points[$path]) && @$this->GetIDForIdent($ident) > 0) {
                $this->SetValue($ident, $points[$path]['value']);
            }
        }
    }

    /** Read-only integer status profile for already mapped WPHub variables.
     * Never alter NRG shared profiles or the variable's own Ident/archive.
     */
    private function ensureKronotermMappedIntegerProfile(string $path): string
    {
        $definition = WPHUB_KronotermModbusProfiles::definition($path);
        if ($definition === null || ($definition['kind'] ?? '') !== 'code') return '';
        $profile = WPHUB_KronotermModbusProfiles::profileName($path, '') . '.Integer';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            foreach (($definition['choices'] ?? []) as $code => $meaning) {
                IPS_SetVariableProfileAssociation($profile, (int)$code, (string)$meaning, '', -1);
            }
        }
        return $profile;
    }

    /** Kronoterm BMS register profiles for Float values: physical unit and precision from the manual. */
    private function ensureKronotermModbusProfile(string $path, string $unit): string
    {
        $definition = WPHUB_KronotermModbusProfiles::definition($path);
        if ($definition === null) return '';
        $profile = WPHUB_KronotermModbusProfiles::profileName($path, $unit);
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText($profile, '', $unit === '' ? '' : ' ' . $unit);
            IPS_SetVariableProfileDigits($profile, WPHUB_KronotermModbusProfiles::digits($definition, $unit));
        }
        return $profile;
    }

    /** Local read-only display profiles; never modify global NRG profiles. */
    private function ensureKronotermExtraProfile(string $unit): string
    {
        $profile = WPHUB_KronotermExtraCatalog::profileName($unit);
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText($profile, '', ' ' . $unit);
            IPS_SetVariableProfileDigits($profile, WPHUB_KronotermExtraCatalog::formatDigits($unit));
        }
        return $profile;
    }

    /** The physical buffer layout is user-selected unless the API has explicit reliable flags. */
    private function kronotermBufferConfiguration(): array
    {
        $channels = [];
        foreach (['HeatTop' => 'heat_top', 'HeatBottom' => 'heat_bottom',
                  'CoolTop' => 'cool_top', 'CoolBottom' => 'cool_bottom'] as $property => $key) {
            $channels[$key] = [
                'source' => $this->ReadPropertyString('KR_' . $property . 'Source'),
                'external_id' => $this->ReadPropertyInteger('KR_' . $property . 'External'),
                'cloud_path' => $this->ReadPropertyString('KR_' . $property . 'CloudPath'),
            ];
        }
        return ['layout' => $this->ReadPropertyString('KR_BufferLayout'), 'channels' => $channels];
    }

    /** A source variable is only usable when its value is a numerical measurement. */
    private function kronotermExternalTemperature(int $id): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $variable = IPS_GetVariable($id);
        $type = (int)($variable['VariableType'] ?? -1);
        if ($type !== VARIABLETYPE_INTEGER && $type !== VARIABLETYPE_FLOAT) {
            return null;
        }
        $value = GetValue($id);
        return is_numeric($value) ? (float)$value : null;
    }

    /** Only the explicitly configured heating/combined upper sensor can act as bufferTempID. */
    private function kronotermContractBufferID(string $prefix): int
    {
        if (!$this->isKronoterm()) return 0;
        $layout = $this->ReadPropertyString('KR_BufferLayout');
        if (!in_array($layout, ['heating','separate','combined'], true)) return 0;
        if (!in_array($this->ReadPropertyString('KR_HeatTopSource'), ['cloud','external'], true)) return 0;
        $ident = $layout === 'combined' ? 'KTKombipufferOben' : 'KTHeizpufferOben';
        return $this->contractFieldID($prefix, $ident);
    }

    /** External sensor values still update when the Kronoterm Cloud is unreachable. */
    private function updateKronotermExternalBuffers(): void
    {
        $config = $this->kronotermBufferConfiguration();
        foreach ($this->readDeviceList() as $device) {
            $prefix = (string)($device['prefix'] ?? '');
            if ($prefix === '') {
                continue;
            }
            $points = WPHUB_KronotermBufferMapper::mapSnapshot([], $config,
                function (int $id): ?float { return $this->kronotermExternalTemperature($id); });
            $this->maintainKronotermMeasurements($prefix, (string)($device['name'] ?? 'Kronoterm'), $points);
        }
    }

    /** Safe public diagnostic API: only sanitized values, no raw cloud payloads. */
    public function GetKronotermCloudInventory(): string
    {
        return json_encode($this->kronotermCloudReport(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function kronotermCloudReport(): array
    {
        $data = json_decode($this->ReadAttributeString('KR_CloudCatalog'), true);
        return is_array($data) ? $data : [];
    }

    private function kronotermCloudReportSummary(): string
    {
        $report = $this->kronotermCloudReport();
        $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
        $n = count(is_array($report['entries'] ?? null) ? $report['entries'] : []);
        if ($n === 0) return WPHUB_Text::t('No Cloud field inventory available yet; read the installation inventory first.');
        $timestamp = (int)($report['timestamp'] ?? 0);
        $message = WPHUB_Text::t('Cloud fields: %d total, %d mapped, %d buffer, %d controller configuration, %d unused. Last scan: %s.',
            $n, (int)($counts['mapped'] ?? 0), (int)($counts['buffer'] ?? 0),
            (int)($counts['configuration'] ?? 0), (int)($counts['unused'] ?? 0),
            $timestamp > 0 ? date('d.m.Y H:i:s', $timestamp) : '?');
        if (($report['truncated'] ?? false) === true) $message .= ' ' . WPHUB_Text::t('Inventory limit reached; report may be incomplete.');
        $pageStatus = is_array($report['page_status'] ?? null) ? $report['page_status'] : [];
        $unavailable = [];
        foreach ($pageStatus as $page => $status) {
            if ($status === 'unavailable') $unavailable[] = (string)$page;
        }
        if ($unavailable !== []) {
            $message .= ' ' . WPHUB_Text::t('Cloud pages unavailable: %s', implode(', ', $unavailable));
        }
        return $message;
    }

    private function refreshKronotermCloudForm(): void
    {
        $report = $this->kronotermCloudReport();
        $this->UpdateFormField('KR_CloudCatalogSummary', 'caption', $this->kronotermCloudReportSummary());
        $this->UpdateFormField('KR_ExtraValues', 'values', json_encode(
            WPHUB_KronotermExtraCatalog::displayRows($report,
                WPHUB_KronotermExtraCatalog::savedRows($this->ReadPropertyString('KR_ExtraValues'))),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        foreach (['HeatTop', 'HeatBottom', 'CoolTop', 'CoolBottom'] as $sensor) {
            $property = 'KR_' . $sensor . 'CloudPath';
            $this->UpdateFormField($property, 'options', json_encode(
                WPHUB_KronotermMeasurementCatalog::temperatureOptions($report, $this->ReadPropertyString($property), static fn(string $s): string => WPHUB_Text::ausFormular($s)),
                JSON_UNESCAPED_UNICODE));
        }
    }

    private function kronotermBufferCandidateSummary(): string
    {
        $raw = json_decode($this->ReadAttributeString('KR_Inventory'), true);
        $candidates = is_array($raw['reservoir_candidates'] ?? null) ? $raw['reservoir_candidates'] : [];
        return WPHUB_Text::t('Potential Cloud reservoir temperature fields (verify sensor assignment): %s',
            $candidates === [] ? '-' : implode(', ', array_slice($candidates, 0, 15)));
    }

    private function kronotermInventorySummary(): string
    {
        $data = json_decode($this->ReadAttributeString('KR_Inventory'), true);
        $flags = is_array($data['inventory'] ?? null) ? $data['inventory'] : [];
        if ($flags === []) {
            return WPHUB_Text::t('No Kronoterm installation inventory available.');
        }
        $parts = [];
        foreach (['loop1', 'loop2', 'loop3', 'loop4', 'dhw', 'pool_active', 'heating_buffer', 'cooling_buffer'] as $key) {
            $value = $flags[$key] ?? null;
            $parts[] = $key . ': ' . ($value === null ? '?' : ($value ? 'yes' : 'no'));
        }
        return WPHUB_Text::t('Kronoterm controller inventory: %s', implode(', ', $parts));
    }
}
