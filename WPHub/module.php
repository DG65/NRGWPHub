<?php

require_once __DIR__ . '/libs/WPHUB_Text.php';
require_once __DIR__ . '/libs/ComfortCloudClient.php';
require_once __DIR__ . '/libs/VaillantClient.php';

// NRG-Stack WPHub -- Waermepumpen-Cloud-Anbindungen, Start mit Panasonic
// Comfort Cloud (Cloud-Alternative zu HeishaMon fuer Nutzer ohne HeishaMon-
// Platine). Weitere Hersteller (Mitsubishi MELCloud, Viessmann, Vaillant
// myVaillant, Stiebel Eltron ISG, ...) folgen spaeter ueber die Community,
// sobald sich Nutzer mit passender Hardware zum Testen finden -- analog dazu,
// wie Tessie/TibberGridReward auch mit einem Hersteller/Dienst gestartet sind.
//
// Vertrag WPHUB_GetFunctions() liefert Type=>'heatpump', konsistent zum
// gemeinsamen heatpump-Vertragstyp (siehe DG65/NRGHeishaMon, ems-integration-
// Branch) -- damit ist fuer EMS die Datenquelle (lokal via HeishaMon vs.
// Cloud via WPHub) austauschbar. PowerID/EnergyID sind 0, solange kein
// externer Zaehler verknuepft ist (Ext_PowerVariable/Ext_EnergyVariable):
// Die Comfort Cloud selbst liefert keine Momentanleistung, und ihre
// Verbrauchswerte sind Tageswerte (springen auf 0 zurueck) -- nach Verbund-
// Regel "Energie nur aus kumulativen Zaehlern" wird die Groesse dann
// weggelassen, nicht hochgerechnet. Mit externem Zaehler (z.B. Shelly)
// liefert der Vertrag die echte Messung -- siehe GetFunctions().
//
// Credentials-Konvention (SUITE.md): Handshake/Token bevorzugt, Passwort nur
// einmalig fuer den Login-Handshake, danach NICHT speichern -- nur das
// resultierende Token-Buendel in RegisterAttributeString (NICHT Property,
// Attribute erscheinen nicht im Formular). IPS verschluesselt Attribute NICHT
// at rest -- "sicher" heisst hier nur "nicht im Formular/Log sichtbar", so
// auch gegenueber dem Nutzer kommunizieren.

class WPHub extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Cloud-Clients und Hilfsfunktionen uebersetzen ueber WPHUB_Text (englische Quelltexte, Deutsch in
        // locale.json); jeder Text fragt Symcon hoechstens einmal je Prozess (SUITE.md Stolperstein 24).
        WPHUB_Text::setzen(WPHUB_Text::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    // Stand des "Neu in Version"-Panels; bei jeder Version mit Neuigkeiten
    // hochziehen, dann erscheint das Panel wieder (pro Version dismissible).
    const NEWS_VERSION = '0.17.0';

    // Wählbare Fachpanels (SUITE.md 3a "Formular: Aufbau"): stabiler Schlüssel => Panel-Name in form.json.
    // Reihenfolge = Standardreihenfolge. 'managedby' wird nur gebaut, sobald ein Gerät bekannt ist.
    private const FACH_PANELS = [
        'general'      => 'GeneralPanel',
        'managedby'    => 'ManagedByPanel',
        'manufacturer' => 'ManufacturerPanel',
        'panasonic'    => 'PanasonicPanel',
        'vaillant'     => 'VaillantPanel',
        'ext'          => 'ExtSensorsPanel',
    ];

    // Comfort Cloud meldet 126 als "kein gueltiger Messwert".
    const CC_INVALID_TEMPERATURE = 126;

    // Zustimmungstypen der Comfort Cloud (Typ 3 = Servicevertrag nur Tuerkei).
    const AGREEMENT_TERMS   = 1;
    const AGREEMENT_PRIVACY = 2;

    // MeterHub-Modul-GUID (DG65/NRGMeterHub, MeterHub/module.json) -- fuer die
    // optionale Auto-Uebernahme einer per Funktionszuordnung "Waermepumpe"
    // markierten Zaehlerzuordnung, siehe meterHubHeatpumpAssignment().
    const METERHUB_MODULE_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    // HeishaMon-Modul-GUID (DG65/NRGHeishaMon), von HeishaMon selbst mitgeteilt
    // (13.09.2026, Verbund-Konfliktpruefung ueber ChargerHub angestossen) --
    // fuer den rein informativen Koexistenz-Hinweis, siehe
    // heishaMonCoexistenceWarning(). HeishaMon hat keine eigene "aktiv"-
    // Eigenschaft; InstanceStatus 102 ("Aktiv") ist der naechstliegende
    // verfuegbare Signal-Ersatz.
    const HEISHAMON_MODULE_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';

    // Vokabular fuer die Geraete-Steuerhoheit (managedBy, contractVersion 1.15,
    // mit EMS abgestimmt 13.09.2026 -- Dietmars Praezisierung "je Geraet, nicht
    // global"). Muster ChargerHubs managedBy, NICHT InverterHubs
    // controlAuthority: dort geht es um EMS-Zugriff, hier um "welches Modul
    // steuert dasselbe physische Geraet" -- das EMS schreibt nie an die
    // Waermepumpe. 'wphub' ist der Standard, damit eine frische Installation
    // ohne HeishaMon unveraendert funktioniert. Zuordnung ist bewusst
    // Nutzerangabe (siehe deviceManagedBy()), nie automatisch aus
    // heishaMonCoexistenceWarning() abgeleitet.
    const MANAGED_BY_VALUES = ['wphub', 'heishamon', 'other', 'none'];
    const MANAGED_BY_DEFAULT = 'wphub';

    // Unterstuetzte Hersteller (Manufacturer-Property, Muster InverterHub:
    // eine Select-Property schaltet Formular-Panel UND Treiber-Logik um,
    // Dietmar-Anstoss 14.09.2026 "andere WP-Hersteller mitnehmen"). Panasonic
    // bleibt Standard, damit jede bestehende Installation unveraendert
    // funktioniert. Vaillant ist Stand 14.09.2026 ungeprueft (kein Testkonto),
    // siehe VaillantClient.php und CHANGELOG.
    const MANUFACTURER_VALUES = ['panasonic', 'vaillant'];
    const MANUFACTURER_DEFAULT = 'panasonic';

    // Laender, in denen die myVAILLANT-App die Marke "Vaillant" fuehrt
    // (Realm vaillant-{land}-b2c) -- Auswahl aus signalkraft/myPyllant
    // const.py COUNTRIES['vaillant'], auf den DACH-/West-EU-Kern beschraenkt.
    const VAILLANT_COUNTRIES = [
        'germany' => 'Germany', 'austria' => 'Austria', 'switzerland' => 'Switzerland',
        'netherlands' => 'Netherlands', 'belgium' => 'Belgium', 'france' => 'France',
        'luxembourg' => 'Luxembourg', 'italy' => 'Italy', 'spain' => 'Spain',
        'poland' => 'Poland', 'denmark' => 'Denmark', 'unitedkingdom' => 'United Kingdom',
    ];

    // Wie lange die Anlagenliste/Regler-Typ-Zuordnung (VAI_SystemsCache)
    // ungeprueft weiterverwendet wird, bevor getHomes()/getControlIdentifier()
    // erneut aufgerufen werden -- siehe vaillantSystemsCache().
    private const VAI_SYSTEMS_CACHE_TTL_SECONDS = 86400;

    // Wie oft die EMF-Energiedaten (Stromverbrauch/erzeugte Waerme/Umwelt-
    // energie) neu abgerufen werden -- bewusst VIEL seltener als der Haupt-
    // Update()-Zyklus (Markus/m_rothenpieler, Forum-Post 29.09.2026: Energie
    // ausdruecklich nicht im 300/600s-Takt). 1 Stunde als erster, konservativer
    // Standardwert. Anfangs 1 Stunde -- bei Markus' Kaskade (13 Bucket-Abrufe
    // je Durchlauf) war das Energie-Kontingent damit nach rund zwei Tagen
    // aufgebraucht (Sperrfrist 2 Tage 17 h, Forum-Post 02.10.2026), er hielt
    // selbst 4-6 Stunden fuer ausreichend. Jetzt 4 Stunden. Siehe
    // updateVaillantEnergy().
    private const VAI_ENERGY_REFRESH_INTERVAL_SECONDS = 14400;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('WPHUB_Active', false);
        $this->RegisterPropertyInteger('WPHUB_Interval', 60);

        // Hersteller-Auswahl (Dietmar-Anstoss 14.09.2026, Muster InverterHub):
        // schaltet Formular-Panel und Treiber-Logik um, siehe MANUFACTURER_VALUES.
        // Panasonic bleibt Standard -- bestehende Installationen unveraendert.
        $this->RegisterPropertyString('Manufacturer', self::MANUFACTURER_DEFAULT);
        // Aufbau des Formulars (SUITE.md 3a): je Fachpanel Aufklapp-Zustand und Position.
        $this->RegisterPropertyString('FormLayout', '[]');

        // myVAILLANT-Login (E-Mail + Passwort NUR fuer den einmaligen
        // Handshake, siehe loginVaillant(), danach geleert -- gleiches Muster
        // wie CC_Email/CC_Password unten). Land bestimmt den Keycloak-Realm.
        $this->RegisterPropertyString('VAI_Email', '');
        $this->RegisterPropertyString('VAI_Password', '');
        $this->RegisterPropertyString('VAI_Country', 'germany');

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

        // Externe Sensoren/Zaehler (optional, freie Verknuepfung zu einer
        // beliebigen bestehenden Variable -- Shelly, MeterHub, HeishaMon,
        // eigener 1-Wire-Fuehler, egal). Schliesst die Luecken, die die
        // Comfort Cloud nicht liefert (echte Leistung/Energie, Vor-/
        // Ruecklauf-/Puffertemperatur). Jedes Modul muss eigenstaendig
        // funktionieren -- WPHub setzt daher KEIN anderes Modul voraus,
        // sondern nur irgendeine Symcon-Variable. Muster/Feldnamen von
        // HeishaMon uebernommen (DG65/NRGHeishaMon, ems-integration).
        // Gilt fuer das (einzige) Geraet des Kontos -- WPHub-Konten mit
        // mehreren Waermepumpen sind bislang kein praktischer Fall.
        $this->RegisterPropertyInteger('Ext_PowerVariable', 0);
        $this->RegisterPropertyInteger('Ext_EnergyVariable', 0);
        $this->RegisterPropertyInteger('Ext_MainInletTempVariable', 0);
        $this->RegisterPropertyInteger('Ext_MainOutletTempVariable', 0);
        $this->RegisterPropertyInteger('Ext_BufferTempVariable', 0);

        // Steuerhoheit je Geraet (managedBy, contractVersion 1.15) -- JSON-
        // Objekt Praefix=>Wert (siehe MANAGED_BY_VALUES), damit mehrere
        // erkannte Geraete unabhaengig zugeordnet werden koennen. Nutzer-
        // angabe, siehe deviceManagedBy()/SetManagedBy().
        $this->RegisterPropertyString('DeviceManagedBy', '{}');

        // Ergebnis des Handshakes -- NICHT das Passwort selbst.
        $this->RegisterAttributeString('CC_Token', '');
        $this->RegisterAttributeString('CC_DeviceList', '[]');
        // Eigenes Token-/Geraetelisten-Paar fuer Vaillant -- ein Konto pro
        // Manufacturer-Auswahl, beide Staende bleiben beim Umschalten separat
        // erhalten (kein Datenverlust bei Hin-/Herschalten zum Ausprobieren).
        $this->RegisterAttributeString('VAI_Token', '');
        $this->RegisterAttributeString('VAI_DeviceList', '[]');
        // Sperrfrist nach einem "Out of call volume quota"-Fehler (403) --
        // siehe updateVaillant()/VaillantClient::parseQuotaRetrySeconds().
        $this->RegisterAttributeInteger('VAI_RetryNotBefore', 0);
        // Eigene Sperrfrist NUR fuer die Energie-Abrufe (EMF): am 02.10.2026
        // lieferten die Buckets HTTP 403 mit 2 Tagen 17 h Sperrzeit, waehrend
        // der normale TLI-Abruf zeitgleich weiter HTTP 200 bekam -- das
        // Kontingent ist hier offenbar getrennt. Die gemeinsame Sperrfrist
        // oben wuerde den Haupt-Takt tagelang blockieren.
        $this->RegisterAttributeInteger('VAI_EnergyRetryNotBefore', 0);
        // Cache fuer Anlagenliste + Regler-Typ (getHomes()/getControlIdentifier())
        // -- spart zwei von drei API-Calls je Zyklus (Fund 26.09.2026,
        // m_rothenpieler, Forum-Post #38: Kontingent ist knapp, diese beiden
        // aendern sich praktisch nie). Siehe vaillantSystemsCache().
        $this->RegisterAttributeString('VAI_SystemsCache', '[]');
        $this->RegisterAttributeInteger('VAI_SystemsCacheAt', 0);
        // Letzter erfolgreicher Energiedaten-Abruf (EMF/buckets) -- eigener,
        // viel selterer Takt als der Haupt-Update()-Zyklus (Forum-Post,
        // m_rothenpieler, 29.09.2026: Energie ausdruecklich NICHT im 300/600s-
        // Takt). Siehe updateVaillantEnergy().
        $this->RegisterAttributeInteger('VAI_EnergyRefreshedAt', 0);
        // Zuletzt automatisch ermittelte App-Version (hat Vorrang).
        $this->RegisterAttributeString('CC_AppVersionAuto', '');
        // Zuletzt gesehener Feldwert von CC_AppVersion ('#unset' = noch nie
        // gesehen). Erkennt eine Nutzeraenderung in ApplyChanges(), siehe dort.
        $this->RegisterAttributeString('CC_AppVersionSeen', '#unset');
        // Zuletzt bestaetigter Stand des "Neu in Version"-Panels.
        $this->RegisterAttributeString('SeenNews', '');
        // Einmalig dismissible "Wozu dieses Modul?"-Panel (SUITE.md
        // "Einheitliche Formular-Optik" Punkt 0, EMS-Anstoss 14.09.2026) --
        // bool statt versioniert, weil sich der Zweck eines Moduls nicht mit
        // jedem Release aendert.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        // Einmalig dismissible Forum-Hinweis (SUITE.md "Einheitliche Formular-
        // Optik" Punkt 5.5, Forumsthread seit 16.09.2026 live), siehe ForumHint().
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        // Zeitpunkt der letzten erfolgreichen Geraetesuche (Verbund-Konvention
        // "Einheitliche Verbund-Status-Kopfzeile", SUITE.md 20.08.2026) --
        // siehe discoverySummaryLine().
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);

        $this->RegisterTimer('WPHUB_UpdateTimer', 0, 'WPHUB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureSharedProfiles();

        // Eigene Angabe hat Vorrang (SUITE.md "Wert kommt automatisch: Eingabefeld
        // ersetzen"): tippt der Nutzer im Feld "App-Version" einen NEUEN Wert ein,
        // wird die bis dahin automatisch ermittelte Version verworfen, damit die
        // Eingabe sofort gilt. Erst wenn die Comfort Cloud diese Version ablehnt
        // (4106), ermittelt WPHub wieder selbst eine und diese hat dann Vorrang
        // (Selbstheilung). Beim ersten Lauf nach dem Update ('#unset') wird der
        // Feldwert nur gemerkt, nichts verworfen.
        $manualNow = trim($this->ReadPropertyString('CC_AppVersion'));
        $manualSeen = $this->ReadAttributeString('CC_AppVersionSeen');
        if ($manualSeen !== $manualNow) {
            $this->WriteAttributeString('CC_AppVersionSeen', $manualNow);
            if ($manualSeen !== '#unset' && $manualNow !== '') {
                $this->WriteAttributeString('CC_AppVersionAuto', '');
            }
        }

        $active   = $this->ReadPropertyBoolean('WPHUB_Active');
        $interval = max(30, $this->ReadPropertyInteger('WPHUB_Interval'));
        $hasToken = $this->isVaillant() ? ($this->vaillantTokenBundle() !== null) : ($this->tokenBundle() !== null);

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
        // Formulartexte der form.json sind englische Quelltexte; die dynamisch gebauten Teile unten
        // uebersetzen sich selbst (nicht doppelt uebersetzen).
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Versionsnummer im Doku-Panel dynamisch aus library.json lesen statt
        // fest im form.json einzutragen -- eine frueher fest eingetragene
        // "0.1.0" war seit Build 3 nie mehr aktualisiert worden (Fund
        // 13.09.2026, im Rahmen der Umlaut-/Datumsformat-Durchsicht).
        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => WPHUB_Text::t('ℹ️ WPHub version %s -- heat pump cloud connection, Panasonic Comfort Cloud and (new, untested) Vaillant myVAILLANT.', $libraryVersion),
        ]);

        [$appVersionLine, $appVersionFieldVisible, $appVersionColor] = $this->appVersionStatus();
        $this->updateFormElement($form['elements'], 'CC_AppVersionStatus', ['caption' => $appVersionLine, 'color' => $appVersionColor]);
        // Nur ein-/ausblenden, NIE den Feldwert per Formular setzen.
        $this->updateFormElement($form['elements'], 'CC_AppVersionRow', ['visible' => $appVersionFieldVisible]);

        // "Neu in Version"-Panel vorn einhaengen, solange diese Version noch
        // nicht bestaetigt wurde (Dismiss NUR via Attribut + UpdateFormField,
        // kein IPS_SetProperty/ApplyChanges -- Store-Review-Regel).
        if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {
            array_unshift($form['elements'], [
                'type'     => 'ExpansionPanel',
                'name'     => 'NewsPanel',
                'caption'  => WPHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION),
                'expanded' => true,
                'items'    => [
                    ['type' => 'Label', 'caption' => WPHUB_Text::t('• 🌐 The module is now bilingual (German/English): form, messages, notes and the names of new variables follow the Symcon language.')],
                    ['type' => 'Label', 'caption' => WPHUB_Text::t('• 📈 Logging in the Symcon archive is no longer switched on automatically. In the "General" section there is the button "Enable logging for the measured values", so you enable it deliberately (Symcon review). Logging that is already enabled stays untouched.')],
                    ['type' => 'Label', 'caption' => WPHUB_Text::t('• 🪟 New: choose the form layout yourself -- in the collapsed panel "Form: layout" you decide per section whether it is expanded, collapsed or hidden on opening and at which position it stands. Symcon does not remember expanding and collapsing itself.')],
                    ['type' => 'Label', 'caption' => WPHUB_Text::t('• Panel "🏭 Manufacturer" -- WPHub can also connect Vaillant heat pumps via myVAILLANT (second manufacturer besides Panasonic Comfort Cloud). Vaillant is read-only by design -- feedback welcome.')],
                    ['type' => 'Button', 'caption' => WPHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPHUB_AckNews($id);'],
                ],
            ]);
        }

        // "Wozu dieses Modul?" -- ganz oben, VOR dem News-Panel (siehe
        // PurposeIntro()). Nach dem NewsPanel-Unshift eingehaengt, damit es
        // darueber landet; der HeishaMon-Sicherheitshinweis weiter unten
        // wird zuletzt eingehaengt und bleibt damit ganz oben, wenn aktiv.
        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }

        // Einheitliche Verbund-Status-Kopfzeile (SUITE.md 20.08.2026): immer
        // aktuell beim Formularaufbau berechnen, nicht erst nach einem Klick.
        $this->updateFormElement($form['elements'], 'DiscoverySummary', [
            'caption' => $this->discoverySummaryLine(),
        ]);

        // Hersteller-Auswahl (Manufacturer): nur das passende Anmelde-Panel
        // zeigen -- beide Panels stehen fest in form.json, hier wird nur
        // umgeschaltet (Muster MeterHubSuggestion/-AdoptButton weiter unten).
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $this->updateFormElement($form['elements'], 'PanasonicPanel', ['visible' => ($manufacturer !== 'vaillant')]);
        $this->updateFormElement($form['elements'], 'VaillantPanel', ['visible' => ($manufacturer === 'vaillant')]);

        // MeterHub-Vorschlag: nur solange noch nichts verknuepft ist (0/0) --
        // wer schon manuell/per Uebernahme verknuepft hat, soll nicht bei
        // jedem Formularaufruf erneut beworben werden.
        //
        // EMS-Klarstellung (28.09.2026, Verbundpruefung gegen SUITE.md
        // "Verbund-Verbindungen im Formular sichtbar machen"): Das ist KEIN
        // 🔗-Automatik-Fall im Sinne dieser Regel, obwohl AdoptMeterHubAssignment()
        // den Vorschlag per UpdateFormField(...,'value',...) ins Eingabefeld
        // schreibt -- die Regel verbietet das nur dort, wo die Verbindung
        // selbst einen laufend aktuellen MESSWERT liefert (Beispiel EMS/
        // Szenariorechner: "PV-Leistung: 9,18 kWp"), weil ein Uebernehmen-Klick
        // dann eine Momentaufnahme einfrieren und spaeteren Aenderungen der
        // Quelle nicht mehr folgen wuerde. Hier liefert MeterHub dagegen nur
        // eine VARIABLEN-ID (welche Symcon-Variable vermutlich die
        // Waermepumpe misst) fuer ein SelectVariable-Feld -- nach der
        // Uebernahme liest WPHub jeden Zyklus ganz normal den LIVE-Wert der
        // verknuepften Variable (extVariableID()/GetValue(), identisch zu
        // jedem anderen manuell verlinkten Ext_*Variable-Feld). Keine
        // Momentaufnahme, kein Nachfolge-Problem -- architektonisch dasselbe
        // "manuelle Fallback-Feld mit Vorbefuellungs-Hilfe" wie die Auswahl
        // selbst, nur mit einem intelligenten Vorschlag statt leerem Start.
        $assignment = $this->meterHubHeatpumpAssignment();
        if ($assignment !== null
            && $this->ReadPropertyInteger('Ext_PowerVariable') <= 0
            && $this->ReadPropertyInteger('Ext_EnergyVariable') <= 0) {
            $this->updateFormElement($form['elements'], 'MeterHubSuggestion', [
                'caption' => WPHUB_Text::t('ℹ️ MeterHub has a meter "%s" with the function assignment "Heat pump".', $assignment['label']),
                'visible' => true,
            ]);
            $this->updateFormElement($form['elements'], 'MeterHubAdoptButton', ['visible' => true]);
        }

        // Koexistenz-Hinweis HeishaMon (13.09.2026, Verbund-Konfliktpruefung
        // ueber ChargerHub/HeishaMon): rein informativ, ganz oben im Formular
        // (letzter array_unshift gewinnt die Spitzenposition, daher nach dem
        // NewsPanel-Unshift), siehe heishaMonCoexistenceWarning(). Die
        // eigentliche Steuerhoheit wird weiter unten je Geraet abgefragt.
        $heishaWarning = $this->heishaMonCoexistenceWarning();
        if ($heishaWarning !== null) {
            array_unshift($form['elements'], [
                'type'    => 'Label',
                'caption' => $heishaWarning,
            ]);
        }

        // Steuerhoheit je Geraet (managedBy, contractVersion 1.15): eigenes
        // Panel, nur sichtbar, sobald mindestens ein Geraet bekannt ist (vor
        // der ersten Anmeldung gibt es nichts zuzuordnen). Direkt nach dem
        // "Allgemein"-Panel eingefuegt (GeneralPanel-Name in form.json).
        $devicesForForm = $this->readDeviceList();
        if (count($devicesForForm) > 0) {
            $rows = [];
            foreach ($devicesForForm as $d) {
                $devPrefix = (string)($d['prefix'] ?? '');
                if ($devPrefix === '') {
                    continue;
                }
                $fieldName = 'ManagedBy_' . $devPrefix;
                $rows[] = [
                    'type'     => 'Select',
                    'name'     => $fieldName,
                    'caption'  => $this->managedBySelectCaption($devPrefix, (string)($d['name'] ?? WPHUB_Text::t('Heat pump')), $heishaWarning !== null),
                    'value'    => $this->deviceManagedBy($devPrefix),
                    'options'  => [
                        ['caption' => WPHUB_Text::t('WPHub (Comfort Cloud, default)'), 'value' => 'wphub'],
                        ['caption' => WPHUB_Text::t('HeishaMon (local, WPHub only reads)'), 'value' => 'heishamon'],
                        ['caption' => WPHUB_Text::t('Other module'), 'value' => 'other'],
                        ['caption' => WPHUB_Text::t('Nobody -- read only'), 'value' => 'none'],
                    ],
                    'onChange' => 'WPHUB_SetManagedBy($id, "' . $devPrefix . '", $' . $fieldName . ');',
                ];
            }
            if (count($rows) > 0) {
                $panel = [
                    'type'     => 'ExpansionPanel',
                    'name'     => 'ManagedByPanel',
                    'caption'  => WPHUB_Text::t('🔀 Control authority'),
                    'expanded' => ($heishaWarning !== null),
                    'items'    => array_merge([
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Label',
                                    'caption' => WPHUB_Text::t('If other heat pump software (e.g. HeishaMon) controls the same system, set it here per device -- WPHub then writes nothing for this device any more but keeps showing all measured values.'),
                                ],
                                [
                                    'type'    => 'PopupButton',
                                    'caption' => WPHUB_Text::t('What do the four control authority options mean?'),
                                    'width'   => '460px',
                                    'popup'   => [
                                        'caption' => WPHUB_Text::t('What do the four control authority options mean?'),
                                        'items'   => [
                                            [
                                                'type'    => 'Label',
                                                'caption' => WPHUB_Text::t('"WPHub (Comfort Cloud, default)": WPHub may control this heat pump (e.g. quiet mode) -- the normal case as long as no other module serves the same system. "HeishaMon (local, WPHub only reads)": a HeishaMon instance installed in parallel controls the same heat pump locally/via MQTT -- WPHub then sends no control commands any more but keeps showing all measured values. "Other module": some third module (not HeishaMon) has the control authority here -- same effect as with HeishaMon, just named separately for documentation. "Nobody -- read only": deliberately a pure observation mode, even without a competing module. In all three non-"WPHub" cases the display of the measured values stays unchanged -- only the control commands are suppressed. The identity of the device between WPHub and other modules cannot be proven technically, which is why this manual entry per device exists.'),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], $rows),
                ];
                $generalIndex = null;
                foreach ($form['elements'] as $i => $el) {
                    if (($el['name'] ?? null) === 'GeneralPanel') {
                        $generalIndex = $i;
                        break;
                    }
                }
                if ($generalIndex === null) {
                    $form['elements'][] = $panel;
                } else {
                    array_splice($form['elements'], $generalIndex + 1, 0, [$panel]);
                }
            }
        }

        // Reihenfolge/Aufklapp-Zustand der Fachpanels nach der Nutzerwahl
        // (SUITE.md 3a), samt Panel "Formular: Aufbau" vor dem Forum-Hinweis.
        $this->applyFormLayout($form);

        // Forum-Hinweis -- nach den Fachpanels, vor "Über dieses Modul"
        // (SUITE.md "Einheitliche Formular-Optik").
        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }

        // "Über dieses Modul" -- ganz unten (SUITE.md "Einheitliche
        // Formular-Optik" Punkt 5), nicht dismissible.
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der vorhandenen Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in anzuwendender Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, kein Haken = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /**
     * Ordnet die Fachpanels im zusammengesetzten Formular nach „FormLayout“ neu, setzt den Aufklapp-Zustand,
     * blendet gewählte Panels aus (visible:false, nie weglassen) und fügt das Panel „Formular: Aufbau“
     * hinter den Fachpanels ein. Panels, die schon durch den gewählten Hersteller ausgeblendet sind, bleiben es.
     */
    private function applyFormLayout(array &$form): void
    {
        $free = [];
        $remove = [];
        foreach (self::FACH_PANELS as $key => $name) {
            foreach ($form['elements'] as $i => $el) {
                if (($el['name'] ?? null) === $name) {
                    $free[$key] = $el;
                    $remove[] = $i;
                    break;
                }
            }
        }
        if (count($free) === 0) {
            return;
        }
        $at = min($remove);
        $rest = [];
        foreach ($form['elements'] as $i => $el) {
            if (!in_array($i, $remove, true)) {
                $rest[] = $el;
            }
        }
        $layout = $this->formLayout(array_keys($free));
        $middle = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                $panel['visible'] = false;
            }
            $middle[] = $panel;
        }
        array_splice($rest, $at, 0, array_merge($middle, [$this->LayoutPanel($layout, $free)]));
        $form['elements'] = $rest;
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', WPHUB_Text::ausFormular((string)($free[$key]['caption'] ?? $key))));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => WPHUB_Text::t('🙈 Hidden: %s -- to show them again, remove the tick at "Hidden". Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'name' => 'LayoutPanel', 'expanded' => false,
            'caption' => WPHUB_Text::t('🪟  Form: layout') . ($hidden ? ' ' . WPHUB_Text::t('(%d hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so the setting applies here every time the form opens. It takes effect after "Apply" the next time the form is opened.')],
                ['type' => 'Label', 'caption' => WPHUB_Text::t('No tick means "default": as the module intends it. The position is a number; with equal numbers the default order is kept. "Hidden" takes a section out of the display without it losing anything: its settings are kept and keep working. To show it again, remove the tick at "Hidden" or click "Reset to default". If several ticks are set, "Hidden" wins over "Collapsed" over "Expanded". Of the login sections WPHub shows only the one of the chosen manufacturer anyway. The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.')],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => WPHUB_Text::t('Sections'), 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => WPHUB_Text::t('Section'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => WPHUB_Text::t('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => WPHUB_Text::t('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => WPHUB_Text::t('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => WPHUB_Text::t('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => WPHUB_Text::t('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('↩️ Reset to default'), 'onClick' => 'echo WPHUB_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $base = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $captions = [];
        foreach (($base['elements'] ?? []) as $el) {
            if (isset($el['name'])) {
                $captions[(string)$el['name']] = WPHUB_Text::ausFormular((string)($el['caption'] ?? ''));
            }
        }
        $captions['ManagedByPanel'] = WPHUB_Text::t('🔀 Control authority');
        $rows = [];
        $i = 0;
        foreach (self::FACH_PANELS as $key => $name) {
            if ($key === 'managedby' && count($this->readDeviceList()) === 0) {
                continue; // wird erst gebaut, sobald ein Gerät bekannt ist
            }
            $label = trim((string)preg_replace('/\s*\(.*$/u', '', $captions[$name] ?? $key));
            $rows[] = ['Key' => $key, 'Panel' => $label, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return WPHUB_Text::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    /**
     * Sucht rekursiv ein Formularelement mit passendem 'name' (auch in
     * verschachtelten 'items', z.B. innerhalb ExpansionPanel/RowLayout) und
     * mischt $patch in dessen Felder. Fuer die statische Rueckgabe von
     * GetConfigurationForm() -- UpdateFormField wirkt nur auf ein bereits
     * geoeffnetes Formular, nicht auf dessen Anfangszustand.
     */
    private function updateFormElement(array &$items, string $name, array $patch): bool
    {
        foreach ($items as &$item) {
            if (($item['name'] ?? null) === $name) {
                $item = array_merge($item, $patch);
                return true;
            }
            if (isset($item['items']) && is_array($item['items'])) {
                if ($this->updateFormElement($item['items'], $name, $patch)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Aktuell gewaehlter Hersteller ist Vaillant statt des Standards Panasonic. */
    private function isVaillant(): bool
    {
        return $this->ReadPropertyString('Manufacturer') === 'vaillant';
    }

    /**
     * Attribut-Name der Geraetliste des aktuell gewaehlten Herstellers --
     * ein WPHub-Konto ist immer genau EIN Hersteller (Manufacturer-Select),
     * daher genuegt eine einzige gemeinsame Stelle statt jeden Lese-/
     * Schreibzugriff einzeln zu verzweigen.
     */
    private function deviceListAttribute(): string
    {
        return $this->isVaillant() ? 'VAI_DeviceList' : 'CC_DeviceList';
    }

    private function readDeviceList(): array
    {
        $devices = json_decode((string)$this->ReadAttributeString($this->deviceListAttribute()), true);
        return is_array($devices) ? $devices : [];
    }

    private function writeDeviceList(array $devices): void
    {
        $this->WriteAttributeString($this->deviceListAttribute(), json_encode($devices));
    }

    // Bestaetigt das "Neu in Version"-Panel fuer die aktuelle Version.
    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * "Wozu dieses Modul?" -- SUITE.md "Einheitliche Formular-Optik" Punkt 0
     * (EMS-Anstoss 14.09.2026, urspruenglicher Auslöser: ein Beta-Tester
     * wusste am Anfang nicht, wozu ein Modul gut ist). Ganz oben im
     * Formular, VOR dem "Neu in Version"-Panel, einmalig dismissible (bool-
     * Attribut, NICHT versioniert -- der Zweck eines Moduls aendert sich
     * nicht mit jedem Release). Referenz: MeterHub.
     */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'PurposeIntroPanel',
            'expanded' => true,
            'caption'  => WPHUB_Text::t('👋  What is this module for?'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPHUB_Text::t('WPHub registers Panasonic Aquarea heat pumps (Comfort Cloud account) with the NRG-Stack family -- operating data, temperatures and control (quiet mode, hot water/zone setpoint, holiday timer) directly in Symcon, without an additional HeishaMon board.')],
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Useful for evaluations (e.g. NRGDashboard), energy management (EMS) and as a cloud alternative when HeishaMon\'s local MQTT bridge is not an option. If WPHub AND HeishaMon both have access to the same heat pump, HeishaMon controls it (see panel "🔀 Control authority").')],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    // Forumsthread seit 16.09.2026 live (Dietmar).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-wphub-waermepumpen-cloud-anbindung-fuer-ip-symcon-panasonic-comfort-cloud-vaillant-myvaillant-cloud-alternative-zu-heishamon/144412';

    /**
     * Symcon-Forum-Hinweis -- SUITE.md "Einheitliche Formular-Optik", nach den
     * Fachpanels, vor "Über dieses Modul". Einmalig dismissible, kein
     * Versionsbezug (Muster MeterHub ForumHint()/AckForumHint(), hier ohne
     * dessen Mehrinstanzen-Propagierung -- WPHub hat dafuer keinen Bedarf).
     */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'ForumHintPanel',
            'expanded' => true,
            'caption'  => WPHUB_Text::t('💬  Feedback in the Symcon forum'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Questions, bugs, experience reports or help testing more heat pump manufacturers -- the WPHub forum thread is the place for that.')],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPHUB_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    /**
     * "Über dieses Modul" -- SUITE.md "Einheitliche Formular-Optik" Punkt 5.
     * Ganz unten, NICHT dismissible (Lizenzhinweis, kein einmaliger Tipp),
     * eingeklappt. Wortlaut verbundweit identisch ("Variante A"), nur
     * LICENSE_URL modul-eigen. Zeigt seit 16.09.2026 auf beta (erster
     * Store-Release-Branch, siehe SUITE.md-Stolperfalle 01.09.2026: nicht
     * blind auf main verlinken -- main existiert fuer dieses Repo noch
     * nicht). Aktive Entwicklung bleibt auf ems-integration, beta wird nur
     * bei Bedarf nachgezogen.
     */
    private const LICENSE_URL = 'https://github.com/DG65/NRGWPHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    private function LicenseHint(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'caption'  => WPHUB_Text::t('🧡  About this module'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => WPHUB_Text::t('License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use needs a separate license from the rights holder.')],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('View license text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => WPHUB_Text::t('Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.')],
                ['type' => 'Button', 'caption' => WPHUB_Text::t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
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
            $say(WPHUB_Text::t('❌ Please enter e-mail and password first and apply, then log in.'));
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

        // Erfolg: Token-Buendel sichern. Das Passwort wird erst ganz am Ende
        // verworfen (discardLoginPassword()): ApplyChanges() laedt das offene
        // Formular neu, jedes UpdateFormField() DANACH ginge verloren
        // (Store-Review-Checkliste 9l).
        $this->WriteAttributeString('CC_Token', json_encode($bundle));

        $devices = $this->refreshDevices($bundle, $client);
        if ($devices === null) {
            if ($client->agreementRequired()) {
                // Vor SetStatus(): ApplyChanges() setzt den Status neu.
                $this->discardLoginPassword('CC_Password');
                $this->SetStatus(202);
                $say(WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . "\n" . WPHUB_Text::t('📜 Panasonic has, however, updated its terms of use/privacy policy and requires renewed agreement from your account. Please click "Accept updated terms" below (or open the official Comfort Cloud app once and confirm there).'));
                return;
            }
            $say(WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('The device list could not be loaded yet (%s) — it will be tried again on the next update run.', $client->getLastError()));
            $this->discardLoginPassword('CC_Password');
            return;
        }
        $this->refreshDiscoverySummary();
        if (count($devices) === 0) {
            $say(WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('But no Aquarea heat pump was found in the account. WPHub deliberately does not include air conditioners.'));
            $this->discardLoginPassword('CC_Password');
            return;
        }
        $lines = [WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('Heat pumps found:')];
        foreach ($devices as $d) {
            $lines[] = '   • ' . $d['name'] . ($d['reachable'] ? '' : ' ' . WPHUB_Text::t('(currently not reachable)'));
        }
        $say(implode("\n", $lines));
        $this->discardLoginPassword('CC_Password');
    }

    /**
     * Passwort-Property nach erfolgreicher Anmeldung leeren. MUSS der letzte
     * Schritt der Login-Funktion sein: IPS_ApplyChanges() laedt das offene
     * Formular neu (Store-Review-Checkliste 9l), das leere Passwortfeld baut
     * GetConfigurationForm() danach ohnehin aus der Property nach. Sofort
     * wirksam sein muss es aus Sicherheitsgruenden, ein reiner Formular-
     * Vorschlag (UpdateFormField) wuerde das Passwort in der Property lassen.
     */
    private function discardLoginPassword(string $property): void
    {
        IPS_SetProperty($this->InstanceID, $property, '');
        IPS_ApplyChanges($this->InstanceID);
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
            $say(WPHUB_Text::t('❌ No valid login — please log in first.'));
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
            $say(WPHUB_Text::t('❌ The current terms could not be retrieved: %s', $client->getLastError()));
            return;
        }
        if (count($docs) === 0) {
            $say(WPHUB_Text::t('ℹ️ The cloud reports no open terms. Trying to load the device list directly …'));
        } elseif (!$client->putAgreementStatus($bundle, $docs)) {
            $say(WPHUB_Text::t('❌ The agreement could not be submitted: %s', $client->getLastError()));
            return;
        }

        $names = [
            self::AGREEMENT_TERMS   => WPHUB_Text::t('terms of use'),
            self::AGREEMENT_PRIVACY => WPHUB_Text::t('privacy policy'),
            3                        => WPHUB_Text::t('service contract'),
        ];
        $accepted = [];
        foreach ($docs as $d) {
            $accepted[] = $names[$d['type']] ?? WPHUB_Text::t('document %s', (string)$d['type']);
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
            $this->LogMessage(WPHUB_Text::t('WPHub diagnostics, agreement:') . "\n" . $client->getApiTrace(), KL_WARNING);
            $say(WPHUB_Text::t('⚠️ Agreement submitted (%s), but the device list still cannot be loaded: %s — details are in the system log.', count($accepted) ? implode(', ', $accepted) : WPHUB_Text::t('nothing open'), $client->getLastError()));
            return;
        }
        $this->refreshDiscoverySummary();
        $this->SetStatus(102);
        $lines = [count($accepted)
            ? WPHUB_Text::t('✅ Confirmed: %s. Heat pumps found:', implode(', ', $accepted))
            : WPHUB_Text::t('✅ According to the cloud nothing was open any more. Heat pumps found:')];
        if (count($devices) === 0) {
            $lines[] = '   ' . WPHUB_Text::t('(no Aquarea heat pump found in the account)');
        }
        foreach ($devices as $d) {
            $lines[] = '   • ' . $d['name'] . ($d['reachable'] ? '' : ' ' . WPHUB_Text::t('(currently not reachable)'));
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
        if ($this->isVaillant()) {
            $this->updateVaillant();
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
                    $this->LogMessage(WPHUB_Text::t('Panasonic requires renewed agreement to the terms of use/privacy policy — click "Accept updated terms" in the WPHub form or open the official app once.'), KL_WARNING);
                }
                $this->SetStatus(202);
                return;
            }
            $this->LogMessage(WPHUB_Text::t('Update failed: %s', $client->getLastError()), KL_WARNING);
            return;
        }
        $this->refreshDiscoverySummary();

        // HeishaMon-Fund 13.09.2026 (live an Instanz #57727 bestaetigt): eine
        // aktive HeishaMon-Instanz ohne gesetzte Steuerhoheit ist eine stille
        // Luecke -- WPHub wuerde sonst unbemerkt weiter schreiben, obwohl
        // Dietmars Vorrang-Entscheidung greifen sollte. Weiche Warnung (2xx,
        // hohe Zahl = "inactive"-Icon), kein Fehler: die Funktion ist nicht
        // gestoert, nur die Zuordnung fehlt noch.
        $needsAttention = $this->managedByNeedsAttention();
        if (count($needsAttention) > 0) {
            if ($this->GetStatus() !== 203) {
                $this->LogMessage(WPHUB_Text::t('Control authority not assigned yet although an active HeishaMon instance was found: %s — set it in the WPHub form under "🔀 Control authority", otherwise WPHub keeps controlling as well (default "WPHub").', implode(', ', $needsAttention)), KL_WARNING);
            }
            $this->SetStatus(203);
            return;
        }
        $this->SetStatus(102);
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu HeishaMons
     * GetFunctions() (Type=>'heatpump'). Ein Eintrag je gefundener
     * Waermepumpe. PowerID/EnergyID = 0: die Cloud liefert keine
     * vertragstaugliche Leistung/Energie -- laut Verbund-Abstimmung mit
     * MeterHub/EMS bewusst so belassen (echte Messwerte kommen ggf. separat
     * aus einem Messmodul, kein Erzeuger-Vertrag referenziert fremde IDs).
     *
     * contractVersion 1.3 (additiv, mit HeishaMon/EMS abgestimmt 13.08.2026):
     * zusaetzliche *ID-Felder fuer den gemeinsamen heatpump-Vertragstyp.
     * z1WaterTempID/z2WaterTempID/dhwTempID sind bewusst dieselben
     * Feldnamen wie bei HeishaMon (identisches Konzept: Zonen-/Warmwasser-
     * Isttemperatur) -- Konsumenten lesen denselben Feldnamen unabhaengig
     * vom liefernden Modul. Alle anderen additiven Felder sind neu (WPHub
     * hat keine Pumpen-/Ventildaten, HeishaMon deckt diese eigenen Konzepte
     * bislang nicht ab). 0, wenn WPHub den jeweiligen Wert nicht liefert.
     *
     * contractVersion 1.11 (Dashboard-Anfrage 17.08.2026, EMS-Registrierung
     * vorgeschlagen): dailyEnergy{Heating,Cooling,DHW,Total}ID zeigen auf die
     * Tages-Energiezaehler der Cloud (springen um Mitternacht auf 0) --
     * bewusst NICHT als EnergyID (siehe Grundregel: nur echte kumulative
     * Zaehler), sondern eigene informative Felder analog zu HeishaMons
     * dailyPerformanceFactorID.
     */
    public function GetFunctions()
    {
        $devices = $this->readDeviceList();

        // Externe Sensoren/Zaehler (contractVersion 1.5, Muster von HeishaMon
        // uebernommen, DG65/NRGHeishaMon): freie Verknuepfung zu einer
        // beliebigen bestehenden Variable schliesst die Cloud-Luecken (echte
        // Leistung/Energie, Vor-/Ruecklauf-/Puffertemperatur). Gilt fuer das
        // (einzige) Geraet des Kontos. Kein COP/Arbeitszahl-Feld -- dafuer
        // fehlt WPHub strukturell die thermische Erzeugung (kein Durchfluss,
        // keine Wassermenge), auch mit externem Stromzaehler nicht ableitbar.
        $extPowerID  = $this->extVariableID('Ext_PowerVariable');
        $extEnergyID = $this->extVariableID('Ext_EnergyVariable');

        $out = [];
        foreach ($devices as $d) {
            $prefix = (string)($d['prefix'] ?? '');
            $reachableID = @$this->GetIDForIdent($prefix . 'Erreichbar');
            $out[] = [
                'contractVersion'      => '1.15',
                'Type'                 => 'heatpump',
                'Caption'              => $d['name'] ?? WPHUB_Text::t('Heat pump'),
                'PowerID'              => $extPowerID,
                'EnergyID'             => $extEnergyID,
                'Measured'             => ($extPowerID > 0),
                'unit'                 => 'W',
                'reachable'            => ($reachableID === false) ? (bool)($d['reachable'] ?? false) : (bool)GetValue($reachableID),
                // contractVersion 1.6 (EMS-Entscheid 17.08.2026, SUITE.md-
                // Feldregister Commit e0f219e): outsideTempID ist der
                // kanonische Feldname (Stilkonsistenz mit den uebrigen
                // *TempID-Kurzformen) -- outdoorTemperatureID war unser
                // eigener, abweichender Name (seit 1.3) und gilt als
                // deprecated. Beide zeigen auf dieselbe Variable, bis
                // Konsumenten auf outsideTempID umgestellt haben; dann laeuft
                // outdoorTemperatureID aus (siehe SUITE.md).
                'outsideTempID'        => $this->contractFieldID($prefix, 'Aussentemperatur'),
                'outdoorTemperatureID' => $this->contractFieldID($prefix, 'Aussentemperatur'), // deprecated, siehe oben
                'z1WaterTempID'        => $this->contractFieldID($prefix, 'Zone1Ist'),
                'z2WaterTempID'        => $this->contractFieldID($prefix, 'Zone2Ist'),
                'z1WaterTargetTempID'  => $this->contractFieldID($prefix, 'Zone1Soll'),
                'z2WaterTargetTempID'  => $this->contractFieldID($prefix, 'Zone2Soll'),
                'dhwTempID'            => $this->contractFieldID($prefix, 'Warmwasser'),
                'dhwTargetTempID'      => $this->contractFieldID($prefix, 'WarmwasserSoll'),
                'quietModeID'          => $this->contractFieldID($prefix, 'Fluesterbetrieb'),
                'ecoComfortModeID'     => $this->contractFieldID($prefix, 'EcoKomfort'),
                'holidayTimerID'       => $this->contractFieldID($prefix, 'Urlaubstimer'),
                // contractVersion 1.4 (EMS-Entscheid, mit HeishaMon abgestimmt
                // 13.08.2026): operatingModeNormID zeigt auf eine modulgepflegte
                // Variable mit dem Verbund-Enum (0=standby,1=heating,2=cooling,
                // 3=dhw,4=heating+dhw,5=cooling+dhw,-1=unbekannt) -- Konsumenten
                // muessen keine Herstellersemantik mehr kennen. operatingModeID
                // ist das optionale rohe Diagnosefeld (unser ExtendedOperationMode).
                'operatingModeNormID'  => $this->contractFieldID($prefix, 'BetriebsartNorm'),
                'operatingModeID'      => $this->contractFieldID($prefix, 'Betriebsart'),
                // contractVersion 1.5: dieselben Feldnamen wie im gemeinsamen
                // heatpump-Typ seit HeishaMons erster Erweiterung (13.08.2026),
                // hier aus einer manuell verknuepften externen Variable statt
                // aus der Cloud -- 0, solange nichts verknuepft ist.
                'mainInletTempID'      => $this->extVariableID('Ext_MainInletTempVariable'),
                // Vaillant liefert Vorlauf-/Puffertemperatur direkt aus der
                // Cloud (Ident Vorlauftemperatur/Puffertemperatur, siehe
                // maintainDeviceVariablesVaillant()) -- geraeteeigener Wert
                // hat Vorrang vor der externen Verknuepfung; bei Panasonic
                // legt WPHub diese Idents nie an, contractFieldID() liefert
                // dann 0 und der bisherige Ext_*-Fallback greift unveraendert.
                'mainOutletTempID'     => $this->contractFieldID($prefix, 'Vorlauftemperatur') ?: $this->extVariableID('Ext_MainOutletTempVariable'),
                'bufferTempID'         => $this->contractFieldID($prefix, 'Puffertemperatur') ?: $this->extVariableID('Ext_BufferTempVariable'),
                // contractVersion 1.11 (Dashboard-Anfrage 17.08.2026, EMS zur
                // SUITE.md-Registrierung vorgeschlagen): die Panasonic Cloud
                // liefert Tageswerte, die um Mitternacht auf 0 zurueckspringen
                // -- laut Grundregel (SUITE.md) daher NICHT EnergyID-tauglich
                // (kein kumulativer Zaehler). Trotzdem sind es echte kWh-Werte,
                // nuetzlich fuer Verlaufsdarstellung -- eigene, klar als
                // "daily" benannte Felder (Praezedenzfall: dailyPerformanceFactorID),
                // damit kein Konsument sie faelschlich wie einen Zaehler diffed.
                'dailyEnergyHeatingID' => $this->contractFieldID($prefix, 'EnergieHeizenHeute'),
                'dailyEnergyCoolingID' => $this->contractFieldID($prefix, 'EnergieKuehlenHeute'),
                'dailyEnergyDHWID'     => $this->contractFieldID($prefix, 'EnergieWarmwasserHeute'),
                'dailyEnergyTotalID'   => $this->contractFieldID($prefix, 'EnergieGesamtHeute'),
                // contractVersion 1.13 (Dashboard-Anfrage 13.09.2026, Vorbild
                // ChargerHub/OCPPHub-Vertrag 1.3): Unix-Zeitstempel der letzten
                // ECHTEN, erfolgreichen Cloud-Antwort fuer dieses Geraet -- 0,
                // wenn noch nie erfolgreich gelesen. Wird NUR bei erfolgreichem
                // Abruf gesetzt (refreshDevices()), bleibt bei einem Cloud-
                // Ausfall unangetastet (markAllUnreachable() aendert nur
                // 'reachable', nie diesen Wert) -- Konsumenten wie Dashboard
                // koennen damit eingefrorene alte Werte von echten aktuellen
                // unterscheiden, ohne dass WPHub selbst "reachable" ueberladen
                // muesste (das bleibt reiner Cloud-Erreichbarkeits-Status).
                'lastSeenAt'           => (int)($d['lastSeenAt'] ?? 0),
                // contractVersion 1.14 (Dashboard-Nachfrage 13.09.2026, im
                // Zuge der lastSeenAt-Einfuehrung: "wie weit darf das
                // Frische-Fenster gesetzt werden?"): reale Abfragerate in
                // Sekunden statt dass Konsumenten einen Sicherheitsfaktor auf
                // einen geratenen Wert draufschlagen muessen -- Vorbild
                // MeterHub-Vertrag ("pollInterval"). Derselbe max(30, ...)-
                // Boden wie in ApplyChanges(), damit der gemeldete Wert immer
                // dem tatsaechlich gesetzten Timer-Intervall entspricht.
                'pollInterval'         => max(30, $this->ReadPropertyInteger('WPHUB_Interval')),
                // contractVersion 1.15 (mit EMS abgestimmt 13.09.2026, Muster
                // ChargerHubs managedBy -- bewusst NICHT InverterHubs
                // controlAuthority, da das EMS nie an die Waermepumpe
                // schreibt): welches Modul hat die Steuerhoheit ueber DIESES
                // Geraet. 'wphub' (Standard) = WPHub steuert selbst,
                // 'heishamon' = HeishaMon steuert lokal, WPHub liest nur,
                // 'other'/'none' siehe MANAGED_BY_VALUES. Bewusste
                // Nutzerangabe je Geraet (SetManagedBy()), nie automatisch
                // aus einer erkannten HeishaMon-Instanz abgeleitet -- die
                // Geraeteidentitaet laesst sich zwischen beiden Vertraegen
                // nicht beweisen.
                'managedBy'            => $this->deviceManagedBy($prefix),
            ];
        }
        return $out;
    }

    /** Variablen-ID zu Praefix+Ident, oder 0 wenn die Variable (noch) nicht existiert. */
    private function contractFieldID(string $prefix, string $ident): int
    {
        $id = @$this->GetIDForIdent($prefix . $ident);
        return ($id === false) ? 0 : (int)$id;
    }

    /**
     * Variablen-ID einer extern verknuepften Property (Ext_*), oder 0, wenn
     * nichts verknuepft ist oder die verknuepfte Variable inzwischen geloescht
     * wurde (SelectVariable haelt sonst eine tote ID).
     */
    private function extVariableID(string $property): int
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return 0;
        }
        return $id;
    }

    /**
     * Schaltet das Logging im Symcon-Archiv fuer die Messwerte (Fliesskomma-
     * Variablen) dieser Instanz ein -- AUSSCHLIESSLICH auf Klick im Formular.
     * Symcon-Review 30.10.2026: ein Modul darf Variablen nicht von selbst
     * archivieren, der Nutzer muss das bewusst einschalten. Schaltet nie etwas
     * aus, was schon laeuft.
     */
    public function EnableArchiving(): string
    {
        try {
            $archiveID = $this->archiveInstanceID();
            if ($archiveID === 0) {
                return WPHUB_Text::t('❌ No archive instance found -- logging cannot be enabled.');
            }
            $new = 0;
            $already = 0;
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
                if (IPS_GetObject($id)['ObjectType'] !== OBJECTTYPE_VARIABLE || IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_FLOAT) {
                    continue;
                }
                if (AC_GetLoggingStatus($archiveID, $id)) {
                    $already++;
                    continue;
                }
                AC_SetLoggingStatus($archiveID, $id, true);
                $new++;
            }
            if ($new > 0) {
                IPS_ApplyChanges($archiveID);
            }
            if ($new === 0 && $already === 0) {
                return WPHUB_Text::t('ℹ️ No measured values yet -- wait for a poll first, then click again.');
            }
            return $already > 0
                ? WPHUB_Text::t('✅ Logging enabled for %d measured value(s) (%d were already enabled).', $new, $already)
                : WPHUB_Text::t('✅ Logging enabled for %d measured value(s).', $new);
        } catch (\Throwable $e) {
            return WPHUB_Text::t('❌ Logging could not be enabled: %s', $e->getMessage());
        }
    }

    /**
     * Einheitliche Verbund-Status-Kopfzeile (SUITE.md, "Einheitliche Verbund-
     * Status-Kopfzeile", 20.08.2026, Referenz EMS' getDiscoverySummaryLine()):
     * <Icon> <Zahl> <Was> gefunden (zuletzt HH:MM:SS Uhr). WPHub hat keinen
     * separaten "Jetzt suchen"-Knopf -- die Geraeteliste aktualisiert sich bei
     * jedem erfolgreichen Login/Update()-Zyklus, LastDiscoveryTs spiegelt das.
     */
    private function discoverySummaryLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts <= 0) {
            return WPHUB_Text::t('ℹ️ Not searched yet.');
        }
        $devices = $this->readDeviceList();
        $count = count($devices);
        $icon = $count > 0 ? '✅' : '⚠️';
        return $count === 1
            ? WPHUB_Text::t('%s %d heat pump found (last at %s).', $icon, $count, date('H:i:s', $ts))
            : WPHUB_Text::t('%s %d heat pumps found (last at %s).', $icon, $count, date('H:i:s', $ts));
    }

    /**
     * Schiebt die aktuelle discoverySummaryLine() in ein BEREITS GEOEFFNETES
     * Formular (SUITE.md-Stolperfalle 12, 20.08.2026: GetConfigurationForm()
     * wird nach einer Aktion NICHT automatisch neu ausgefuehrt -- ohne diesen
     * Aufruf bliebe die Kopfzeile nach einem Login-/Zustimmungs-Klick auf dem
     * alten Stand, obwohl die Suche serverseitig laengst aktualisiert hat).
     * Bei geschlossenem Formular ein wirkungsloser Aufruf, kein Fehler.
     */
    private function refreshDiscoverySummary(): void
    {
        $this->UpdateFormField('DiscoverySummary', 'caption', $this->discoverySummaryLine());
    }

    /** Erste (i.d.R. einzige) Archiv-Control-Instanz im System, oder 0. */
    private function archiveInstanceID(): int
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return 0;
        }
        $instances = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (is_array($instances) && isset($instances[0])) ? (int)$instances[0] : 0;
    }

    /**
     * Sucht ueber alle installierten MeterHub-Instanzen nach einer Funktions-
     * zuordnung "Waermepumpe" (Vertrag MHUB_GetFunctions($id), Feld
     * 'function' === 'heatpump' -- MeterHub fuehrt dafuer bereits ein festes
     * Vokabular, siehe dessen eigene Doku). Rein lesend, MeterHub ist optional
     * (function_exists-Wache) und WPHub aendert an dessen Zuordnung nichts.
     * Liefert die erste gefundene Zuordnung mit mindestens einer Groesse
     * (Leistung oder Energie) als ['powerID','energyID','label','instanceID'],
     * oder null, wenn kein MeterHub installiert ist oder keine Waermepumpe
     * zugeordnet wurde.
     */
    private function meterHubHeatpumpAssignment(): ?array
    {
        if (!function_exists('MHUB_GetFunctions') || !function_exists('IPS_GetInstanceListByModuleID')) {
            return null;
        }
        try {
            $instances = @IPS_GetInstanceListByModuleID(self::METERHUB_MODULE_GUID);
            if (!is_array($instances)) {
                return null;
            }
            foreach ($instances as $instanceID) {
                $raw = @MHUB_GetFunctions((int)$instanceID);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($data) || !isset($data['assignments']) || !is_array($data['assignments'])) {
                    continue;
                }
                foreach ($data['assignments'] as $a) {
                    if (!is_array($a) || ($a['function'] ?? '') !== 'heatpump') {
                        continue;
                    }
                    $powerID  = (int)($a['powerID'] ?? 0);
                    $energyID = (int)($a['energyImportID'] ?? 0);
                    if ($powerID <= 0 && $energyID <= 0) {
                        continue;
                    }
                    return [
                        'powerID'    => $powerID,
                        'energyID'   => $energyID,
                        'label'      => (string)($a['label'] ?? WPHUB_Text::t('Heat pump')),
                        'instanceID' => (int)$instanceID,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('MeterHub-Erkennung', $e->getMessage(), 0);
        }
        return null;
    }

    /**
     * Koexistenz-HINWEIS (13.09.2026, Verbund-Konfliktpruefung ueber
     * ChargerHub/HeishaMon angestossen -- seit der EMS-Praezisierung vom
     * selben Tag NUR NOCH ein Vorschlag im Formular, KEINE eigene Blockade
     * mehr). HeishaMon bridged Panasonic-Aquarea-Waermepumpen lokal per MQTT
     * -- existiert eine aktive HeishaMon-Instanz, KANN sie dasselbe Geraet
     * meinen, muss aber nicht (Dietmar hat aktuell nur eine Anlage, aber die
     * Regel soll auch bei einer zweiten, nur-WPHub-Waermepumpe nicht
     * faelschlich blockieren). Die eigentliche Steuerhoheit steht deshalb
     * NICHT hier, sondern in deviceManagedBy() -- eine bewusste Nutzerangabe
     * je Geraet, siehe MANAGED_BY_VALUES. HeishaMon hat keine eigene "aktiv"-
     * Eigenschaft; InstanceStatus 102 ("Aktiv") ist der von HeishaMon selbst
     * genannte naechstliegende Signal-Ersatz.
     */
    private function heishaMonCoexistenceWarning(): ?string
    {
        if (!function_exists('IPS_GetInstanceListByModuleID') || !function_exists('IPS_GetInstance')) {
            return null;
        }
        try {
            $instances = @IPS_GetInstanceListByModuleID(self::HEISHAMON_MODULE_GUID);
            if (!is_array($instances)) {
                return null;
            }
            foreach ($instances as $instanceID) {
                $inst = @IPS_GetInstance((int)$instanceID);
                if (is_array($inst) && (int)($inst['InstanceStatus'] ?? 0) === 102) {
                    return WPHUB_Text::t('ℹ️ An active HeishaMon instance (#%d) was found. If it controls the same heat pump: choose "HeishaMon" below at "Who controls this heat pump?" so that WPHub does not write in parallel.', (int)$instanceID);
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('HeishaMon-Koexistenz', $e->getMessage(), 0);
        }
        return null;
    }

    /**
     * Steuerhoheit fuer ein Geraet (managedBy, contractVersion 1.15) --
     * bewusste Nutzerangabe, NIE automatisch aus heishaMonCoexistenceWarning()
     * abgeleitet (EMS-Entscheid 13.09.2026: die Geraete-Identitaet zwischen
     * WPHub und HeishaMon laesst sich nicht beweisen). Unbekannte/fehlende
     * Werte fallen sicher auf MANAGED_BY_DEFAULT ('wphub') zurueck, damit
     * eine frische Installation ohne HeishaMon sich durch dieses Feld nicht
     * aendert.
     */
    /**
     * Beschriftung des je Geraet dynamisch erzeugten "Steuerhoheit"-Select
     * (GetConfigurationForm()) -- als eigene Methode ausgelagert, damit
     * SetManagedBy() dieselbe Berechnung fuer den Live-Refresh per
     * UpdateFormField() nutzen kann (SUITE.md-Stolperfalle 12, siehe
     * refreshDiscoverySummary(): ein bereits offenes Formular liest sich
     * nach einer Aktion nicht von selbst neu ein).
     */
    private function managedBySelectCaption(string $prefix, string $deviceName, bool $heishaMonActive): string
    {
        $needsAttention = ($heishaMonActive && !$this->deviceManagedByIsExplicit($prefix));
        return $needsAttention
            ? WPHUB_Text::t('⚠️ Not assigned yet -- %s: Who controls this heat pump?', $deviceName)
            : WPHUB_Text::t('%s: Who controls this heat pump?', $deviceName);
    }

    private function deviceManagedBy(string $prefix): string
    {
        $map = json_decode((string)$this->ReadPropertyString('DeviceManagedBy'), true);
        $value = is_array($map) ? (string)($map[$prefix] ?? '') : '';
        return in_array($value, self::MANAGED_BY_VALUES, true) ? $value : self::MANAGED_BY_DEFAULT;
    }

    /**
     * Wurde die Steuerhoheit fuer dieses Geraet tatsaechlich vom Nutzer
     * gesetzt, oder greift nur der stille Standard 'wphub'? Unterscheidung
     * noetig fuer managedByNeedsAttention() -- ein leerer Eintrag ist bei
     * fehlendem HeishaMon voellig normal, aber bei aktiver HeishaMon-Instanz
     * eine echte Luecke (HeishaMon-Fund 13.09.2026, live an Dietmars Instanz
     * #57727 bestaetigt: DeviceManagedBy stand nach dem Umbau auf '{}',
     * WPHub haette damit stillschweigend wieder parallel geschrieben).
     */
    private function deviceManagedByIsExplicit(string $prefix): bool
    {
        $map = json_decode((string)$this->ReadPropertyString('DeviceManagedBy'), true);
        $value = is_array($map) ? (string)($map[$prefix] ?? '') : '';
        return in_array($value, self::MANAGED_BY_VALUES, true);
    }

    /**
     * Liste der Geraete-Namen, deren Steuerhoheit noch nicht gesetzt ist,
     * OBWOHL eine aktive HeishaMon-Instanz gefunden wurde -- genau die
     * stille Luecke aus dem HeishaMon-Fund 13.09.2026. Leer, wenn HeishaMon
     * nicht aktiv ist (dann ist der Standard 'wphub' der Normalfall, keine
     * Warnung noetig) oder jedes bekannte Geraet bereits explizit zugeordnet
     * ist.
     */
    private function managedByNeedsAttention(): array
    {
        if ($this->heishaMonCoexistenceWarning() === null) {
            return [];
        }
        $devices = $this->readDeviceList();
        $names = [];
        foreach ($devices as $d) {
            $devPrefix = (string)($d['prefix'] ?? '');
            if ($devPrefix !== '' && !$this->deviceManagedByIsExplicit($devPrefix)) {
                $names[] = (string)($d['name'] ?? WPHUB_Text::t('Heat pump'));
            }
        }
        return $names;
    }

    /**
     * Setzt die Steuerhoheit fuer ein Geraet -- ausschliesslich auf Klick/
     * Auswahl im Formular (dynamisch je Geraet erzeugtes Select, siehe
     * GetConfigurationForm()), nie automatisch. Gleiches Muster wie
     * SetManagedBy(): IPS_SetProperty()+IPS_ApplyChanges() ist hier der
     * EINZIGE Persistenzweg -- ManagedBy_<prefix> ist ein rein synthetisches
     * Formularfeld (kein eigenes RegisterProperty), die echte Property
     * DeviceManagedBy ist ein einzelnes JSON-Objekt fuer ALLE Geraete. Der
     * generische "Änderungen übernehmen"-Knopf am Formularende kann dieses
     * Feld also gar nicht selbst speichern -- anders als bei
     * AdoptMeterHubAssignment() (siehe dort), wo Ext_PowerVariable/
     * Ext_EnergyVariable echte RegisterProperty-Felder sind.
     *
     * Store-Review-Fund (HeishaMon, 28.09.2026, urspruenglich an deren
     * AdoptMeterHubAssignment() bemaengelt, dieselbe Stelle auch hier
     * gefunden): IPS_ApplyChanges() gefolgt von UpdateFormField() auf
     * dieselbe Formular-Session ist wirkungslos, weil ApplyChanges() das
     * offene Formular automatisch neu laedt (Symcon-Reviewer-Zitat: "Bei
     * ApplyChanges wird das Formular neu geladen und die UpdateFormField
     * gehen verloren"). Der fruehere Kommentar hier hat nur begruendet, WARUM
     * die Property-Aenderung selbst legitim ist -- das war nie die Frage,
     * der mechanische Effekt gilt unabhaengig davon. Die vormals nachfolgenden
     * UpdateFormField()-Aufrufe wurden deshalb entfernt: Sie waren tatsaechlich
     * bereits wirkungslose tote Last, denn der automatische Formular-Reload
     * nach ApplyChanges() ruft GetConfigurationForm() ohnehin neu auf, und die
     * dort berechnete Select-Beschriftung (managedBySelectCaption()) liest
     * bereits live von der (jetzt aktuellen) Property -- die Beschriftung ist
     * nach dem Reload also von selbst korrekt.
     */
    public function SetManagedBy(string $prefix, string $value): void
    {
        if (!in_array($value, self::MANAGED_BY_VALUES, true)) {
            $value = self::MANAGED_BY_DEFAULT;
        }
        $map = json_decode((string)$this->ReadPropertyString('DeviceManagedBy'), true);
        if (!is_array($map)) {
            $map = [];
        }
        $map[$prefix] = $value;
        IPS_SetProperty($this->InstanceID, 'DeviceManagedBy', json_encode($map));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Uebernimmt eine per meterHubHeatpumpAssignment() gefundene Zuordnung in
     * Ext_PowerVariable/Ext_EnergyVariable -- ausschliesslich auf Klick der
     * Formular-Schaltflaeche (siehe GetConfigurationForm), nie automatisch im
     * Update()-Zyklus: die Verknuepfung ist eine Entscheidung des Nutzers,
     * kein stiller Hintergrundabgleich (gleiches Prinzip wie AcceptAgreements).
     * Kein SUITE.md-🔗-Automatik-Fall trotz UpdateFormField(...,'value',...) --
     * Begruendung siehe Kommentar an der MeterHubSuggestion-Stelle in
     * GetConfigurationForm() (EMS-Klarstellung 28.09.2026).
     *
     * Store-Review-Fund (HeishaMon, 28.09.2026): Diese Funktion rief bisher
     * IPS_SetProperty()+IPS_ApplyChanges() auf UND danach UpdateFormField()
     * auf dieselbe Formular-Session -- wirkungslos, weil ApplyChanges() das
     * offene Formular automatisch neu laedt (Symcon-Reviewer-Zitat: "Bei
     * ApplyChanges wird das Formular neu geladen und die UpdateFormField
     * gehen verloren"). Ext_PowerVariable/Ext_EnergyVariable sind echte
     * RegisterProperty-Felder -- deshalb genuegt es, nur noch die WERTE ins
     * offene Formular zu schreiben (UpdateFormField 'value'); der generische
     * "Änderungen übernehmen"-Knopf am Formularende persistiert sie, sobald
     * der Nutzer den Vorschlag geprueft hat und selbst klickt -- kein
     * automatisches Speichern mehr, analog HeishaMons eigenem Fix derselben
     * Stelle.
     */
    public function AdoptMeterHubAssignment(): void
    {
        $found = $this->meterHubHeatpumpAssignment();
        if ($found === null) {
            $this->UpdateFormField('MeterHubResult', 'caption', WPHUB_Text::t('❌ No MeterHub assignment "Heat pump" found (any more).'));
            $this->UpdateFormField('MeterHubResult', 'visible', true);
            return;
        }
        if ($found['powerID'] > 0) {
            $this->UpdateFormField('Ext_PowerVariable', 'value', $found['powerID']);
        }
        if ($found['energyID'] > 0) {
            $this->UpdateFormField('Ext_EnergyVariable', 'value', $found['energyID']);
        }
        $this->UpdateFormField('MeterHubResult', 'caption', WPHUB_Text::t('✅ Adopted from MeterHub "%s" into the open form -- click "Apply changes" below to save.', $found['label']));
        $this->UpdateFormField('MeterHubResult', 'visible', true);
        $this->UpdateFormField('MeterHubSuggestion', 'visible', false);
    }

    /**
     * Bildet unseren ExtendedOperationMode (0=Aus,1=Heizen,2=Kühlen,
     * 3=AutoHeizen,4=AutoKühlen) zusammen mit dem Warmwasser-Aktivstatus auf
     * den Verbund-weiten Enum ab (EMS/SUITE.md, mit HeishaMon abgestimmt
     * 13.08.2026): 0=standby,1=heating,2=cooling,3=dhw,4=heating+dhw,
     * 5=cooling+dhw,-1=unbekannt. Auto-Modi zaehlen als ihre aktuell aktive
     * Richtung (die API meldet AutoHeizen/AutoKuehlen bereits als konkrete
     * Richtung, nicht als generisches "Auto").
     */
    private function normalizeOperatingMode(?int $operationMode, bool $dhwActive): int
    {
        if ($operationMode === null) {
            return -1;
        }
        switch ($operationMode) {
            case 0:
                $direction = 0; // Aus -> standby
                break;
            case 1:
            case 3:
                $direction = 1; // Heizen / Auto Heizen -> heating
                break;
            case 2:
            case 4:
                $direction = 2; // Kühlen / Auto Kühlen -> cooling
                break;
            default:
                return -1; // unbekannt
        }
        if (!$dhwActive) {
            return $direction;
        }
        if ($direction === 0) {
            return 3; // nur Warmwasser -> dhw
        }
        return ($direction === 1) ? 4 : 5; // heating+dhw / cooling+dhw
    }

    /**
     * Vaillant state.system.energy_manager_state -> Verbund-Enum
     * (WPHUB.BetriebsartNorm, dieselbe Bedeutung wie bei Panasonic/
     * normalizeOperatingMode()). Zuordnung an einem echten, ueber mehrere
     * Zyklen beobachteten Warmwasser-Ladevorgang bestaetigt (Markus/
     * m_rothenpieler, Forum-Post #38, 26.09.2026): DHW waehrend aktiver
     * Speicherladung, HEATING waehrend Heizbetrieb, STANDBY in Bereitschaft.
     * "Kuehlen" (2/4/5) ist fuer Vaillant noch NIE beobachtet worden -- jeder
     * andere/unbekannte Rohwert bleibt bewusst -1 (Unbekannt) statt geraten
     * einem der Kuehl-Zustaende zugeordnet zu werden.
     */
    private function normalizeVaillantEnergyManagerState(?string $energyManagerState): int
    {
        switch ($energyManagerState) {
            case 'STANDBY':
                return 0;
            case 'HEATING':
                return 1;
            case 'DHW':
                return 3;
            default:
                return -1; // unbekannt (inkl. noch nie beobachteter Werte)
        }
    }

    /**
     * Steuerung: WebFront/EMS aendert eine der per EnableAction() freige-
     * gebenen Variablen (Fluesterbetrieb, Leistungsbetrieb, Urlaubstimer,
     * Notbetriebe, Warmwasser-/Zonen-Sollwert). Der Praefix (9 Zeichen, siehe
     * devicePrefix()) bestimmt das Geraet, der Rest des Idents den Befehl.
     * Bei Erfolg wird die Variable auf den neuen Wert gesetzt, sonst bleibt
     * sie auf dem letzten bestaetigten Cloud-Stand und eine Protokollzeile
     * erklaert, warum.
     */
    public function RequestAction($Ident, $Value)
    {
        if (strlen($Ident) <= 9) {
            $this->LogMessage(WPHUB_Text::t('RequestAction: unknown ident %s', (string)$Ident), KL_WARNING);
            return;
        }
        $prefix = substr($Ident, 0, 9);
        $field = substr($Ident, 9);

        $devices = $this->readDeviceList();
        $dev = null;
        foreach ($devices as $d) {
            if (($d['prefix'] ?? '') === $prefix) {
                $dev = $d;
                break;
            }
        }
        if ($dev === null) {
            $this->LogMessage(WPHUB_Text::t('RequestAction: device for %s not found.', (string)$Ident), KL_WARNING);
            return;
        }

        $bundle = $this->ensureToken();
        if ($bundle === null) {
            $this->LogMessage(WPHUB_Text::t('RequestAction: no valid login.'), KL_WARNING);
            return;
        }
        $this->applyControl($Ident, $field, $Value, $dev, $bundle, $this->ccClient());
    }

    /**
     * Testbarer Kern von RequestAction() -- Client als Parameter injizierbar,
     * gleiches Muster wie refreshDevices(). Setzt die Variable nur bei
     * bestaetigtem Cloud-Erfolg; schlaegt der Befehl fehl, bleibt sie auf dem
     * letzten bekannten Stand und eine Protokollzeile erklaert, warum.
     */
    private function applyControl(string $ident, string $field, $value, array $dev, array $bundle, WPHUB_ComfortCloudClient $client): void
    {
        // Dietmars Vorrang-Entscheidung (13.09.2026, mit EMS auf "je Geraet"
        // praezisiert): steht die Steuerhoheit dieses Geraets nicht auf
        // 'wphub' (managedBy, Nutzerangabe -- siehe deviceManagedBy()),
        // steuert WPHub fuer GENAU DIESES Geraet nicht, unabhaengig vom
        // Aufrufweg (WebFront-Klick, EMS, Skript). Andere Geraete desselben
        // Kontos bleiben davon unberuehrt. Variable bleibt auf dem letzten
        // bestaetigten Stand, wie bei jedem anderen Fehlschlag auch.
        $managedBy = $this->deviceManagedBy((string)($dev['prefix'] ?? ''));
        if ($managedBy !== self::MANAGED_BY_DEFAULT) {
            $this->LogMessage(WPHUB_Text::t('Control command (%s) blocked: the control authority of this device is set to "%s", not "wphub".', $field, $managedBy), KL_WARNING);
            return;
        }

        $guid = (string)$dev['guid'];

        if ($field === 'Fluesterbetrieb') {
            $ok = $client->setQuietMode($bundle, $guid, (int)$value);
        } elseif ($field === 'Leistungsbetrieb') {
            $ok = $client->setPowerfulTime($bundle, $guid, (int)$value);
        } elseif ($field === 'Urlaubstimer') {
            $ok = $client->setHolidayTimer($bundle, $guid, (bool)$value);
        } elseif ($field === 'NotbetriebWarmwasser') {
            $ok = $client->setForceDHW($bundle, $guid, (bool)$value);
        } elseif ($field === 'NotHeizbetrieb') {
            $ok = $client->setForceHeater($bundle, $guid, (bool)$value);
        } elseif ($field === 'WarmwasserSoll') {
            $ok = $client->setTankTemperature($bundle, $guid, (float)$value);
        } elseif (preg_match('/^Zone(\d+)Soll$/', $field, $m) === 1) {
            // ExtendedOperationMode 2/4 = Kuehlen -> coolSet, sonst heatSet
            // (0=Aus,1=Heizen,3=Auto Heizen zaehlen als Heizen-Kontext).
            $mode = $dev['operationMode'] ?? null;
            $key = in_array($mode, [2, 4], true) ? 'coolSet' : 'heatSet';
            $ok = $client->setZoneTemperature($bundle, $guid, (int)$m[1], (float)$value, $key);
        } else {
            $this->LogMessage(WPHUB_Text::t('RequestAction: unknown control field %s', $field), KL_WARNING);
            return;
        }

        if ($ok) {
            $this->SetValue($ident, $value);
        } else {
            $this->LogMessage(WPHUB_Text::t('Control command (%s) failed: %s', $field, $client->getLastError()), KL_WARNING);
        }
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    const APP_VERSION_DEFAULT = '4.4.0';
    // Gruen fuer automatisch uebernommene Werte (SUITE.md, Regel "Wert kommt automatisch").
    const AUTO_LINE_COLOR = 0x2E8B3D;

    /**
     * Welche Comfort-Cloud-App-Version gilt gerade, und woher kommt sie?
     * Vorrang: zuletzt automatisch ermittelte Version -> manueller Notnagel
     * aus dem Formular -> Code-Standard (Stand 08/2026). Gemeinsam genutzt von
     * ccClient() und der Statuszeile im Formular, damit beide dasselbe sagen.
     *
     * @return array{0: string, 1: string} [Version, Quelle: auto|manual|default]
     */
    private function effectiveAppVersion(): array
    {
        $auto = trim($this->ReadAttributeString('CC_AppVersionAuto'));
        if ($auto !== '') {
            return [$auto, 'auto'];
        }
        $manual = trim($this->ReadPropertyString('CC_AppVersion'));
        if ($manual !== '') {
            return [$manual, 'manual'];
        }
        return [self::APP_VERSION_DEFAULT, 'default'];
    }

    /**
     * Statuszeile zur App-Version in den Zustaenden der Verbund-Regel (SUITE.md
     * "Verbund-Verbindungen im Formular sichtbar machen" + "Wert kommt
     * automatisch: Eingabefeld ersetzen"): 🔗 automatisch ermittelt (Eingabefeld
     * ausgeblendet), ✏️ eigene Angabe (Feld sichtbar), ℹ️ nichts automatisch
     * (Feld sichtbar). Liefert [Zeile, Feld sichtbar, Farbe]: 🔗 GRUEN
     * (0x2E8B3D, Verbund-Regel), sonst -1 = Standardfarbe.
     *
     * @return array{0: string, 1: bool, 2: int}
     */
    private function appVersionStatus(): array
    {
        [$version] = $this->effectiveAppVersion();
        $auto = trim($this->ReadAttributeString('CC_AppVersionAuto'));
        $manual = trim($this->ReadPropertyString('CC_AppVersion'));

        if ($auto !== '' && $manual === '') {
            return [WPHUB_Text::t('🔗 App version: %s (determined automatically, source: Play Store or AppBrain, after the Comfort Cloud had rejected an older version). The input field only appears when nothing is determined automatically.', $auto), false, self::AUTO_LINE_COLOR];
        }
        if ($auto !== '' && $manual !== '') {
            if ($manual === $auto) {
                return [WPHUB_Text::t('✏️ App version: %s (your own entry, matches the automatically determined version). Empty the field to use only the automatic one again.', $manual), true, -1];
            }
            return [WPHUB_Text::t('✏️ Your own app version %s was rejected by the Comfort Cloud, the automatically determined version %s applies (takes precedence). Empty the field to use only the automatic one again.', $manual, $auto), true, -1];
        }
        if ($manual !== '') {
            return [WPHUB_Text::t('✏️ App version: %s (your own entry, takes precedence over the module default). If the Comfort Cloud rejects it (error code 4106), WPHub determines the current version itself and then uses that.', $manual), true, -1];
        }
        return [WPHUB_Text::t('ℹ️ App version: %s (module default, nothing determined automatically so far). If the Comfort Cloud rejects it (error code 4106), WPHub determines the current version itself. The field below is only the fallback in case that does not work.', $version), true, -1];
    }

    private function ccClient(): WPHUB_ComfortCloudClient
    {
        [$appVersion] = $this->effectiveAppVersion();
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
        $this->LogMessage(WPHUB_Text::t('Comfort Cloud app version automatically updated to %s.', $new), KL_NOTIFY);
        return true;
    }

    /** Token-Buendel aus dem Attribut, null wenn (noch) keines da ist. */
    private function tokenBundle(): ?array
    {
        $bundle = json_decode((string)$this->ReadAttributeString('CC_Token'), true);
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
            $this->LogMessage(WPHUB_Text::t('Comfort Cloud access key expired and renewal failed (%s) — please log in again in the form.', $client->getLastError()), KL_WARNING);
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

    // ------------------------------------------------------------------
    // Vaillant myVAILLANT -- eigener, paralleler Zweig statt Umbau der
    // Panasonic-Pfade oben (Muster InverterHub: je Hersteller ein eigener
    // Treiber/Zweig, siehe MANUFACTURER_VALUES). Stand 14.09.2026 bewusst
    // NUR lesend (keine Steuerbefehle) und ungeprueft an einer echten Cloud
    // -- siehe VaillantClient.php.
    // ------------------------------------------------------------------

    /**
     * Baut den Vaillant-Client. Testseam: der Pruefstand kann eine
     * Fabrikfunktion unter $GLOBALS['ips']['vaillantClientFactory']
     * hinterlegen (liefert dann eine Attrappe statt eines echten
     * curl-Clients) -- Muster identisch zu WPBsbLan::bsbLanClient().
     */
    private function vaillantClient(): WPHUB_VaillantClient
    {
        if (isset($GLOBALS['ips']['vaillantClientFactory']) && is_callable($GLOBALS['ips']['vaillantClientFactory'])) {
            return ($GLOBALS['ips']['vaillantClientFactory'])();
        }
        $country = trim($this->ReadPropertyString('VAI_Country'));
        if ($country === '' || !isset(self::VAILLANT_COUNTRIES[$country])) {
            $country = 'germany';
        }
        return new WPHUB_VaillantClient($country, function (string $topic, string $text) {
            $this->SendDebug('Vaillant/' . $topic, $text, 0);
        });
    }

    /**
     * Lokale Zeitzone des gewaehlten myVAILLANT-Kontos (VAI_Country) --
     * Fund 29.09.2026 (m_rothenpieler, Forum-Post): die EMF-Tagesenergie
     * lief bisher auf UTC-Mitternacht statt Ortszeit, der Tageswechsel haette
     * damit bei ihm um 01:00/02:00 Uhr statt Mitternacht stattgefunden. Alle
     * VAILLANT_COUNTRIES ausser dem Vereinigten Koenigreich liegen in derselben
     * CET/CEST-Zone mit identischen EU-weiten Umstellungsterminen -- "Europe/
     * Berlin" ist dafuer eine unmittelbar zutreffende IANA-Zeitzone, nicht nur
     * ein Naeherungswert. Das Vereinigte Koenigreich (GMT/BST, andere
     * Zeitzone) bekommt "Europe/London". Siehe maintainDeviceEnergyVaillant().
     */
    private function vaillantLocalTimezone(): \DateTimeZone
    {
        $country = trim($this->ReadPropertyString('VAI_Country'));
        return new \DateTimeZone($country === 'unitedkingdom' ? 'Europe/London' : 'Europe/Berlin');
    }

    /**
     * "Heute" als UTC-Instanzen (Ortszeit-Mitternacht bis jetzt) -- eine
     * einzige Stelle fuer beide Verwender (maintainDeviceEnergyVaillant() im
     * echten Takt UND TestEmfEndpoint() als manueller Testabruf), damit
     * beide IMMER dieselbe Anfrage stellen. Fund 30.09.2026 (m_rothenpieler,
     * Forum-Post): WPHub zeigte fuer "Heizen heute" 0,03 kWh, die
     * myVAILLANT-App 0,9 kWh -- Warmwasser passte exakt. Ein an Ortszeit-
     * Mitternacht beginnendes Fenster ueberlappt im Sommer (CEST, UTC+2)
     * zwei UTC-Tage -- CONFIRMED 30.09.2026 (echter Rohdump "dump (7).txt"):
     * bei resolution=DAY ist total_consumption NICHT auf das Fenster geclippt,
     * siehe maintainDeviceEnergyVaillant()-Kommentar fuer den Fix (resolution
     * HOUR + eigene Summierung). Vorher lief TestEmfEndpoint() mit einem
     * eigenen "letzte 3 Tage"-Fenster, das dieses Randproblem gar nicht
     * zeigen konnte (mehrere volle UTC-Tage, kein angeschnittener). Jetzt
     * dasselbe Fenster wie der echte Takt -- ein Klick liefert damit exakt
     * die Rohdaten, die der echte Takt auch verwendet.
     */
    private function vaillantTodayWindowUtc(): array
    {
        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = (new \DateTimeImmutable('now', $this->vaillantLocalTimezone()))
            ->setTime(0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'));
        return [$from, $to];
    }

    /**
     * ISO8601-Zeitstempel (wie in den Buckets-data[]-Eintraegen, z. B.
     * "2026-09-28T22:00:00Z") in eine DateTimeImmutable-Instanz -- liefert
     * NULL statt einer Exception bei fehlendem/ungueltigem Wert (Rohdaten
     * einer externen API, nie ungeprueft vertrauen). Siehe
     * maintainDeviceEnergyVaillant() fuer die Verwendung.
     */
    private function parseVaillantIsoInstant(?string $iso): ?\DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Token-Buendel aus dem Vaillant-Attribut, null wenn (noch) keines da ist. */
    private function vaillantTokenBundle(): ?array
    {
        $bundle = json_decode((string)$this->ReadAttributeString('VAI_Token'), true);
        if (!is_array($bundle) || ($bundle['accessToken'] ?? '') === '') {
            return null;
        }
        return $bundle;
    }

    /** Vaillant-Gegenstueck zu ensureToken() -- gleiches Erneuerungs-Muster. */
    private function vaillantEnsureToken(): ?array
    {
        $bundle = $this->vaillantTokenBundle();
        if ($bundle === null) {
            $this->SetStatus(201);
            return null;
        }
        if ((int)($bundle['expiresAt'] ?? 0) - 300 > time()) {
            return $bundle;
        }
        $client = $this->vaillantClient();
        $new = $client->refresh($bundle);
        if ($new === null) {
            // Fund 25.09.2026 (m_rothenpieler, Forum-Post #28): schlug die
            // Erneuerung wegen desselben API-Kontingent-Fehlers fehl wie
            // refreshDevicesVaillant() (siehe VaillantClient::failApi()),
            // GALT DAS BISHER ALS "Zugangsschluessel kaputt, Timer aus" --
            // bei einer bloss vorruebergehenden Kontingent-Sperre haette der
            // Nutzer den Timer nie automatisch wieder anlaufen sehen, egal
            // wie lange er wartet (musste manuell neu anmelden/ApplyChanges).
            // Jetzt: gleiche Sperrfrist wie beim Datenabruf, Timer bleibt an.
            if ($client->quotaRetryAfterSeconds !== null) {
                $this->WriteAttributeInteger('VAI_RetryNotBefore', time() + $client->quotaRetryAfterSeconds);
                $this->LogMessage(WPHUB_Text::t('Vaillant API quota used up (access key renewal) -- next attempt in %d s (%s).', $client->quotaRetryAfterSeconds, $client->getLastError()), KL_WARNING);
                return null;
            }
            $this->LogMessage(WPHUB_Text::t('myVAILLANT access key expired and renewal failed (%s) — please log in again in the form.', $client->getLastError()), KL_WARNING);
            $this->SetStatus(201);
            $this->SetTimerInterval('WPHUB_UpdateTimer', 0);
            return null;
        }
        $this->WriteAttributeString('VAI_Token', json_encode($new));
        return $new;
    }

    /**
     * Anlagenanmeldung -- ausschliesslich auf Klick der Formular-
     * Schaltflaeche, nie automatisch (gleiches Muster wie Login()). Steuert
     * bewusst keine Geraete (Stand 14.09.2026, siehe Klassenkopf
     * VaillantClient.php) -- nur Anmeldung + Geraeteliste + Basiswerte.
     */
    public function LoginVaillant(): void
    {
        $say = function (string $m) {
            $this->UpdateFormField('VAI_Result', 'caption', $m);
            $this->UpdateFormField('VAI_Result', 'visible', true);
            trigger_error('WPHUB_LoginVaillant #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        };

        $email = trim($this->ReadPropertyString('VAI_Email'));
        $pass  = (string)$this->ReadPropertyString('VAI_Password');
        if ($email === '' || $pass === '') {
            $say(WPHUB_Text::t('❌ Please enter e-mail and password first and apply, then log in.'));
            return;
        }

        $client = $this->vaillantClient();
        $bundle = $client->login($email, $pass);
        if ($bundle === null) {
            $say('❌ ' . $client->getLastError());
            return;
        }

        // Passwort erst ganz am Ende verwerfen (discardLoginPassword()),
        // sonst gingen die UpdateFormField()-Aufrufe in $say() verloren.
        $this->WriteAttributeString('VAI_Token', json_encode($bundle));

        // Manuelle Anmeldung erzwingt eine frische Anlagenliste, auch wenn
        // VAI_SystemsCache noch innerhalb der TTL waere (z. B. neu
        // hinzugefuegte Anlage im myVAILLANT-Konto) -- siehe vaillantSystemsCache().
        $this->WriteAttributeInteger('VAI_SystemsCacheAt', 0);
        $devices = $this->refreshDevicesVaillant($bundle, $client);
        if ($devices === null) {
            $say(WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('The system list could not be loaded yet (%s) — it will be tried again on the next update run.', $client->getLastError()));
            $this->discardLoginPassword('VAI_Password');
            return;
        }
        $this->refreshDiscoverySummary();
        if (count($devices) === 0) {
            $say(WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('But no system was found in the account.'));
            $this->discardLoginPassword('VAI_Password');
            return;
        }
        $lines = [WPHUB_Text::t('✅ Logged in, access key saved, password discarded.') . ' ' . WPHUB_Text::t('Systems found:')];
        foreach ($devices as $d) {
            $lines[] = '   • ' . $d['name'] . ($d['reachable'] ? '' : ' ' . WPHUB_Text::t('(currently not reachable)'));
        }
        $say(implode("\n", $lines));
        $this->discardLoginPassword('VAI_Password');
    }

    /**
     * Bekannte Geraete-Rollen einer EMF-currentSystem-Antwort als flache
     * Liste ['rolle-oder-rolle[index]' => Geraet-Array], nur tatsaechlich
     * vorhandene (nicht-null) Geraete -- Struktur an m_rothenpielers echter
     * Kaskaden-Antwort bestaetigt (29.09.2026, siehe getCurrentSystemEmf()-
     * Kommentar): primary_heat_generator/electric_backup_heater sind
     * einzelne Objekte, secondary_heat_generators eine Liste,
     * gateway/solar_station/ventilation koennen null sein.
     */
    private function emfDeviceRoles(array $emf): array
    {
        $out = [];
        foreach (['primary_heat_generator', 'electric_backup_heater', 'gateway', 'solar_station', 'ventilation'] as $role) {
            if (is_array($emf[$role] ?? null)) {
                $out[$role] = $emf[$role];
            }
        }
        $secondary = $emf['secondary_heat_generators'] ?? [];
        if (is_array($secondary)) {
            foreach ($secondary as $i => $device) {
                if (is_array($device)) {
                    $out['secondary_heat_generators[' . $i . ']'] = $device;
                }
            }
        }
        return $out;
    }

    /**
     * Manueller Test-Abruf der EMF-Energiedaten (Forum-Post #48/#49,
     * m_rothenpieler: Stromverbrauch/erzeugte Waermemenge fehlen noch
     * komplett -- laut myPyllant-Quelltext ueber /emf/v2/{systemId}/
     * currentSystem verfuegbar, siehe VaillantClient::getCurrentSystemEmf()).
     * AUSSCHLIESSLICH auf Klick, NIE Teil des normalen Update()-Zyklus --
     * Markus hat ausdruecklich gebeten, das Kontingent nicht mit einer
     * zweiten Abfrage im 300/600s-Takt zu belasten.
     *
     * Zweistufig, beide Stufen per SendDebug: (1) currentSystem liefert die
     * Geraete + ihre verfuegbaren Energiekanaele (siehe emfDeviceRoles()),
     * (2) fuer JEDEN gefundenen Kanal zusaetzlich das Buckets-Fenster
     * abrufen. Mehrere API-Aufrufe in diesem einen manuellen Klick sind hier
     * vertretbar (typischerweise ein bis zwei Dutzend bei einer Kaskade mit
     * Zusatzgeraet) -- die Kontingent-Sorge betraf ausdruecklich den
     * AUTOMATISCHEN 300/600s-Takt, nicht einen seltenen, bewussten
     * Debug-Klick.
     *
     * Fenster seit 30.09.2026 identisch zu maintainDeviceEnergyVaillant()
     * (vaillantTodayWindowUtc(), Ortszeit-Mitternacht bis jetzt) -- vorher
     * lief dieser Knopf mit einem eigenen "letzte 3 Tage"-Fenster, das
     * IMMER volle UTC-Tage abfragte und deshalb den UTC/Ortszeit-
     * Randfall (Fund m_rothenpieler, Forum-Post: "Heizen heute" 0,03 statt
     * 0,9 kWh laut myVAILLANT-App) gar nicht zeigen konnte. Jetzt liefert
     * ein Klick exakt die Rohdaten, die der naechste echte Energie-Takt
     * auch verwenden wuerde -- seit 30.09.2026 auch dieselbe Aufloesung
     * (HOUR statt DAY, siehe Klassenkommentar an maintainDeviceEnergyVaillant()
     * fuer die Begruendung: DAY-Buckets waren bei einem UTC-Tag-uebergreifenden
     * Fenster nicht auf das Fenster geclippt).
     */
    public function TestEmfEndpoint(): void
    {
        $say = function (string $m) {
            $this->UpdateFormField('VAI_EmfResult', 'caption', $m);
            $this->UpdateFormField('VAI_EmfResult', 'visible', true);
        };

        $bundle = $this->vaillantEnsureToken();
        if ($bundle === null) {
            $say(WPHUB_Text::t('❌ Not logged in (or login currently blocked because of the quota) -- log in above first.'));
            return;
        }
        $devices = $this->readDeviceList();
        if (count($devices) === 0) {
            $say(WPHUB_Text::t('❌ No system known -- log in above first and search for systems.'));
            return;
        }
        $client = $this->vaillantClient();
        $ok = 0;
        $bucketsOk = 0;
        [$from, $to] = $this->vaillantTodayWindowUtc();
        foreach ($devices as $d) {
            $systemId = (string)($d['guid'] ?? '');
            if ($systemId === '') {
                continue;
            }
            $emf = $client->getCurrentSystemEmf($bundle, $systemId);
            if ($emf === null) {
                continue;
            }
            $this->SendDebug('Vaillant/emf-currentSystem-Rohdaten', json_encode($emf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
            $ok++;

            foreach ($this->emfDeviceRoles($emf) as $role => $device) {
                $deviceUuid = (string)($device['device_uuid'] ?? '');
                $channels = is_array($device['data'] ?? null) ? $device['data'] : [];
                if ($deviceUuid === '') {
                    continue;
                }
                foreach ($channels as $channel) {
                    $opMode = (string)($channel['operation_mode'] ?? '');
                    $valType = (string)($channel['value_type'] ?? '');
                    if ($opMode === '' || $valType === '') {
                        continue;
                    }
                    $buckets = $client->getDeviceDataBuckets($bundle, $systemId, $deviceUuid, $opMode, $valType, 'HOUR', $from, $to);
                    if ($buckets !== null) {
                        $this->SendDebug(
                            'Vaillant/emf-buckets-Rohdaten',
                            $role . '/' . $opMode . '/' . $valType . ': ' . json_encode($buckets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            0
                        );
                        $bucketsOk++;
                    }
                }
            }
        }
        if ($ok === 0) {
            $say(WPHUB_Text::t('❌ No request succeeded (%s) -- details in the instance debug output.', $client->getLastError()));
            return;
        }
        $say(WPHUB_Text::t('✅ %d system(s) + %d energy channel test window(s) successful -- raw data is in the instance debug output (entries "Vaillant/emf-currentSystem-Rohdaten" and "Vaillant/emf-buckets-Rohdaten").', $ok, $bucketsOk));
    }

    /**
     * Statische Referenztabelle: welche Vaillant-Steuerfunktionen es laut
     * Quellcode gibt, welcher PATCH/POST/DELETE-Endpunkt dafuer je Regler-Typ
     * zustaendig waere und woher der Wertebereich stammt. Rein informativ --
     * wird NIRGENDS fuer einen echten Schreibaufruf verwendet (den gibt es in
     * WPHub Stand 30.09.2026 nicht). Quellen (30.09.2026 gegengelesen, siehe
     * Markus' Wunsch nach einer vollstaendigen Uebersicht):
     * - github.com/signalkraft/myPyllant (api.py/models.py/enums.py) -- die
     *   von Home Assistant genutzte, aktiv gepflegte Referenzbibliothek.
     * - github.com/TA2k/ioBroker.vaillant (main.js) -- unabhaengige zweite
     *   Implementierung, an ECHTER vrc700-Hardware nachgebessert (Issue #112)
     *   -- bestaetigt insbesondere die Index-Falle unten unabhaengig von
     *   myPyllant.
     * 'range' ohne weiteren Zusatz = aus der Referenz-Implementierung
     * (Home-Assistant-UI-Grenzwert), NICHT von der Vaillant-API selbst
     * geliefert und an KEINER Anlage in diesem Forum bisher bestaetigt.
     * Die einzige Ausnahme ist DHW min_setpoint/max_setpoint -- das liefert
     * laut myPyllant-Modell (DomesticHotWater-Klasse) die API selbst mit,
     * wird unten separat als 'apiRange' behandelt.
     */
    private function vaillantCapabilityReference(): array
    {
        return [
            'zone' => [
                ['caption' => WPHUB_Text::t('Operating mode heating'), 'fields' => ['heating.operation_mode_heating', 'operation_mode_heating'], 'tli' => 'PATCH zones/{i}/heating-operation-mode {operationMode: MANUAL|TIME_CONTROLLED|OFF}', 'vrc700' => 'PATCH zone/{i}/heating/operation-mode {operationMode: DAY|AUTO|SET_BACK|OFF}', 'range' => WPHUB_Text::t('Enum, see endpoint')],
                ['caption' => WPHUB_Text::t('Manual setpoint (room temperature)'), 'fields' => ['heating.manual_mode_setpoint_heating', 'heating.day_temperature_heating'], 'tli' => 'PATCH zones/{i}/manual-mode-setpoint {setpoint, type:HEATING}', 'vrc700' => 'PATCH zone/{i}/heating/comfort-room-temperature {comfortRoomTemperature}', 'range' => WPHUB_Text::t('no known limit in the reference client')],
                ['caption' => WPHUB_Text::t('Set-back temperature'), 'fields' => ['heating.set_back_temperature', 'set_back_temperature'], 'tli' => 'PATCH zones/{i}/set-back-temperature {setBackTemperature}', 'vrc700' => 'PATCH zone/{i}/heating/set-back-temperature {setBackTemperature}', 'range' => WPHUB_Text::t('no known limit in the reference client')],
                ['caption' => WPHUB_Text::t('Quick veto (temporary room temperature override)'), 'fields' => ['current_special_function'], 'tli' => 'POST/PATCH zones/{i}/quick-veto {desiredRoomTemperatureSetpoint, duration}, DELETE ' . WPHUB_Text::t('to cancel'), 'vrc700' => 'POST/PATCH zone/{i}/heating/quick-veto {desiredRoomTemperatureSetpoint, duration}, DELETE ' . WPHUB_Text::t('to cancel'), 'range' => WPHUB_Text::t('Duration up to 12 h according to the reference client, default 3 h; displayed value = active special function of the zone (QUICK_VETO = running, NONE = none)')],
            ],
            'circuit' => [
                ['caption' => WPHUB_Text::t('Heating curve'), 'fields' => ['heating_curve'], 'tli' => 'PATCH circuit/{i}/heating-curve {heatingCurve}', 'vrc700' => 'PATCH circuit/{i}/heating-curve {setPoint}', 'range' => WPHUB_Text::t('0.1–5.0, step 0.05')],
                ['caption' => WPHUB_Text::t('Minimum flow temperature'), 'fields' => ['min_flow_temperature_setpoint'], 'tli' => 'PATCH circuit/{i}/min-flow-temperature-setpoint {minFlowTemperatureSetpoint}', 'vrc700' => 'PATCH circuit/{i}/min-flow-temperature-setpoint {minFlowTemperatureSetpoint}', 'range' => WPHUB_Text::t('0–100 °C, step 0.1')],
                ['caption' => WPHUB_Text::t('Heating limit temperature'), 'fields' => ['heat_demand_limited_by_outside_temperature'], 'tli' => 'POST circuit/{i}/heat-demand-limited-by-outside-temperature {heatDemandLimitedByOutsideTemperature}', 'vrc700' => 'POST .../system-control/v1/systems/{sys}/circuits/{i}/heat-demand-limited-by-outside-temperature {setpoint}', 'range' => WPHUB_Text::t('0–100 °C, step 0.1')],
            ],
            'dhw' => [
                ['caption' => WPHUB_Text::t('Hot water tapping setpoint'), 'fields' => ['tapping_setpoint'], 'tli' => 'PATCH domestic-hot-water/{index}/temperature {setpoint}', 'vrc700' => 'PATCH domestic-hot-water/{index}/tapping-setpoint {setpoint}', 'range' => 'apiRange'],
                ['caption' => WPHUB_Text::t('Operating mode hot water'), 'fields' => ['operation_mode_dhw'], 'tli' => 'PATCH domestic-hot-water/{index}/operation-mode {operationMode: MANUAL|TIME_CONTROLLED|OFF}', 'vrc700' => 'PATCH domestic-hot-water/{index}/operation-mode {operationMode: DAY|AUTO|OFF}', 'range' => WPHUB_Text::t('Enum, see endpoint')],
                ['caption' => WPHUB_Text::t('Hot water boost (one-time special charge)'), 'fields' => ['current_special_function'], 'tli' => 'POST domestic-hot-water/{index}/boost, DELETE ' . WPHUB_Text::t('to cancel'), 'vrc700' => 'POST domestic-hot-water/{index}/boost, DELETE ' . WPHUB_Text::t('to cancel'), 'range' => WPHUB_Text::t('no parameter (on/off only)')],
            ],
        ];
    }

    /**
     * Reiner Lese-Ueberblick ueber die Steuerungsfaehigkeiten EINER Anlage
     * (Markus' Wunsch, Forum-Post 30.09.2026 -- erster Schritt eines
     * vorsichtigen mehrstufigen Ablaufs zu einer spaeteren Vaillant-Steuerung,
     * "erst erkennen, dann schreiben"). Macht KEINEN einzigen POST/PUT/PATCH/
     * DELETE-Aufruf -- nur $system (bereits ueber getSystem()/getSystemVrc700()
     * gelesen) wird ausgewertet und die statische Referenztabelle daneben
     * gelegt. Gibt die Anzahl protokollierter Elemente (Zonen+Heizkreise+
     * Warmwasser) zurueck.
     *
     * WICHTIGER SICHERHEITSHINWEIS fuer eine spaetere Schreibfunktion (NICHT
     * hier umgesetzt, nur dokumentiert): sowohl myPyllant (System.merge_object()
     * fasst state/configuration/properties je Eintrag ueber dessen EIGENES
     * "index"-Feld zusammen, NICHT ueber die Array-Position) als auch TA2k/
     * ioBroker.vaillant (eigener, an echter vrc700-Hardware nachgebesserter
     * Fix, Issue #112) bestaetigen UNABHAENGIG voneinander: Die Array-Position
     * einer Zone/eines Heizkreises in state.zones[]/state.circuits[] ist NICHT
     * zuverlaessig derselbe numerische Index, den ein Schreib-Endpunkt in der
     * URL erwartet -- und der Zusammenhang unterscheidet sich zwischen tli und
     * vrc700. Bei Warmwasser ist der reale Index haeufig der Sonderwert 255
     * (an Markus' echten Rohdaten UND am ioBroker-Adapter unabhaengig
     * bestaetigt), nicht 0. Deshalb wird unten je Element IMMER zusaetzlich
     * das eigene 'index'-Feld ausgegeben -- ein kuenftiger Schreibzugriff MUSS
     * dieses Feld verwenden, niemals die Array-Position.
     */
    private function logVaillantCapabilities(string $systemId, string $controlIdentifier, array $system): int
    {
        $reference = $this->vaillantCapabilityReference();
        $lines = [];
        $count = 0;

        $entityGroups = [
            'zone'    => ['state.zones' => ['state', 'zones'], 'configuration.zones' => ['configuration', 'zones'], 'properties.zones' => ['properties', 'zones']],
            'circuit' => ['state.circuits' => ['state', 'circuits'], 'configuration.circuits' => ['configuration', 'circuits'], 'properties.circuits' => ['properties', 'circuits']],
            'dhw'     => ['state.dhw' => ['state', 'dhw'], 'configuration.dhw' => ['configuration', 'dhw'], 'properties.dhw' => ['properties', 'dhw']],
        ];

        foreach ($entityGroups as $scope => $sections) {
            // Anzahl Elemente aus der laengeren der beiden Sektionen (state/
            // configuration muessen laut myPyllant nicht dieselbe Laenge haben).
            $maxLen = 0;
            foreach ($sections as $path) {
                $node = $this->arrayAtPath($system, $path);
                $maxLen = max($maxLen, is_array($node) ? count($node) : 0);
            }
            for ($i = 0; $i < $maxLen; $i++) {
                $merged = [];
                foreach ($sections as $sectionName => $path) {
                    $entry = $this->listEntryAt($system, $i, ...$path);
                    if ($entry === []) {
                        continue;
                    }
                    foreach ($entry as $key => $value) {
                        // Bewusst NICHT ueberschreiben, wenn schon vorhanden --
                        // 'state' steht in $sections VOR 'configuration', damit
                        // Ist-/Live-Werte Vorrang vor reinen Konfigurationswerten
                        // haben, falls derselbe Rohname in beiden vorkommt.
                        if (!array_key_exists($key, $merged)) {
                            $merged[$key] = $value;
                        }
                    }
                }
                if ($merged === []) {
                    continue;
                }
                $count++;
                $indexField = array_key_exists('index', $merged) ? $merged['index'] : WPHUB_Text::t('(no index field found)');
                $activeText = array_key_exists('is_active', $merged)
                    ? ($merged['is_active'] ? WPHUB_Text::t('yes') : WPHUB_Text::t('NO (not active in the system -- missing values are normal here)'))
                    : WPHUB_Text::t('not specified');
                $lines[] = '--- ' . WPHUB_Text::t('%s #%d (array position, do NOT use for write access) -- real index field: %s -- active: %s', strtoupper($scope), $i, json_encode($indexField), $activeText) . ' ---';
                foreach ($reference[$scope] as $row) {
                    $present = false;
                    $value = null;
                    foreach ($row['fields'] as $fieldPath) {
                        $found = $this->valueAtDotPath($merged, $fieldPath);
                        if ($found !== null) {
                            $present = true;
                            $value = WPHUB_Text::t('%s (raw field %s)', json_encode($found[0]), $fieldPath);
                            break;
                        }
                    }
                    $rangeText = $row['range'] === 'apiRange'
                        ? ((array_key_exists('min_setpoint', $merged) || array_key_exists('max_setpoint', $merged))
                            ? WPHUB_Text::t('supplied by the API itself: %s–%s', (string)($merged['min_setpoint'] ?? '?'), (string)($merged['max_setpoint'] ?? '?'))
                            : WPHUB_Text::t('supplied by the API itself, but min_setpoint/max_setpoint not present in this response'))
                        : WPHUB_Text::t('%s (from reference client, not confirmed by this API response)', $row['range']);
                    $lines[] = '  ' . $row['caption'] . ': ' . ($present ? WPHUB_Text::t('current value %s', $value) : WPHUB_Text::t('NOT present in this response'))
                        . ' | ' . WPHUB_Text::t('Value range: %s', $rangeText)
                        . ' | tli: ' . $row['tli'] . ' | vrc700: ' . $row['vrc700'];
                }
            }
        }

        if ($count > 0) {
            $this->SendDebug(
                'Vaillant/steuerungsfaehigkeiten',
                WPHUB_Text::t('System %s (%s):', $systemId, $controlIdentifier) . "\n" . implode("\n", $lines),
                0
            );
        }
        return $count;
    }

    /**
     * Hilfsfunktion fuer logVaillantCapabilities(): liest einen Wert ueber
     * einen Punkt-Pfad (z. B. "heating.operation_mode_heating"). Liefert
     * [Wert] bei Treffer (auch wenn der Wert selbst null ist), sonst null.
     * Noetig, weil die Zonen-Heizparameter bei tli NICHT flach, sondern
     * verschachtelt in configuration.zones[i].heating stehen (an Markus'
     * echter Rohantwort vom 25.09.2026 bestaetigt, deckt sich mit
     * myPyllants ZoneHeating-Klasse) -- bis 0.15.1 wurden sie deshalb
     * faelschlich als "NICHT vorhanden" gemeldet.
     */
    private function valueAtDotPath(array $data, string $path): ?array
    {
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }
        return [$node];
    }

    /**
     * Hilfsfunktion fuer logVaillantCapabilities(): navigiert zu einem
     * verschachtelten Pfad und liefert das Array dort, oder [] wenn der Pfad
     * fehlt/kein Array ist. Wie listEntryAt(), nur ohne den letzten
     * Index-Zugriff (liefert die ganze Liste, nicht ein Element daraus).
     */
    private function arrayAtPath(array $system, array $path): array
    {
        $node = $system;
        foreach ($path as $key) {
            if (!is_array($node) || !isset($node[$key])) {
                return [];
            }
            $node = $node[$key];
        }
        return is_array($node) ? $node : [];
    }

    /**
     * Manueller Knopf "🔎 Steuerungsfähigkeiten anzeigen" -- siehe
     * logVaillantCapabilities() fuer die Sicherheitshinweise. Nutzt
     * ausschliesslich dieselben lesenden Methoden, die der normale Zyklus
     * ohnehin aufruft (getSystem()/getSystemVrc700(), ueber vaillantSystemsCache()
     * zwischengespeichert) -- kein zusaetzlicher API-Aufruf, der das Kontingent
     * ueber den normalen Takt hinaus belasten wuerde.
     */
    public function VaillantCapabilities(): void
    {
        $say = function (string $m) {
            $this->UpdateFormField('VAI_CapabilitiesResult', 'caption', $m);
            $this->UpdateFormField('VAI_CapabilitiesResult', 'visible', true);
        };

        $bundle = $this->vaillantEnsureToken();
        if ($bundle === null) {
            $say(WPHUB_Text::t('❌ Not logged in (or login currently blocked because of the quota) -- log in above first.'));
            return;
        }
        $client = $this->vaillantClient();
        $homes = $this->vaillantSystemsCache($bundle, $client);
        if ($homes === null || count($homes) === 0) {
            $say(WPHUB_Text::t('❌ No system known -- log in above first and search for systems.'));
            return;
        }

        $entityCount = 0;
        $systemCount = 0;
        foreach ($homes as $home) {
            $systemId = (string)($home['systemId'] ?? '');
            $controlIdentifier = (string)($home['controlIdentifier'] ?? '');
            if ($systemId === '') {
                continue;
            }
            if ($controlIdentifier === 'tli') {
                $system = $client->getSystem($bundle, $systemId);
            } elseif ($controlIdentifier === 'vrc700') {
                $system = $client->getSystemVrc700($bundle, $systemId);
            } else {
                continue;
            }
            if ($system === null) {
                continue;
            }
            $systemCount++;
            $entityCount += $this->logVaillantCapabilities($systemId, $controlIdentifier, $system);
        }

        if ($entityCount === 0) {
            $say(WPHUB_Text::t('❌ No evaluable zones/heating circuits/hot water found -- details in the instance debug output.'));
            return;
        }
        $say(WPHUB_Text::t('✅ %d element(s) from %d system(s) logged -- see the instance debug output, entry "Vaillant/steuerungsfaehigkeiten". NOTHING was written, only read.', $entityCount, $systemCount));
    }

    /**
     * Vaillant-Update -- mit Sperrfrist nach einem API-Kontingent-Fehler
     * (Fund 25.09.2026, m_rothenpieler: "Out of call volume quota", HTTP 403
     * nach mehreren 60s-Zyklen). OHNE diese Sperrfrist wuerde jeder weitere
     * Zyklus sofort erneut anfragen und die Sperre nur verlaengern -- siehe
     * VaillantClient::parseQuotaRetrySeconds().
     */
    private function updateVaillant(): void
    {
        $retryNotBefore = $this->ReadAttributeInteger('VAI_RetryNotBefore');
        if ($retryNotBefore > time()) {
            return; // Kontingent-Sperrfrist laeuft noch, letzter Status bleibt stehen
        }
        $bundle = $this->vaillantEnsureToken();
        if ($bundle === null) {
            return; // Status 201 gesetzt, Meldung im Protokoll
        }
        $client = $this->vaillantClient();
        if ($this->refreshDevicesVaillant($bundle, $client) === null) {
            $this->markAllUnreachable();
            if ($client->quotaRetryAfterSeconds !== null) {
                $this->WriteAttributeInteger('VAI_RetryNotBefore', time() + $client->quotaRetryAfterSeconds);
                $this->LogMessage(WPHUB_Text::t('Vaillant API quota used up -- next attempt in %d s (%s).', $client->quotaRetryAfterSeconds, $client->getLastError()), KL_WARNING);
            } else {
                $this->LogMessage(WPHUB_Text::t('Update failed: %s', $client->getLastError()), KL_WARNING);
            }
            return;
        }
        $this->refreshDiscoverySummary();
        $this->updateVaillantEnergy($bundle, $client);

        $needsAttention = $this->managedByNeedsAttention();
        if (count($needsAttention) > 0) {
            if ($this->GetStatus() !== 203) {
                $this->LogMessage(WPHUB_Text::t('Control authority not assigned yet although an active HeishaMon instance was found: %s — set it in the WPHub form under "🔀 Control authority".', implode(', ', $needsAttention)), KL_WARNING);
            }
            $this->SetStatus(203);
            return;
        }
        $this->SetStatus(102);
    }

    /**
     * Energiedaten (EMF/buckets) fuer alle bekannten Anlagen -- eigener,
     * viel selterer Takt als der Haupt-Update()-Zyklus (siehe
     * VAI_ENERGY_REFRESH_INTERVAL_SECONDS). Wird von updateVaillant() NACH
     * dem erfolgreichen Haupt-Refresh aufgerufen, beeinflusst aber niemals
     * dessen Status/Rueckgabewert -- ein Fehlschlag hier (auch Kontingent)
     * lässt die bereits aktualisierten Temperaturen/Status unberuehrt, nur
     * die Energie-Variablen bleiben beim letzten bekannten Stand. Ein
     * Kontingent-Fehler setzt eine EIGENE Sperrfrist (VAI_EnergyRetryNotBefore),
     * nicht die des Haupt-Refresh: am 02.10.2026 bekamen die EMF-Buckets HTTP 403
     * (Sperre 2 Tage 17 h), waehrend TLI zeitgleich HTTP 200 lieferte -- die
     * Kontingente sind getrennt, eine gemeinsame Sperre wuerde die normalen
     * Werte tagelang blockieren.
     */
    private function updateVaillantEnergy(array $bundle, WPHUB_VaillantClient $client): void
    {
        if ($this->ReadAttributeInteger('VAI_EnergyRetryNotBefore') > time()) {
            return; // Energie-Sperrfrist laeuft noch, Werte bleiben beim letzten Stand
        }
        $refreshedAt = $this->ReadAttributeInteger('VAI_EnergyRefreshedAt');
        if ($refreshedAt > 0 && (time() - $refreshedAt) < self::VAI_ENERGY_REFRESH_INTERVAL_SECONDS) {
            return;
        }
        $client->quotaRetryAfterSeconds = null; // klebt am Client-Objekt, hier nur Energie-Fehler zaehlen
        $devices = $this->readDeviceList();
        $anySuccess = false;
        foreach ($devices as $d) {
            $systemId = (string)($d['guid'] ?? '');
            $prefix = (string)($d['prefix'] ?? '');
            $name = (string)($d['name'] ?? WPHUB_Text::t('Heat pump'));
            if ($systemId === '' || $prefix === '') {
                continue;
            }
            $emf = $client->getCurrentSystemEmf($bundle, $systemId);
            $quotaSeconds = null;
            if ($emf === null) {
                $quotaSeconds = $client->quotaRetryAfterSeconds;
            } else {
                $quotaSeconds = $this->maintainDeviceEnergyVaillant($prefix, $name, $client, $bundle, $systemId, $emf);
            }
            if ($quotaSeconds !== null) {
                // Beim ersten Kontingent-Fehler den ganzen Energie-Durchlauf
                // abbrechen (Fund 02.10.2026, Markus: nach dem ersten 403 wurden
                // trotzdem alle 13 Buckets weiter abgefragt) -- nur die Energie
                // pausiert, der Haupt-Takt laeuft weiter.
                $this->WriteAttributeInteger('VAI_EnergyRetryNotBefore', time() + $quotaSeconds);
                $this->LogMessage(WPHUB_Text::t('Vaillant API quota used up during the energy request -- energy values pause for %d s, the normal values continue (%s).', $quotaSeconds, $client->getLastError()), KL_WARNING);
                return;
            }
            if ($emf === null) {
                continue;
            }
            $anySuccess = true;
        }
        if ($anySuccess) {
            $this->WriteAttributeInteger('VAI_EnergyRefreshedAt', time());
        }
    }

    /**
     * Fasst die heutigen Energiewerte (Ortszeit-Mitternacht bis jetzt, DAY-
     * Aufloesung) ueber ALLE Geraete einer Anlage zusammen (primary_heat_
     * generator + secondary_heat_generators[] + electric_backup_heater) --
     * auf Markus' eigenen Wunsch (Forum-Post 29.09.2026: "eine Aufteilung auf
     * die beiden einzelnen Wärmepumpen wäre für mich gar nicht zwingend
     * nötig"), keine Geraete-getrennten Variablen. Response-Aufbau (start_date/
     * end_date/resolution/operation_mode/energy_type/total_consumption/data[])
     * an Markus' echter Buckets-Antwort bestaetigt (29.09.2026) -- total_
     * consumption ist bereits die Summe ueber das angefragte Zeitfenster, in
     * Wh. Physik-Gegenprobe an seinen echten Werten bestanden: CONSUMED_
     * ELECTRICAL_ENERGY + EARNED_ENVIRONMENT_ENERGY = HEAT_GENERATED (auf
     * jedem Geraet, jeder Betriebsart).
     *
     * Fund 29.09.2026 (Markus, Forum-Post, direkt nach dem 0.14.0-Release):
     * das Zeitfenster lief zuerst auf UTC-Mitternacht -- bei ihm haette der
     * Tageswechsel damit um 01:00/02:00 Uhr Ortszeit statt Mitternacht
     * stattgefunden (CET/CEST-Versatz). Jetzt echte Ortszeit-Mitternacht
     * (vaillantLocalTimezone(), aus VAI_Country abgeleitet), fuer die Anfrage
     * selbst nach UTC zurueckgewandelt (getDeviceDataBuckets() formatiert
     * IMMER mit literalem "Z"-Suffix, erwartet also UTC-Instanzen).
     *
     * Fund 30.09.2026 (Markus + Dashboard-Sitzung, echter Rohdump "dump (7).txt"):
     * auch nach dem Ortszeit-Fix wich "Heizen heute" weiter ab (diesmal zu
     * VIEL: 2,10 statt 1,0 kWh). Ursache am echten Rohdump bestaetigt, KEIN
     * Rateweg mehr: bei resolution=DAY ist total_consumption schlicht die
     * Summe ALLER data[]-Tages-Buckets, die das Anfragefenster ueberhaupt
     * BERUEHRT -- nicht auf das Fenster geclippt. Da unser Fenster an
     * Ortszeit-Mitternacht beginnt (in Deutschland z. B. 22:00 UTC des
     * VORTAGS), beruehrt es IMMER auch den kompletten vorherigen UTC-Tag,
     * dessen GESAMTER Tageswert (nicht nur der Anteil ab 22:00 Uhr) dann mit
     * einflieszt. Beispiel aus dem echten Dump: data=[{28.09. 00-24 Uhr UTC,
     * 2000 Wh}, {29.09. 00 Uhr UTC bis jetzt, 0 Wh}], total_consumption=2000
     * -- der GANZE 28.09. floss ein, obwohl von ihm nur die letzten 2 Stunden
     * zu "heute" (Ortszeit) gehoeren.
     * Fix: resolution auf HOUR (statt DAY) -- alle bisher unterstuetzten
     * Laender (VAILLANT_COUNTRIES, siehe vaillantLocalTimezone()) haben einen
     * GANZZAHLIGEN Stunden-Versatz zu UTC (CET/CEST +1/+2, GMT/BST +0/+1),
     * Ortszeit-Mitternacht faellt deshalb IMMER exakt auf eine UTC-Stunden-
     * Grenze -- anders als bei DAY-Aufloesung gibt es also keinen Bucket, der
     * das Fenster nur TEILWEISE beruehrt. total_consumption wird trotzdem
     * NICHT mehr vertraut (haette bei DAY genau das Problem verursacht) --
     * stattdessen werden die einzelnen data[]-Eintraege selbst aufsummiert,
     * zusaetzlich defensiv auf start_date >= $from gefiltert (Sicherheitsnetz,
     * falls ein kuenftiges Land/eine Sonderregel doch mal einen Bucket VOR
     * dem Fenster mitliefert -- exakt das Muster, das bei DAY beobachtet
     * wurde).
     *
     * EnergieHeizenHeute/EnergieWarmwasserHeute/EnergieGesamtHeute sind
     * dieselben Idents wie bei Panasonic (dailyEnergyHeatingID/-DHWID/
     * -TotalID in GetFunctions(), bereits herstellerneutral) -- keine
     * GetFunctions()-Aenderung noetig. WaermeXHeute/UmweltenergieXHeute sind
     * NEU und (noch) nicht Teil des Verbund-Vertrags (kein SUITE.md-Feld
     * dafuer) -- reine Zusatzwerte wie Systemdruck/Raumtemperatur.
     */
    private function maintainDeviceEnergyVaillant(string $prefix, string $name, WPHUB_VaillantClient $client, array $bundle, string $systemId, array $emf): ?int
    {
        [$from, $to] = $this->vaillantTodayWindowUtc();

        $sums = [];
        foreach ($this->emfDeviceRoles($emf) as $role => $device) {
            $deviceUuid = (string)($device['device_uuid'] ?? '');
            $channels = is_array($device['data'] ?? null) ? $device['data'] : [];
            if ($deviceUuid === '') {
                continue;
            }
            foreach ($channels as $channel) {
                $opMode = (string)($channel['operation_mode'] ?? '');
                $valType = (string)($channel['value_type'] ?? '');
                if ($opMode === '' || $valType === '') {
                    continue;
                }
                $bucket = $client->getDeviceDataBuckets($bundle, $systemId, $deviceUuid, $opMode, $valType, 'HOUR', $from, $to);
                if ($bucket === null) {
                    if ($client->quotaRetryAfterSeconds !== null) {
                        // Kontingent leer: sofort abbrechen, KEINE Teilsummen
                        // schreiben (wuerden zu niedrige Werte zeigen) -- der
                        // Aufrufer setzt die Energie-Sperrfrist.
                        return $client->quotaRetryAfterSeconds;
                    }
                    continue;
                }
                // Fund 30.09.2026 (m_rothenpieler): "Heizen heute" wich von der
                // myVAILLANT-App ab -- Rohdaten gehen jetzt auch aus dem ECHTEN
                // Takt per SendDebug raus (bisher nur beim manuellen
                // TestEmfEndpoint()), damit sich sowas kuenftig ohne Extra-Klick
                // nachvollziehen laesst.
                $this->SendDebug(
                    'Vaillant/emf-energie-buckets-Rohdaten',
                    $role . '/' . $opMode . '/' . $valType . ': ' . json_encode($bucket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    0
                );
                // total_consumption wird bewusst NICHT verwendet (siehe
                // Klassenkommentar oben, Fund 30.09.2026: bei DAY-Aufloesung war
                // das die Summe UNGECLIPPTER kompletter Tages-Buckets) --
                // stattdessen die einzelnen data[]-Stundenwerte selbst
                // aufsummieren, auf $from geclippt.
                $entries = is_array($bucket['data'] ?? null) ? $bucket['data'] : [];
                $channelSum = 0.0;
                foreach ($entries as $entry) {
                    $entryStart = $this->parseVaillantIsoInstant($entry['start_date'] ?? null);
                    if ($entryStart === null || $entryStart < $from || !is_numeric($entry['value'] ?? null)) {
                        continue;
                    }
                    $channelSum += (float)$entry['value'];
                }
                $key = $opMode . '/' . $valType;
                $sums[$key] = ($sums[$key] ?? 0.0) + $channelSum;
            }
        }

        // Feste Positionsbloecke (200+), unabhaengig vom laufenden $pos-Zaehler
        // in maintainDeviceVariablesVaillant() -- diese Funktion wird aus einem
        // eigenen, viel selteneren Takt aufgerufen und kennt dessen aktuellen
        // Zaehlerstand nicht. Platziert die Energie-Variablen bewusst HINTER
        // allen Temperatur-/Statuswerten im WebFront.
        $pos = 200;
        $this->maintainDailyEnergyVariable($prefix, $name, 'EnergieHeizenHeute', WPHUB_Text::t('Energy consumption heating (today)'), $sums['HEATING/CONSUMED_ELECTRICAL_ENERGY'] ?? null, $pos++);
        $this->maintainDailyEnergyVariable($prefix, $name, 'EnergieWarmwasserHeute', WPHUB_Text::t('Energy consumption hot water (today)'), $sums['DOMESTIC_HOT_WATER/CONSUMED_ELECTRICAL_ENERGY'] ?? null, $pos++);
        if (isset($sums['HEATING/CONSUMED_ELECTRICAL_ENERGY']) || isset($sums['DOMESTIC_HOT_WATER/CONSUMED_ELECTRICAL_ENERGY'])) {
            $gesamt = ($sums['HEATING/CONSUMED_ELECTRICAL_ENERGY'] ?? 0.0) + ($sums['DOMESTIC_HOT_WATER/CONSUMED_ELECTRICAL_ENERGY'] ?? 0.0);
            $this->maintainDailyEnergyVariable($prefix, $name, 'EnergieGesamtHeute', WPHUB_Text::t('Energy consumption total (today)'), $gesamt, $pos++);
        } else {
            $pos++;
        }
        $this->maintainDailyEnergyVariable($prefix, $name, 'WaermeHeizenHeute', WPHUB_Text::t('Heat generated heating (today)'), $sums['HEATING/HEAT_GENERATED'] ?? null, $pos++);
        $this->maintainDailyEnergyVariable($prefix, $name, 'WaermeWarmwasserHeute', WPHUB_Text::t('Heat generated hot water (today)'), $sums['DOMESTIC_HOT_WATER/HEAT_GENERATED'] ?? null, $pos++);
        $this->maintainDailyEnergyVariable($prefix, $name, 'UmweltenergieHeizenHeute', WPHUB_Text::t('Environmental energy heating (today)'), $sums['HEATING/EARNED_ENVIRONMENT_ENERGY'] ?? null, $pos++);
        $this->maintainDailyEnergyVariable($prefix, $name, 'UmweltenergieWarmwasserHeute', WPHUB_Text::t('Environmental energy hot water (today)'), $sums['DOMESTIC_HOT_WATER/EARNED_ENVIRONMENT_ENERGY'] ?? null, $pos++);
        return null;
    }

    /**
     * Legt eine Tages-Energievariable an/aktualisiert sie -- nur, wenn der
     * Wert tatsaechlich verfuegbar ist (sonst bleibt die Variable ganz weg,
     * gleiche Linie wie alle anderen optionalen Vaillant-Felder). Wert kommt
     * in Wh aus der API, Variable wird in kWh gefuehrt (NRG.kWh-Profil,
     * Verbund-Konvention).
     */
    private function maintainDailyEnergyVariable(string $prefix, string $name, string $ident, string $caption, ?float $valueWh, int $pos): void
    {
        if ($valueWh === null) {
            return;
        }
        $this->MaintainVariable($prefix . $ident, $name . ': ' . $caption, VARIABLETYPE_FLOAT, 'NRG.kWh', $pos, true);
        $this->SetValue($prefix . $ident, $valueWh / 1000.0);
    }

    /**
     * Anlagenliste + Regler-Typ, aus VAI_SystemsCache wenn noch frisch genug
     * (VAI_SYSTEMS_CACHE_TTL_SECONDS), sonst per getHomes()/
     * getControlIdentifier() neu ermittelt und in den Cache geschrieben.
     *
     * Fund 26.09.2026 (m_rothenpieler, Forum-Post #38): refreshDevicesVaillant()
     * machte bislang DREI API-Calls je Zyklus (getHomes() einmal, dazu
     * getControlIdentifier() UND getSystem()/getSystemVrc700() je Anlage) --
     * bei einem Ein-Anlagen-Konto also 3 Calls, nicht 1. Anlagenliste und
     * Regler-Typ aendern sich praktisch nie, im Gegensatz zu den eigentlichen
     * Messwerten (getSystem()/getSystemVrc700(), bleibt bewusst jeden Zyklus
     * bestehen). Ein Fehler HIER (Kontingent oder sonstiger Fehlschlag von
     * getHomes()) wird wie zuvor mit NULL nach oben gereicht -- dieselbe,
     * bereits zweimal gebuggte Sperrfrist-Logik (updateVaillant()) deckt
     * also weiterhin BEIDE Pfade ab.
     *
     * Bewusst NICHT versucht: eine entfernte Anlage vorzeitig aus dem Cache
     * zu erkennen (z. B. per HTTP-Statuscode von getSystem()) -- dafuer gibt
     * es noch keinen beobachteten Fall, das waere ein Rateweg. Eine entfernte
     * Anlage faellt spaetestens nach Ablauf der TTL automatisch weg, sofort
     * nach einer erneuten Anmeldung (LoginVaillant() erzwingt einen frischen
     * Cache, siehe dort).
     */
    private function vaillantSystemsCache(array $bundle, WPHUB_VaillantClient $client): ?array
    {
        $cacheAt = $this->ReadAttributeInteger('VAI_SystemsCacheAt');
        $cached = json_decode((string)$this->ReadAttributeString('VAI_SystemsCache'), true);
        if (is_array($cached) && $cacheAt > 0 && (time() - $cacheAt) < self::VAI_SYSTEMS_CACHE_TTL_SECONDS) {
            return $cached;
        }

        $homes = $client->getHomes($bundle);
        if ($homes === null) {
            return null;
        }
        $systems = [];
        foreach ($homes as $home) {
            $systems[] = [
                'systemId'          => $home['systemId'],
                'homeName'          => $home['homeName'],
                'controlIdentifier' => $client->getControlIdentifier($bundle, $home['systemId']),
            ];
        }
        $this->WriteAttributeString('VAI_SystemsCache', json_encode($systems));
        $this->WriteAttributeInteger('VAI_SystemsCacheAt', time());
        return $systems;
    }

    /**
     * Anlagenliste laden (siehe vaillantSystemsCache()) und je Anlage die
     * Basiswerte pflegen (siehe maintainDeviceVariablesVaillant()). Regler-Typ
     * "tli" UND "vrc700" werden abgerufen (siehe VaillantClient::getSystem()/
     * getSystemVrc700()) -- "scf"/iQconnect-Anlagen bleiben uebersprungen
     * (laut myPyllant-Quelltext strukturell ohne aggregiertes System, siehe
     * VaillantClient.php-Kommentar). vrc700 ist Stand 25.09.2026 NUR im
     * Verbindungsaufbau verifiziert (cbeham, Forum-Post #22/WPHub-Thread) --
     * welche Felder maintainDeviceVariablesVaillant() daraus tatsaechlich
     * lesen kann, ist noch offen, deshalb geht das komplette Roh-System
     * zusaetzlich per SendDebug raus.
     */
    private function refreshDevicesVaillant(array $bundle, WPHUB_VaillantClient $client): ?array
    {
        $homes = $this->vaillantSystemsCache($bundle, $client);
        if ($homes === null) {
            return null;
        }

        $devices = [];
        foreach ($homes as $home) {
            $systemId = $home['systemId'];
            $controlIdentifier = $home['controlIdentifier'];
            if ($controlIdentifier === 'tli') {
                $system = $client->getSystem($bundle, $systemId);
                // Fund 25.09.2026 (m_rothenpieler, Forum-Post #26): Vorlauf-
                // und Puffertemperatur kamen bei seiner Kaskade identisch an,
                // Warmwasser/Betriebszustaende/Energie fehlten komplett --
                // ob das an seiner Anlagentopologie liegt oder an einer
                // falschen Feldzuordnung, laesst sich nur mit dem echten
                // Rohsystem klaeren (myPyllants eigenes Modell kennt DHW/
                // Kreise/Geraete als eigene Listen, nicht nur flache
                // state.system.*-Felder -- WPHub liest bislang nur Letzteres).
                if ($system !== null) {
                    $this->SendDebug('Vaillant/tli-Rohdaten', json_encode($system, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
                }
            } elseif ($controlIdentifier === 'vrc700') {
                $system = $client->getSystemVrc700($bundle, $systemId);
                if ($system !== null) {
                    $this->SendDebug('Vaillant/vrc700-Rohdaten', json_encode($system, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
                }
            } else {
                $this->SendDebug('Vaillant/Geräte', WPHUB_Text::t('Skipped (controller type "%s" not supported yet): %s', $controlIdentifier, $systemId), 0);
                continue;
            }
            if ($system === null) {
                continue;
            }
            $name = trim((string)$home['homeName']) !== '' ? $home['homeName'] : WPHUB_Text::t('Heat pump %s', substr($systemId, 0, 8));
            $prefix = $this->devicePrefix($systemId);
            $reachable = (bool)($system['connected'] ?? true);

            $this->maintainDeviceVariablesVaillant($prefix, $name, $system, $reachable);

            $devices[] = [
                'guid'          => $systemId,
                'name'          => $name,
                'prefix'        => $prefix,
                'reachable'     => $reachable,
                'operationMode' => null, // Vaillant-Steuerung noch nicht implementiert (siehe Klassenkopf)
                'lastSeenAt'    => time(),
            ];
        }

        $this->writeDeviceList($devices);
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        return $devices;
    }

    /**
     * Variablen einer Vaillant-Anlage pflegen -- bewusst schmaler Umfang
     * (nur Werte, die direkt unter state.system.* bestaetigt sind, siehe
     * signalkraft/myPyllant models.py System.outdoor_temperature/
     * water_pressure/... ). Gleiche Ident-Namen wie maintainDeviceVariables()
     * (Panasonic), damit GetFunctions()/contractFieldID() unveraendert
     * funktioniert -- der Vertrag ist bereits herstellerneutral.
     */
    /**
     * Element einer myVAILLANT-Liste an einer festen Array-POSITION (dhw/
     * circuits/zones -- jeweils ein Array mit eigenem 'index'-Feld, das NICHT
     * zwingend der Array-Position entspricht, siehe Markus' Rohdaten
     * 25.09.2026: dhw[0].index ist z. B. 255, nicht 0). Die Array-Position
     * entspricht bei circuits/zones aber dem Heizkreis/Zone (0 = Kreis 1,
     * 1 = Kreis 2 usw., an cbehams echter Zwei-Kreis-Anlage bestaetigt,
     * Forum-Post #36, 26.09.2026).
     */
    private function listEntryAt(array $system, int $index, string ...$path): array
    {
        $node = $system;
        foreach ($path as $key) {
            if (!is_array($node) || !isset($node[$key])) {
                return [];
            }
            $node = $node[$key];
        }
        return (is_array($node) && is_array($node[$index] ?? null)) ? $node[$index] : [];
    }

    /**
     * Erstes Element einer myVAILLANT-Liste -- Kurzform von listEntryAt() mit
     * Index 0 (dhw/Warmwasser hat bislang nur EINEN Eintrag pro Konto,
     * mehrere DHW-Stationen sind noch nicht verifiziert).
     */
    private function firstListEntry(array $system, string ...$path): array
    {
        return $this->listEntryAt($system, 0, ...$path);
    }

    /**
     * Erster gueltiger Temperaturwert aus mehreren Kandidaten-Rohwerten (in
     * Reihenfolge). Vaillants tli- und vrc700-Endpunkte liefern fuer denselben
     * Messpunkt teils VERSCHIEDENE Feldnamen (nicht nur unterschiedliche
     * Werte) -- siehe maintainDeviceVariablesVaillant()-Kommentar.
     */
    private function firstValidTemperature(array $candidates): ?float
    {
        foreach ($candidates as $c) {
            if ($c !== null && $this->isValidTemperature($c)) {
                return (float)$c;
            }
        }
        return null;
    }

    /**
     * Vaillant-Basiswerte. Feldherkunft am 25.09.2026 an ZWEI echten Rohsystemen
     * verifiziert (siehe WPHub-CLAUDE.md) -- NICHT mehr nur nach der
     * Referenzbibliothek geraten wie beim ersten Bau:
     * - Markus' (m_rothenpieler) tli-Anlage (VRC720-Kaskade, Forum-Post #30)
     * - cbehams vrc700-Anlage (VRC700, Forum-Post #32)
     * tli und vrc700 sind KEINE Feldnamen-Varianten voneinander, sondern zwei
     * eigenstaendige Datenmodelle mit teils unterschiedlichen Rohfeldern fuer
     * denselben Messpunkt (Puffer-/Warmwassertemperatur) -- deshalb wird bei
     * diesen beiden Feldern jeweils eine Liste bekannter Pfade durchprobiert.
     */
    private function maintainDeviceVariablesVaillant(string $prefix, string $name, array $system, bool $reachable): void
    {
        $pos = 0;
        $this->MaintainVariable($prefix . 'Erreichbar', $name . ': ' . WPHUB_Text::t('Reachable'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue($prefix . 'Erreichbar', $reachable);

        $state = (is_array($system['state'] ?? null) && is_array($system['state']['system'] ?? null)) ? $system['state']['system'] : [];

        if (isset($state['outdoor_temperature']) && $this->isValidTemperature($state['outdoor_temperature'])) {
            $this->MaintainVariable($prefix . 'Aussentemperatur', $name . ': ' . WPHUB_Text::t('Outdoor temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Aussentemperatur', (float)$state['outdoor_temperature']);
        }
        if (isset($state['system_flow_temperature']) && $this->isValidTemperature($state['system_flow_temperature'])) {
            $this->MaintainVariable($prefix . 'Vorlauftemperatur', $name . ': ' . WPHUB_Text::t('Flow temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Vorlauftemperatur', (float)$state['system_flow_temperature']);
        }
        // Puffer oben -- FIX 25.09.2026 (cbeham, Forum-Post #32): vrc700
        // nennt dasselbe Feld anders als tli (cylinder_temperature_sensor_
        // top_central_heating statt _top_c_h), bislang komplett uebersehen,
        // Puffertemperatur blieb bei jeder vrc700-Anlage unangelegt.
        $puffer = $this->firstValidTemperature([
            $state['cylinder_temperature_sensor_top_c_h'] ?? null,                 // tli (Markus)
            $state['cylinder_temperature_sensor_top_central_heating'] ?? null,     // vrc700 (cbeham)
        ]);
        if ($puffer !== null) {
            // Fund 25.09.2026 (Markus): auf seiner Anlage liefert die API
            // system_flow_temperature UND diesen Wert identisch -- keine
            // Verwechslung im Code, das kommt schon so von Vaillant (Puffer
            // ist bei ihm offenbar die hydraulische Weiche fuer den System-
            // vorlauf). Bewusst NICHT zusammengelegt, andere Anlagen koennen
            // hier durchaus unterschiedliche Werte liefern.
            $this->MaintainVariable($prefix . 'Puffertemperatur', $name . ': ' . WPHUB_Text::t('Buffer temperature (top)'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Puffertemperatur', $puffer);
        }

        // Warmwasser Ist -- FIX 25.09.2026: bei tli in einer eigenen Liste
        // (state.dhw[].current_dhw_temperature), bei vrc700 dagegen als
        // flaches Systemfeld (state.system.cylinder_temperature_sensor_top_
        // dhw, Fund cbeham Forum-Post #32 -- state.dhw[] enthaelt bei ihm nur
        // current_special_function/index, keine Temperatur). Beide Pfade
        // durchprobiert, keiner geraten -- beide an echten Rohdaten bestaetigt.
        $dhwState = $this->firstListEntry($system, 'state', 'dhw');
        $warmwasser = $this->firstValidTemperature([
            $dhwState['current_dhw_temperature'] ?? null,           // tli (Markus)
            $state['cylinder_temperature_sensor_top_dhw'] ?? null,  // vrc700 (cbeham)
        ]);
        if ($warmwasser !== null) {
            $this->MaintainVariable($prefix . 'Warmwasser', $name . ': ' . WPHUB_Text::t('Hot water'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Warmwasser', $warmwasser);
        }
        $dhwConfig = $this->firstListEntry($system, 'configuration', 'dhw');
        if (isset($dhwConfig['tapping_setpoint']) && $this->isValidTemperature($dhwConfig['tapping_setpoint'])) {
            $this->MaintainVariable($prefix . 'WarmwasserSoll', $name . ': ' . WPHUB_Text::t('Hot water setpoint'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'WarmwasserSoll', (float)$dhwConfig['tapping_setpoint']);
        }

        // Heizkreis-1-Vorlauf Ist/Soll (NEU 25.09.2026, aus state.circuits[0]) --
        // Ident bewusst "Zone1Ist"/"Zone1Soll" wie bei Panasonic, damit
        // GetFunctions() sie automatisch ueber z1WaterTempID/
        // z1WaterTargetTempID auflöst (gleicher Vertrag, gleiche Idents,
        // siehe GetFunctions()-Kommentar "dieselben Feldnamen wie bei
        // HeishaMon").
        $circuitState = $this->firstListEntry($system, 'state', 'circuits');
        if (isset($circuitState['current_circuit_flow_temperature']) && $this->isValidTemperature($circuitState['current_circuit_flow_temperature'])) {
            $this->MaintainVariable($prefix . 'Zone1Ist', $name . ': ' . WPHUB_Text::t('Heating circuit 1 flow temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Zone1Ist', (float)$circuitState['current_circuit_flow_temperature']);
        }
        if (isset($circuitState['heating_circuit_flow_setpoint']) && $this->isValidTemperature($circuitState['heating_circuit_flow_setpoint'])) {
            $this->MaintainVariable($prefix . 'Zone1Soll', $name . ': ' . WPHUB_Text::t('Heating circuit 1 flow setpoint'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Zone1Soll', (float)$circuitState['heating_circuit_flow_setpoint']);
        }

        // Heizkreis 2 (NEU 26.09.2026, Forum-Post #36, cbeham) -- seine
        // vrc700-Anlage hat ZWEI eigenstaendige, gleichzeitig unterschiedlich
        // aktive Kreise (state.circuits[1] = "GASTWOHNUNG", separat von
        // circuits[0] = "EG UND OG"), bisher wurde nur circuits[0] gelesen,
        // Kreis 2 war komplett unsichtbar. Ident "Zone2Ist"/"Zone2Soll" wie
        // bei SamsungEhs, damit GetFunctions() sie automatisch ueber
        // z2WaterTempID/z2WaterTargetTempID auflöst (keine Aenderung an
        // GetFunctions() noetig).
        // Vorsicht bei der Interpretation: heating_circuit_flow_setpoint
        // stand bei cbehams NICHT aktivem Kreis auf 0 (nicht leer/fehlend) --
        // das ist vermutlich ein "kein Sollwert, weil Kreis in Bereitschaft"-
        // Platzhalter, keine echte 0-°C-Vorgabe. Bewusst NICHT herausgefiltert
        // (waere Interpretation ohne weitere Bestaetigung), der Rohwert geht
        // unveraendert durch -- siehe Heizkreis2Status fuer den zugehoerigen
        // Bereitschaftsstatus.
        $circuitState2 = $this->listEntryAt($system, 1, 'state', 'circuits');
        if (isset($circuitState2['current_circuit_flow_temperature']) && $this->isValidTemperature($circuitState2['current_circuit_flow_temperature'])) {
            $this->MaintainVariable($prefix . 'Zone2Ist', $name . ': ' . WPHUB_Text::t('Heating circuit 2 flow temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Zone2Ist', (float)$circuitState2['current_circuit_flow_temperature']);
        }
        if (isset($circuitState2['heating_circuit_flow_setpoint']) && $this->isValidTemperature($circuitState2['heating_circuit_flow_setpoint'])) {
            $this->MaintainVariable($prefix . 'Zone2Soll', $name . ': ' . WPHUB_Text::t('Heating circuit 2 flow setpoint'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Zone2Soll', (float)$circuitState2['heating_circuit_flow_setpoint']);
        }
        if (isset($circuitState2['circuit_state']) && is_string($circuitState2['circuit_state']) && $circuitState2['circuit_state'] !== '') {
            $this->MaintainVariable($prefix . 'Heizkreis2Status', $name . ': ' . WPHUB_Text::t('Heating circuit 2 status (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'Heizkreis2Status', $circuitState2['circuit_state']);
        }
        if (isset($circuitState2['calculated_energy_manager_state']) && is_string($circuitState2['calculated_energy_manager_state']) && $circuitState2['calculated_energy_manager_state'] !== '') {
            $this->MaintainVariable($prefix . 'Heizkreis2Betriebszustand', $name . ': ' . WPHUB_Text::t('Heating circuit 2 operating state (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'Heizkreis2Betriebszustand', $circuitState2['calculated_energy_manager_state']);
        }

        // Rohstatus-Werte (NEU 26.09.2026, Forum-Post #34, Markus) -- die drei
        // Rohwerte von state.system.energy_manager_state (STANDBY/HEATING/DHW)
        // wurden inzwischen an einem echten, ueber mehrere Zyklen beobachteten
        // Warmwasser-Ladevorgang bestaetigt (Forum-Post #38, 26.09.2026) --
        // deshalb zusaetzlich zum Rohwert jetzt auch BetriebsartNorm (Verbund-
        // Enum, contractVersion 1.4, siehe normalizeVaillantEnergyManagerState()).
        // circuit_state/calculated_energy_manager_state/current_special_function
        // bleiben bewusst UNNORMALISIERT -- current_special_function hat sich
        // laut Markus' Beobachtung sogar als NICHT aussagekraeftig fuer aktive
        // Warmwasserbereitung erwiesen (blieb durchgehend REGULAR), die beiden
        // anderen sind reine Diagnosewerte ohne eigenes Vertragsfeld.
        if (isset($state['energy_manager_state']) && is_string($state['energy_manager_state']) && $state['energy_manager_state'] !== '') {
            $this->MaintainVariable($prefix . 'Systemstatus', $name . ': ' . WPHUB_Text::t('System status (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'Systemstatus', $state['energy_manager_state']);
            $this->MaintainVariable($prefix . 'BetriebsartNorm', $name . ': ' . WPHUB_Text::t('Operating mode (normalized)'), VARIABLETYPE_INTEGER, 'WPHUB.BetriebsartNorm', $pos++, true);
            $this->SetValue($prefix . 'BetriebsartNorm', $this->normalizeVaillantEnergyManagerState($state['energy_manager_state']));
        }
        if (isset($circuitState['circuit_state']) && is_string($circuitState['circuit_state']) && $circuitState['circuit_state'] !== '') {
            $this->MaintainVariable($prefix . 'Heizkreis1Status', $name . ': ' . WPHUB_Text::t('Heating circuit 1 status (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'Heizkreis1Status', $circuitState['circuit_state']);
        }
        if (isset($circuitState['calculated_energy_manager_state']) && is_string($circuitState['calculated_energy_manager_state']) && $circuitState['calculated_energy_manager_state'] !== '') {
            $this->MaintainVariable($prefix . 'Heizkreis1Betriebszustand', $name . ': ' . WPHUB_Text::t('Heating circuit 1 operating state (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'Heizkreis1Betriebszustand', $circuitState['calculated_energy_manager_state']);
        }
        if (isset($dhwState['current_special_function']) && is_string($dhwState['current_special_function']) && $dhwState['current_special_function'] !== '') {
            $this->MaintainVariable($prefix . 'WarmwasserSonderfunktion', $name . ': ' . WPHUB_Text::t('Hot water special function (raw value)'), VARIABLETYPE_STRING, '', $pos++, true);
            $this->SetValue($prefix . 'WarmwasserSonderfunktion', $dhwState['current_special_function']);
        }

        // Reiner Zusatzwert, nicht Teil des Verbund-Vertrags (kein
        // gemeinsames *PressureID-Feld) -- Comfort Cloud liefert das gar
        // nicht erst, daher hier bewusst kein Ident-Gleichlauf noetig.
        if (isset($state['system_water_pressure']) && is_numeric($state['system_water_pressure'])) {
            $this->MaintainVariable($prefix . 'Systemdruck', $name . ': ' . WPHUB_Text::t('System pressure'), VARIABLETYPE_FLOAT, '', $pos++, true);
            $this->SetValue($prefix . 'Systemdruck', (float)$state['system_water_pressure']);
        }

        // Raumtemperatur/-feuchte (NEU 25.09.2026, state.zones[0]) -- wie
        // Systemdruck ein reiner Zusatzwert ohne eigenes Vertragsfeld
        // (der Verbund kennt keine Raumklima-*ID, nur Wasser-/Aussentemp.).
        $zoneState = $this->firstListEntry($system, 'state', 'zones');
        if (isset($zoneState['current_room_temperature']) && $this->isValidTemperature($zoneState['current_room_temperature'])) {
            $this->MaintainVariable($prefix . 'Raumtemperatur', $name . ': ' . WPHUB_Text::t('Room temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($prefix . 'Raumtemperatur', (float)$zoneState['current_room_temperature']);
        }
        if (isset($zoneState['current_room_humidity']) && is_numeric($zoneState['current_room_humidity'])) {
            $this->MaintainVariable($prefix . 'Raumfeuchte', $name . ': ' . WPHUB_Text::t('Room humidity'), VARIABLETYPE_FLOAT, 'NRG.Percent', $pos++, true);
            $this->SetValue($prefix . 'Raumfeuchte', (float)$zoneState['current_room_humidity']);
        }
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
                    $this->SendDebug('Geräte', WPHUB_Text::t('Skipped (not A2W): %s', (string)($entry['deviceName'] ?? $guid)), 0);
                    continue;
                }

                $name = (string)($entry['deviceName'] ?? WPHUB_Text::t('Heat pump %s', substr($guid, 0, 8)));
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
                $consumption = $client->getDeviceConsumptionToday($bundle, $guid);

                $this->maintainDeviceVariables($prefix, $name, $entry, $reachable, $status, $consumption);

                $devices[] = [
                    'guid'          => $guid,
                    'name'          => $name,
                    'prefix'        => $prefix,
                    'reachable'     => $reachable,
                    // Fuer RequestAction: bei einer Zonen-Solltemperatur muss
                    // je nach aktueller Betriebsart heatSet oder coolSet
                    // gesetzt werden (siehe setZoneTemperature()).
                    'operationMode' => isset($entry['operationMode']) ? (int)$entry['operationMode'] : null,
                    // Dashboard-Vertragsfeld lastSeenAt (contractVersion 1.13):
                    // dieser Zweig laeuft nur bei erfolgreicher Cloud-Antwort,
                    // also ist genau hier der richtige Stempelzeitpunkt.
                    'lastSeenAt'    => time(),
                ];
            }
        }

        $this->writeDeviceList($devices);
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        return $devices;
    }

    /**
     * Variablen eines Geraets anlegen/pflegen. $dev ist der Geraeteeintrag aus
     * der device/group-Antwort. Vorhandene Messwerte werden auch bei
     * connectionStatus 0 als letzter bekannter Stand geschrieben; die
     * Erreichbarkeit spiegelt connectionStatus wider.
     */
    private function maintainDeviceVariables(string $prefix, string $name, array $dev, bool $reachable, ?array $status = null, ?array $consumption = null): void
    {
        // Dietmars Vorrang-Entscheidung (13.09.2026, mit EMS auf "je Geraet"
        // praezisiert): steht die Steuerhoheit DIESES Geraets nicht auf
        // 'wphub' (managedBy), werden die Steuerelemente bewusst deaktiviert
        // (DisableAction) statt nur beworben und im Hintergrund abgelehnt,
        // siehe applyControl().
        $controlBlocked = ($this->deviceManagedBy($prefix) !== self::MANAGED_BY_DEFAULT);

        $pos = 0;
        $this->MaintainVariable($prefix . 'Erreichbar', $name . ': ' . WPHUB_Text::t('Reachable'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue($prefix . 'Erreichbar', $reachable);

        // Ist-Temperaturen/Zonennamen aus dem Transfer-Statusabruf, nach
        // zoneId zugeordnet (dort steckt auch der echte Zonenname, z.B.
        // "HK1") -- wird unten von zwei Bloecken genutzt (erst die
        // Prioritaets-Sollwerte, danach der Rest).
        $statusZones = [];
        foreach ((is_array($status) ? ($status['zoneStatus'] ?? []) : []) as $sz) {
            if (is_array($sz) && isset($sz['zoneId'])) {
                $statusZones[(int)$sz['zoneId']] = $sz;
            }
        }
        $tank = $dev['tankStatus'] ?? null;

        // ------------------------------------------------------------
        // Prioritaets-Steuerelemente ZUERST im Objektbaum (aufgezogene
        // Kachelansicht sortiert Instanz-Variablen nach dieser Positions-
        // Zahl). Reihenfolge nach der HeishaMon-Referenzlogik
        // (Examples/Rules/Jeisha-DHW-Radiators-Rowbuffer im offiziellen
        // HeishaMon-GitHub-Repo, dort real-world-erprobt energiesparend fuer
        // genau diese Waermepumpenart): Fluester-/Leistungsbetrieb + ein
        // dynamisches Warmwasser-/Zonenziel + Urlaubslogik sind die
        // wirksamsten Stellschrauben, danach die Notbetriebe, danach der
        // Rest wie bisher. WPHub hat mangels Leistungsmessung keine eigene
        // COP-Berechnung -- hier geht es nur um die Sichtbarkeit/Reihenfolge
        // der vorhandenen Steuerelemente.
        // ------------------------------------------------------------
        if (is_array($status)) {
            if (isset($status['quietMode'])) {
                $this->MaintainVariable($prefix . 'Fluesterbetrieb', $name . ': ' . WPHUB_Text::t('Quiet mode'), VARIABLETYPE_INTEGER, 'WPHUB.Fluesterbetrieb', $pos++, true);
                $controlBlocked ? $this->DisableAction($prefix . 'Fluesterbetrieb') : $this->EnableAction($prefix . 'Fluesterbetrieb');
                $this->SetValue($prefix . 'Fluesterbetrieb', (int)$status['quietMode']);
            }
            if (isset($status['powerful'])) {
                $this->MaintainVariable($prefix . 'Leistungsbetrieb', $name . ': ' . WPHUB_Text::t('Powerful mode'), VARIABLETYPE_INTEGER, 'WPHUB.Leistungsbetrieb', $pos++, true);
                $controlBlocked ? $this->DisableAction($prefix . 'Leistungsbetrieb') : $this->EnableAction($prefix . 'Leistungsbetrieb');
                $this->SetValue($prefix . 'Leistungsbetrieb', (int)$status['powerful']);
            }
        }

        if (is_array($tank) && isset($tank['temperature']) && $this->isValidTemperature($tank['temperature'])) {
            $this->MaintainVariable($prefix . 'WarmwasserSoll', $name . ': ' . WPHUB_Text::t('Hot water setpoint'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $controlBlocked ? $this->DisableAction($prefix . 'WarmwasserSoll') : $this->EnableAction($prefix . 'WarmwasserSoll');
            $this->SetValue($prefix . 'WarmwasserSoll', (float)$tank['temperature']);
        }

        // Zonen-Sollwerte (Ist-Temperatur/Aktiv-Status folgen weiter unten
        // zusammen mit dem uebrigen Zonenblock).
        foreach (($dev['zoneStatus'] ?? []) as $zone) {
            if (!is_array($zone) || !isset($zone['zoneId']) || !isset($zone['temperature']) || !$this->isValidTemperature($zone['temperature'])) {
                continue;
            }
            $zid = (int)$zone['zoneId'];
            $sz = $statusZones[$zid] ?? null;
            $zname = (is_array($sz) && ($sz['zoneName'] ?? '') !== '') ? (string)$sz['zoneName'] : WPHUB_Text::t('Zone %d', $zid);
            $this->MaintainVariable($prefix . 'Zone' . $zid . 'Soll', $name . ': ' . WPHUB_Text::t('%s setpoint temperature', $zname), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $controlBlocked ? $this->DisableAction($prefix . 'Zone' . $zid . 'Soll') : $this->EnableAction($prefix . 'Zone' . $zid . 'Soll');
            $this->SetValue($prefix . 'Zone' . $zid . 'Soll', (float)$zone['temperature']);
        }

        if (is_array($status) && isset($status['holidayTimer'])) {
            $this->MaintainVariable($prefix . 'Urlaubstimer', $name . ': ' . WPHUB_Text::t('Holiday timer active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
            $controlBlocked ? $this->DisableAction($prefix . 'Urlaubstimer') : $this->EnableAction($prefix . 'Urlaubstimer');
            $this->SetValue($prefix . 'Urlaubstimer', (int)$status['holidayTimer'] === 1);
        }
        if (is_array($status) && isset($status['forceDHW'])) {
            $this->MaintainVariable($prefix . 'NotbetriebWarmwasser', $name . ': ' . WPHUB_Text::t('Emergency hot water mode active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
            $controlBlocked ? $this->DisableAction($prefix . 'NotbetriebWarmwasser') : $this->EnableAction($prefix . 'NotbetriebWarmwasser');
            $this->SetValue($prefix . 'NotbetriebWarmwasser', (int)$status['forceDHW'] === 1);
        }
        if (is_array($status) && isset($status['forceHeater'])) {
            $this->MaintainVariable($prefix . 'NotHeizbetrieb', $name . ': ' . WPHUB_Text::t('Emergency heating mode active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
            $controlBlocked ? $this->DisableAction($prefix . 'NotHeizbetrieb') : $this->EnableAction($prefix . 'NotHeizbetrieb');
            $this->SetValue($prefix . 'NotHeizbetrieb', (int)$status['forceHeater'] === 1);
        }
        // ------------------------------------------------------------
        // Ende Prioritaetsblock -- ab hier alles Uebrige wie bisher.
        // ------------------------------------------------------------

        if (isset($dev['operationMode'])) {
            $this->MaintainVariable($prefix . 'Betriebsart', $name . ': ' . WPHUB_Text::t('Operating mode'), VARIABLETYPE_INTEGER, 'WPHUB.Betriebsart', $pos++, true);
            $this->SetValue($prefix . 'Betriebsart', (int)$dev['operationMode']);
        }

        if (is_array($status) && isset($status['outdoorNow']) && $this->isValidTemperature($status['outdoorNow'])) {
            $this->MaintainVariable($prefix . 'Aussentemperatur', $name . ': ' . WPHUB_Text::t('Outdoor temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            // Automatische Archivierung: eigene Variable (im Gegensatz zu den
            // extern verknuepften Ext_*-Feldern, die WPHub nicht gehoeren --
            // deren Archivierung bleibt Sache des jeweils besitzenden Moduls),
            // gebraucht fuer die Verlaufsansichten (Dashboard-Anfrage 17.08.2026).
            $this->SetValue($prefix . 'Aussentemperatur', (float)$status['outdoorNow']);
        }

        // Warmwasserspeicher: temperatureNow = Ist (Sollwert oben bereits
        // im Prioritaetsblock behandelt).
        if (is_array($tank)) {
            if (isset($tank['temperatureNow']) && $this->isValidTemperature($tank['temperatureNow'])) {
                $this->MaintainVariable($prefix . 'Warmwasser', $name . ': ' . WPHUB_Text::t('Hot water'), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'Warmwasser', (float)$tank['temperatureNow']);
            }
            if (isset($tank['operationStatus'])) {
                $this->MaintainVariable($prefix . 'WarmwasserBetrieb', $name . ': ' . WPHUB_Text::t('Hot water active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'WarmwasserBetrieb', (int)$tank['operationStatus'] === 1);
            }
        }

        // Verbund-weit normierte Betriebsart (EMS/SUITE.md, mit HeishaMon
        // abgestimmt 13.08.2026): jedes heatpump-Modul bildet seinen eigenen
        // Hersteller-Enum auf diesen gemeinsamen Enum ab, Konsumenten (Dashboard/
        // EMS) muessen keine Herstellersemantik mehr kennen. "Warmwasser aktiv"
        // fliesst mit ein (kombinierte Zustaende 3-5), unser operationMode allein
        // kennt nur die Heiz-/Kuehlrichtung.
        if (isset($dev['operationMode'])) {
            $dhwActive = is_array($tank) && isset($tank['operationStatus']) && (int)$tank['operationStatus'] === 1;
            $this->MaintainVariable($prefix . 'BetriebsartNorm', $name . ': ' . WPHUB_Text::t('Operating mode (normalized)'), VARIABLETYPE_INTEGER, 'WPHUB.BetriebsartNorm', $pos++, true);
            $this->SetValue($prefix . 'BetriebsartNorm', $this->normalizeOperatingMode((int)$dev['operationMode'], $dhwActive));
        }

        // Zonen: Ist-Temperatur/Aktiv-Status (Sollwert oben bereits im
        // Prioritaetsblock behandelt).
        foreach (($dev['zoneStatus'] ?? []) as $zone) {
            if (!is_array($zone) || !isset($zone['zoneId'])) {
                continue;
            }
            $zid = (int)$zone['zoneId'];
            $sz = $statusZones[$zid] ?? null;
            $zname = (is_array($sz) && ($sz['zoneName'] ?? '') !== '') ? (string)$sz['zoneName'] : WPHUB_Text::t('Zone %d', $zid);
            if (is_array($sz) && isset($sz['temperatureNow']) && $this->isValidTemperature($sz['temperatureNow'])) {
                $this->MaintainVariable($prefix . 'Zone' . $zid . 'Ist', $name . ': ' . WPHUB_Text::t('%s actual temperature', $zname), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                $this->SetValue($prefix . 'Zone' . $zid . 'Ist', (float)$sz['temperatureNow']);
            }
            if (isset($zone['operationStatus'])) {
                $this->MaintainVariable($prefix . 'Zone' . $zid . 'Betrieb', $name . ': ' . WPHUB_Text::t('%s active', $zname), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'Zone' . $zid . 'Betrieb', (int)$zone['operationStatus'] === 1);
            }
        }

        // Weitere Betriebsdaten aus dem Transfer-Statusabruf (Quiet-/Power-/
        // Urlaubs-/Notbetriebe stehen bereits oben im Prioritaetsblock).
        if (is_array($status)) {
            if (isset($status['deiceStatus'])) {
                $this->MaintainVariable($prefix . 'Abtaubetrieb', $name . ': ' . WPHUB_Text::t('Defrost mode active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
                $this->SetValue($prefix . 'Abtaubetrieb', (int)$status['deiceStatus'] === 1);
            }
            if (isset($status['direction'])) {
                $this->MaintainVariable($prefix . 'Betriebsrichtung', $name . ': ' . WPHUB_Text::t('Operating direction'), VARIABLETYPE_INTEGER, 'WPHUB.Betriebsrichtung', $pos++, true);
                $this->SetValue($prefix . 'Betriebsrichtung', (int)$status['direction']);
            }
            if (isset($status['specialStatus'])) {
                $this->MaintainVariable($prefix . 'EcoKomfort', $name . ': ' . WPHUB_Text::t('Eco/comfort mode'), VARIABLETYPE_INTEGER, 'WPHUB.EcoKomfort', $pos++, true);
                $this->SetValue($prefix . 'EcoKomfort', (int)$status['specialStatus']);
            }
            // Fehlerstatus: leere Liste ist der Normalfall, dann 0/"".
            $faults = $status['faultStatus'] ?? [];
            if (is_array($faults)) {
                $this->MaintainVariable($prefix . 'Fehleranzahl', $name . ': ' . WPHUB_Text::t('Error count'), VARIABLETYPE_INTEGER, '', $pos++, true);
                $this->SetValue($prefix . 'Fehleranzahl', count($faults));
                $texts = [];
                foreach ($faults as $f) {
                    if (is_array($f) && ($f['errorMessage'] ?? '') !== '') {
                        $texts[] = (string)$f['errorMessage'];
                    }
                }
                $this->MaintainVariable($prefix . 'Fehlertext', $name . ': ' . WPHUB_Text::t('Error text'), VARIABLETYPE_STRING, '', $pos++, true);
                $this->SetValue($prefix . 'Fehlertext', implode('; ', $texts));
            }
        }

        // Energieverbrauch des laufenden Tages -- rein informativ, NICHT Teil
        // des EMS-Vertrags (PowerID/EnergyID bleiben 0: Tageswerte springen um
        // Mitternacht auf 0, sind also kein kumulativer Zaehler).
        if (is_array($consumption)) {
            if (isset($consumption['heat'])) {
                $this->MaintainVariable($prefix . 'EnergieHeizenHeute', $name . ': ' . WPHUB_Text::t('Energy consumption heating (today)'), VARIABLETYPE_FLOAT, 'NRG.kWh', $pos++, true);
                $this->SetValue($prefix . 'EnergieHeizenHeute', (float)$consumption['heat']);
            }
            if (isset($consumption['cool'])) {
                $this->MaintainVariable($prefix . 'EnergieKuehlenHeute', $name . ': ' . WPHUB_Text::t('Energy consumption cooling (today)'), VARIABLETYPE_FLOAT, 'NRG.kWh', $pos++, true);
                $this->SetValue($prefix . 'EnergieKuehlenHeute', (float)$consumption['cool']);
            }
            if (isset($consumption['tank'])) {
                $this->MaintainVariable($prefix . 'EnergieWarmwasserHeute', $name . ': ' . WPHUB_Text::t('Energy consumption hot water (today)'), VARIABLETYPE_FLOAT, 'NRG.kWh', $pos++, true);
                $this->SetValue($prefix . 'EnergieWarmwasserHeute', (float)$consumption['tank']);
            }
            if (isset($consumption['total'])) {
                $this->MaintainVariable($prefix . 'EnergieGesamtHeute', $name . ': ' . WPHUB_Text::t('Energy consumption total (today)'), VARIABLETYPE_FLOAT, 'NRG.kWh', $pos++, true);
                $this->SetValue($prefix . 'EnergieGesamtHeute', (float)$consumption['total']);
            }
        }
    }

    /** Bei Cloud-Ausfall: alle bekannten Geraete als unerreichbar markieren. */
    private function markAllUnreachable(): void
    {
        $devices = $this->readDeviceList();
        if (count($devices) === 0) {
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
        $this->writeDeviceList($devices);
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
        if (!IPS_VariableProfileExists('NRG.kWh')) {
            IPS_CreateVariableProfile('NRG.kWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.kWh', '', ' kWh');
            IPS_SetVariableProfileDigits('NRG.kWh', 2);
        }
        if (!IPS_VariableProfileExists('NRG.Percent')) {
            IPS_CreateVariableProfile('NRG.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Percent', '', ' %');
            IPS_SetVariableProfileDigits('NRG.Percent', 0);
            IPS_SetVariableProfileValues('NRG.Percent', 0, 100, 1);
        }
        // Modulspezifisch (kein NRG.*-Praefix): Werte aus dem A2W-Transfer-
        // Statusabruf, die kein anderes NRG-Stack-Modul teilt.
        if (!IPS_VariableProfileExists('WPHUB.Betriebsart')) {
            IPS_CreateVariableProfile('WPHUB.Betriebsart', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 0, WPHUB_Text::t('Off'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 1, WPHUB_Text::t('Heating'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 2, WPHUB_Text::t('Cooling'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 3, WPHUB_Text::t('Auto heating'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsart', 4, WPHUB_Text::t('Auto cooling'), '', -1);
        }
        // Verbund-weiter normierter Betriebsart-Enum (EMS/SUITE.md, mit
        // HeishaMon abgestimmt) -- Werte/Bedeutung modulübergreifend fest.
        if (!IPS_VariableProfileExists('WPHUB.BetriebsartNorm')) {
            IPS_CreateVariableProfile('WPHUB.BetriebsartNorm', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', -1, WPHUB_Text::t('Unknown'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 0, WPHUB_Text::t('Standby'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 1, WPHUB_Text::t('Heating'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 2, WPHUB_Text::t('Cooling'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 3, WPHUB_Text::t('Hot water'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 4, WPHUB_Text::t('Heating + hot water'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.BetriebsartNorm', 5, WPHUB_Text::t('Cooling + hot water'), '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.Fluesterbetrieb')) {
            IPS_CreateVariableProfile('WPHUB.Fluesterbetrieb', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 0, WPHUB_Text::t('Off'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 1, WPHUB_Text::t('Level 1'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 2, WPHUB_Text::t('Level 2'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Fluesterbetrieb', 3, WPHUB_Text::t('Level 3'), '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.Leistungsbetrieb')) {
            IPS_CreateVariableProfile('WPHUB.Leistungsbetrieb', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 0, WPHUB_Text::t('Off'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 1, WPHUB_Text::t('30 minutes'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 2, WPHUB_Text::t('60 minutes'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Leistungsbetrieb', 3, WPHUB_Text::t('90 minutes'), '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.Betriebsrichtung')) {
            IPS_CreateVariableProfile('WPHUB.Betriebsrichtung', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsrichtung', 0, WPHUB_Text::t('Idle'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsrichtung', 1, WPHUB_Text::t('Circulation pump'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.Betriebsrichtung', 2, WPHUB_Text::t('Hot water'), '', -1);
        }
        if (!IPS_VariableProfileExists('WPHUB.EcoKomfort')) {
            IPS_CreateVariableProfile('WPHUB.EcoKomfort', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WPHUB.EcoKomfort', 0, WPHUB_Text::t('Off'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.EcoKomfort', 1, WPHUB_Text::t('Eco'), '', -1);
            IPS_SetVariableProfileAssociation('WPHUB.EcoKomfort', 2, WPHUB_Text::t('Comfort'), '', -1);
        }
    }
}
