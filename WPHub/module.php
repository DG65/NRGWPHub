<?php

// NRG-Stack WPHub -- Waermepumpen-Cloud-Anbindungen, Start mit Panasonic
// Comfort Cloud (Cloud-Alternative zu HeishaMon fuer Nutzer ohne HeishaMon-
// Platine). Weitere Hersteller (Mitsubishi MELCloud, Viessmann, Vaillant
// myVaillant, Stiebel Eltron ISG, ...) folgen spaeter ueber die Community,
// sobald sich Nutzer mit passender Hardware zum Testen finden -- analog dazu,
// wie Tessie/TibberGridReward auch mit einem Hersteller/Dienst gestartet sind.
//
// Vertrag WPHUB_GetFunctions() liefert Type=>'heatpump', konsistent zu
// HeishaMons Form (siehe DG65/NRGHeishaMon, ems-integration-Branch,
// HeishaMon/module.php::GetFunctions()) -- damit ist fuer EMS die
// Datenquelle (lokal via HeishaMon vs. Cloud via WPHub) austauschbar.
//
// Credentials-Konvention (SUITE.md): Handshake/Token bevorzugt, Passwort nur
// einmalig fuer den Login-Handshake, danach NICHT speichern -- nur das
// resultierende Token in RegisterAttributeString (NICHT Property, Attribute
// erscheinen nicht im Formular). IPS verschluesselt Attribute NICHT at rest --
// "sicher" heisst hier nur "nicht im Formular/Log sichtbar", so auch
// gegenueber dem Nutzer kommunizieren, nicht als echte Verschluesselung
// darstellen.

class WPHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('WPHUB_Active', false);
        $this->RegisterPropertyInteger('WPHUB_Interval', 60);

        // Panasonic Comfort Cloud Login (E-Mail + Passwort NUR fuer den
        // einmaligen Handshake-Aufruf, siehe Login(), danach verworfen).
        $this->RegisterPropertyString('CC_Email', '');
        $this->RegisterPropertyString('CC_Password', ''); // PasswordTextBox im Formular

        // Ergebnis des Handshakes -- NICHT das Passwort selbst.
        $this->RegisterAttributeString('CC_Token', '');
        $this->RegisterAttributeString('CC_DeviceList', '[]');

        $this->RegisterTimer('WPHUB_UpdateTimer', 0, 'WPHUB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active   = $this->ReadPropertyBoolean('WPHUB_Active');
        $interval = $this->ReadPropertyInteger('WPHUB_Interval');

        if ($active) {
            $this->SetTimerInterval('WPHUB_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
        } else {
            $this->SetTimerInterval('WPHUB_UpdateTimer', 0);
            $this->SetStatus(104);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    /**
     * Zyklische Aktualisierung -- TODO: Panasonic Comfort Cloud API-Aufrufe
     * (Login-Handshake falls Token fehlt/abgelaufen, Geraeteliste, Messwerte).
     * Noch nicht implementiert, dies ist nur das Scaffold.
     */
    public function Update()
    {
        if (!$this->ReadPropertyBoolean('WPHUB_Active')) {
            return;
        }

        // TODO: Login()/RefreshDevices()/UpdateValues() implementieren.
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu HeishaMons
     * GetFunctions() (Type=>'heatpump'). Liste bleibt leer, solange keine
     * Geraete via Comfort Cloud erkannt wurden (additiv, kein Fehler).
     */
    public function GetFunctions()
    {
        $devices = json_decode($this->ReadAttributeString('CC_DeviceList'), true);
        if (!is_array($devices)) {
            $devices = array();
        }

        $out = array();
        foreach ($devices as $d) {
            $out[] = array(
                'contractVersion' => '1.0',
                'Type'            => 'heatpump',
                'Caption'         => $d['name'] ?? 'Waermepumpe',
                'PowerID'         => $d['powerID'] ?? 0,
                'EnergyID'        => $d['energyID'] ?? 0,
                'Measured'        => $d['measured'] ?? false,
                'unit'            => 'W',
                'reachable'       => $d['reachable'] ?? false,
            );
        }
        return $out;
    }
}
