<?php

// WPHUB_ComfortCloudClient — HTTP-Client fuer die (inoffizielle) Panasonic
// Comfort Cloud API. Der Login laeuft seit 2023 ueber Auth0
// (authglb.digital.panasonic.com) mit OAuth2 Authorization Code + PKCE; die
// eigentliche Geraete-API liegt auf accsmart.panasonic.com und verlangt je
// Request einen aus Zeitstempel+Token errechneten Schluessel (x-cfc-api-key).
// Referenz fuer den Ablauf: sockless-coding/aio-panasonic-comfort-cloud
// (Python, Stand 08/2026). Aquarea-Waermepumpen (A2W) werden ueber den
// Transfer-Proxy /remote/v1/app/common/transfer angesprochen (Scope
// a2w.control ist im Authorize-Request enthalten).
//
// Globaler Klassenname bewusst mit WPHUB_-Praefix: mehrere NRG-Stack-Module
// koennen im selben PHP-Prozess geladen sein (Verbund-Konvention 25.07.2026).
//
// Kein Passwort und kein Token wird geloggt — Debug-Ausgaben enthalten nur
// Statuscodes, URLs und gekuerzte Antwortkoerper.

class WPHUB_ComfortCloudClient
{
    const AUTH_BASE    = 'https://authglb.digital.panasonic.com';
    const ACC_BASE     = 'https://accsmart.panasonic.com';
    const CLIENT_ID    = 'Xmy6xIYIitMxngjB2rHvlm6HSDNnaMJx';
    const AUTH0_CLIENT = 'eyJuYW1lIjoiQXV0aDAuQW5kcm9pZCIsImVudiI6eyJhbmRyb2lkIjoiMzAifSwidmVyc2lvbiI6IjIuOS4zIn0=';
    const REDIRECT_URI = 'panasonic-iot-cfc://authglb.digital.panasonic.com/android/com.panasonic.ACCsmart/callback';
    const SCOPE        = 'openid offline_access comfortcloud.control a2w.control';
    const UA_API       = 'okhttp/4.10.0';
    const UA_BROWSER   = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Mobile Safari/537.36';
    const API_KEY_SALT = '521325fb2dd486bf4831b47644317fca';

    private $appVersion;
    private $lastError = '';
    private $cookieFile = null;
    private $debug = null; // callable(string $topic, string $text)
    private $versionRejected = false;   // letzte API-Antwort war 4106 (App-Version zu alt)
    private $agreementRequired = false; // letzte API-Antwort war 4103 (Bedingungen aktualisiert)
    private $apiTrace = [];             // Diagnose: letzte API-Aufrufe (Pfad/Status/Antwort, ohne Geheimnisse)

    public function __construct(string $appVersion = '1.21.0', ?callable $debug = null)
    {
        $this->appVersion = $appVersion;
        $this->debug = $debug;
    }

    public function __destruct()
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Diagnose-Mitschnitt der letzten Geraete-API-Aufrufe (Methode, Pfad,
     * Statuscode, gekuerzter Antwortkoerper). Enthaelt KEINE Zugangsdaten --
     * Token stehen nur in Headern, die hier nicht auftauchen. Fuer die
     * Fehlersuche ins Systemprotokoll.
     */
    public function getApiTrace(): string
    {
        return implode("\n", $this->apiTrace);
    }

    /** Hat der letzte API-Aufruf die App-Version abgelehnt (Code 4106)? */
    public function versionRejected(): bool
    {
        return $this->versionRejected;
    }

    /** Verlangt die Cloud eine erneute Zustimmung zu den Bedingungen (Code 4103)? */
    public function agreementRequired(): bool
    {
        return $this->agreementRequired;
    }

    // Zustimmungstypen (aus der offiziellen App): 1 = Nutzungsbedingungen,
    // 2 = Datenschutzerklaerung, 3 = Servicevertrag (nur Tuerkei).
    const AGREEMENT_TYPES = [1, 2, 3];

    /**
     * Aktuelle Bedingungs-Dokumente inkl. Versionsnummern abrufen (rein
     * lesend). Genau der Aufruf der offiziellen App:
     *   GET /auth/v2/agreement/documents?language=0&includeContent=0[&type=N]
     * Antwort: { "agreementList": [ { "type":"1", "version":"…", … }, … ] }
     * (type kommt als String). Rueckgabe: Liste [['type'=>int,'version'=>str]],
     * null bei Fehler. Ohne $typeId werden alle Typen geliefert.
     */
    public function getAgreementDocuments(array $bundle, ?int $typeId = null): ?array
    {
        $this->lastError = '';
        $query = 'language=0&includeContent=0' . ($typeId !== null ? '&type=' . $typeId : '');
        $r = $this->apiRequest($bundle, 'GET', '/auth/v2/agreement/documents?' . $query);
        $json = ($r !== null) ? json_decode($r['body'], true) : null;
        if ($r === null || $r['status'] !== 200 || !is_array($json) || !isset($json['agreementList'])) {
            $this->failApi('Bedingungs-Dokumente (v2/agreement/documents)', $r);
            return null;
        }
        // Rohantwort ins Debug -- macht bei einem erneuten 4002 die genaue
        // Feld-/Versionsstruktur sichtbar (enthaelt keine Geheimnisse).
        $this->dbg('agreement', 'documents(type=' . ($typeId ?? 'alle') . '): ' . substr($r['body'], 0, 600));
        $out = [];
        foreach ($json['agreementList'] as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !isset($entry['version'])) {
                continue;
            }
            $version = (string)$entry['version'];
            if ($version === '') {
                continue; // ohne Versionsnummer nicht bestaetigbar
            }
            $out[] = ['type' => (int)$entry['type'], 'version' => $version];
        }
        return $out;
    }

    /**
     * Die zu bestaetigenden Dokument-Versionen einsammeln -- exakt wie die
     * offizielle App: je Typ (1/2/3) ein eigener documents-Abruf, daraus je
     * Typ die passende Version. Ein einzelner Typ darf fehlschlagen (z. B.
     * Typ 3 = Servicevertrag nur Tuerkei) und wird dann uebersprungen; nur
     * wenn KEIN Typ abrufbar ist, gilt das als Fehler (null). Der ungefilterte
     * Abruf (alle Typen/Sprachen auf einmal) liefert dagegen Fremdvarianten
     * und fuehrte zu 4002 -- deshalb bewusst je Typ.
     */
    public function collectAgreementVersions(array $bundle): ?array
    {
        $out = [];
        $anyOk = false;
        foreach (self::AGREEMENT_TYPES as $typeId) {
            $docs = $this->getAgreementDocuments($bundle, $typeId);
            if ($docs === null) {
                continue;
            }
            $anyOk = true;
            foreach ($docs as $d) {
                if ((int)$d['type'] === $typeId) {
                    $out[$typeId] = ['type' => $typeId, 'version' => $d['version']];
                    break;
                }
            }
        }
        if (!$anyOk) {
            $this->lastError = 'Keiner der Bedingungs-Typen war abrufbar: ' . $this->lastError;
            return null;
        }
        return array_values($out);
    }

    /**
     * Bedingungen im Namen des Kontos bestaetigen -- der PUT der offiziellen
     * App, exakt nach ihrem Schema (aus dem App-Code der aktuellen Version
     * abgeleitet, nicht geraten):
     *   PUT /auth/v2/agreement/status
     *   { "agreementList": [ { "type": <int>, "version": "<str>" }, … ] }
     * Jeder Eintrag ist ein bestaetigtes Dokument mit genau der Version, die
     * die documents-Antwort geliefert hat. Nur auf ausdrueckliche Nutzer-
     * aktion aufrufen -- das Modul stimmt nie stillschweigend selbst zu.
     */
    public function putAgreementStatus(array $bundle, array $items): bool
    {
        $this->lastError = '';
        $list = [];
        foreach ($items as $it) {
            if (!isset($it['type']) || !isset($it['version'])) {
                continue;
            }
            $list[] = ['type' => (int)$it['type'], 'version' => (string)$it['version']];
        }
        if (count($list) === 0) {
            $this->lastError = 'Zustimmung: keine gueltigen Dokument-Versionen zum Bestaetigen vorhanden.';
            return false;
        }
        // Wie die offizielle App: unmittelbar vor dem PUT einmal den
        // Zustimmungsstatus lesen (GET), auf derselben Sitzung. Ergebnis wird
        // nicht ausgewertet -- der Aufruf scheint serverseitig den PUT zu
        // "scharf" zu machen (die App tut es reproduzierbar so).
        $rg = $this->apiRequest($bundle, 'GET', '/auth/v2/agreement/status');
        if ($rg !== null) {
            $this->dbg('agreement', 'status(vor PUT): ' . substr((string)$rg['body'], 0, 200));
        }

        $payload = ['agreementList' => $list];
        $this->dbg('agreement', '[PUT] ' . json_encode($payload));
        $r = $this->apiRequest($bundle, 'PUT', '/auth/v2/agreement/status', $payload);
        if ($r === null || $r['status'] !== 200) {
            $this->failApi('Zustimmung (v2/agreement/status, ' . count($list) . ' Dokument(e))', $r);
            return false;
        }
        $this->dbg('agreement', 'Zustimmung erfolgreich fuer Typen ' . implode(',', array_column($list, 'type')) . ', Antwort: ' . substr((string)$r['body'], 0, 200));
        return true;
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    /**
     * Aktuelle Versionsnummer der offiziellen Comfort-Cloud-App ermitteln
     * (Play Store, Rueckfall AppBrain — gleiche Quellen wie die Referenz-
     * bibliothek). Bei Erfolg wird sie sofort fuer weitere Aufrufe dieses
     * Clients uebernommen und zurueckgegeben, sonst null.
     */
    public function refreshAppVersion(): ?string
    {
        $r = $this->request('GET', 'https://play.google.com/store/apps/details?id=com.panasonic.ACCsmart', [
            'user-agent: ' . self::UA_BROWSER,
        ]);
        if ($r !== null && $r['status'] === 200 && preg_match('/\["(\d+\.\d+\.\d+)"\]/', $r['body'], $m)) {
            $this->appVersion = $m[1];
            $this->dbg('appversion', 'Play Store: ' . $m[1]);
            return $m[1];
        }
        $r = $this->request('GET', 'https://www.appbrain.com/app/panasonic-comfort-cloud/com.panasonic.ACCsmart', [
            'user-agent: ' . self::UA_BROWSER,
        ]);
        if ($r !== null && $r['status'] === 200 && preg_match('/itemprop="softwareVersion"\s+content="(\d+\.\d+(?:\.\d+)?)"/', $r['body'], $m)) {
            $this->appVersion = $m[1];
            $this->dbg('appversion', 'AppBrain: ' . $m[1]);
            return $m[1];
        }
        $this->lastError = 'Aktuelle App-Version konnte nicht ermittelt werden (Play Store/AppBrain nicht erreichbar oder Seitenaufbau geaendert)';
        $this->dbg('appversion', $this->lastError);
        return null;
    }

    // Cookie-Behaelter verwerfen, damit ein neuer Login ohne Altlasten
    // (alte Auth0-Session) startet — Pendant zum clear_domain() der Referenz.
    private function resetCookies(): void
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
        $this->cookieFile = null;
    }

    // ------------------------------------------------------------------
    // Login-Handshake (Auth0, PKCE). Liefert bei Erfolg ein Token-Buendel:
    // ['accessToken','refreshToken','expiresAt','scope','clientId'].
    // clientId wird separat via accLogin() geholt.
    // ------------------------------------------------------------------
    public function login(string $email, string $password): ?array
    {
        $this->lastError = '';
        $this->resetCookies();

        // PKCE: Verifier + S256-Challenge
        $verifier = $this->randomString(43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = $this->randomString(20);

        // Schritt 1: /authorize — erwartet 302 mit Location.
        $r = $this->request('GET', self::AUTH_BASE . '/authorize?' . http_build_query([
            'scope'                 => self::SCOPE,
            'audience'              => 'https://digital.panasonic.com/' . self::CLIENT_ID . '/api/v1/',
            'protocol'              => 'oauth2',
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'auth0Client'           => self::AUTH0_CLIENT,
            'client_id'             => self::CLIENT_ID,
            'redirect_uri'          => self::REDIRECT_URI,
            'state'                 => $state,
        ]), ['user-agent: ' . self::UA_API]);
        if ($r === null || $r['status'] !== 302 || !isset($r['headers']['location'])) {
            return $this->fail('Autorisierung (Schritt 1/5) fehlgeschlagen' . $this->statusSuffix($r));
        }
        $location = $r['headers']['location'];

        if (strpos($location, self::REDIRECT_URI) === 0) {
            // Bereits eingeloggt (Session-Cookie) — Code direkt aus der Location.
            $code = $this->queryParam($location, 'code');
        } else {
            // Schritt 2: Login-Seite laden (setzt _csrf-Cookie), State kann
            // vom Server umgeschrieben worden sein — aus der Location nehmen.
            $state = $this->queryParam($location, 'state') ?? $state;
            $r = $this->request('GET', $this->absoluteAuthUrl($location), ['user-agent: ' . self::UA_API]);
            if ($r === null || $r['status'] !== 200) {
                return $this->fail('Login-Seite (Schritt 2/5) fehlgeschlagen' . $this->statusSuffix($r));
            }
            $csrf = $this->cookieValue($r, '_csrf');
            if ($csrf === null) {
                return $this->fail('Login-Seite (Schritt 2/5): kein _csrf-Cookie erhalten');
            }

            // Schritt 3: Benutzername/Passwort einreichen — 200 mit HTML,
            // darin versteckte Formularfelder fuer den Callback.
            $r = $this->request('POST', self::AUTH_BASE . '/usernamepassword/login', [
                'Auth0-Client: ' . self::AUTH0_CLIENT,
                'user-agent: ' . self::UA_API,
                'content-type: application/json',
            ], json_encode([
                'client_id'     => self::CLIENT_ID,
                'redirect_uri'  => self::REDIRECT_URI,
                'tenant'        => 'pdpauthglb-a1',
                'response_type' => 'code',
                'scope'         => self::SCOPE,
                'audience'      => 'https://digital.panasonic.com/' . self::CLIENT_ID . '/api/v1/',
                '_csrf'         => $csrf,
                'state'         => $state,
                '_intstate'     => 'deprecated',
                'username'      => $email,
                'password'      => $password,
                'lang'          => 'de',
                'connection'    => 'PanasonicID-Authentication',
            ]));
            if ($r === null || $r['status'] !== 200) {
                return $this->fail('Anmeldung (Schritt 3/5) fehlgeschlagen — E-Mail oder Passwort falsch?' . $this->statusSuffix($r));
            }
            $hidden = $this->parseHiddenInputs($r['body']);
            if (isset($hidden['mfa_token'])) {
                return $this->fail('Das Konto verlangt Zwei-Faktor-Authentifizierung (2FA). Die wird von WPHub noch nicht unterstuetzt — bitte 2FA fuer dieses Konto deaktivieren oder ein eigenes Konto ohne 2FA fuer die Anbindung freigeben.');
            }
            if (!isset($hidden['wresult']) || !isset($hidden['wctx'])) {
                return $this->fail('Anmeldung (Schritt 3/5): unerwartete Antwort (keine Callback-Felder). E-Mail oder Passwort falsch?');
            }

            // Schritt 4: Callback + Redirects folgen bis zum Code.
            $r = $this->request('POST', self::AUTH_BASE . '/login/callback', [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ' . self::UA_BROWSER,
            ], http_build_query($hidden));
            if ($r === null || $r['status'] !== 302 || !isset($r['headers']['location'])) {
                return $this->fail('Callback (Schritt 4/5) fehlgeschlagen' . $this->statusSuffix($r));
            }
            $r = $this->request('GET', $this->absoluteAuthUrl($r['headers']['location']), ['user-agent: ' . self::UA_API]);
            if ($r === null || $r['status'] !== 302 || !isset($r['headers']['location'])) {
                return $this->fail('Callback-Weiterleitung (Schritt 4/5) fehlgeschlagen' . $this->statusSuffix($r));
            }
            $code = $this->queryParam($r['headers']['location'], 'code');
        }

        if (!is_string($code) || $code === '') {
            return $this->fail('Kein Autorisierungscode erhalten (Schritt 4/5)');
        }

        // Schritt 5: Code gegen Access-/Refresh-Token tauschen.
        $issuedAt = time();
        $r = $this->request('POST', self::AUTH_BASE . '/oauth/token', [
            'Auth0-Client: ' . self::AUTH0_CLIENT,
            'user-agent: ' . self::UA_API,
            'content-type: application/json',
        ], json_encode([
            'scope'         => 'openid',
            'client_id'     => self::CLIENT_ID,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ]));
        $bundle = $this->tokenBundleFromResponse($r, $issuedAt, 'Token-Tausch (Schritt 5/5)');
        return $bundle;
    }

    // Access-Token per Refresh-Token erneuern. Buendel behaelt clientId.
    public function refresh(array $bundle): ?array
    {
        $this->lastError = '';
        if (($bundle['refreshToken'] ?? '') === '') {
            return $this->fail('Kein Refresh-Token vorhanden — Neuanmeldung erforderlich');
        }
        $issuedAt = time();
        $r = $this->request('POST', self::AUTH_BASE . '/oauth/token', [
            'Auth0-Client: ' . self::AUTH0_CLIENT,
            'user-agent: ' . self::UA_API,
            'content-type: application/json',
        ], json_encode([
            'scope'         => $bundle['scope'] ?? self::SCOPE,
            'client_id'     => self::CLIENT_ID,
            'refresh_token' => $bundle['refreshToken'],
            'grant_type'    => 'refresh_token',
        ]));
        $new = $this->tokenBundleFromResponse($r, $issuedAt, 'Token-Erneuerung');
        if ($new === null) {
            return null;
        }
        $new['clientId'] = $bundle['clientId'] ?? '';
        return $new;
    }

    // App-Anmeldung an der Geraete-API — liefert die x-client-id, die alle
    // weiteren API-Aufrufe brauchen. Schreibt sie direkt ins Buendel.
    public function accLogin(array &$bundle): bool
    {
        $this->lastError = '';
        $r = $this->apiRequest($bundle, 'POST', '/auth/v2/login', ['language' => 0], false);
        if ($r === null) {
            return false;
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($json) || !isset($json['clientId'])) {
            $this->failApi('App-Anmeldung (auth/v2/login)', $r);
            return false;
        }
        $bundle['clientId'] = (string)$json['clientId'];
        return true;
    }

    // Geraetegruppen samt Geraeteliste.
    public function getGroups(array $bundle): ?array
    {
        $this->lastError = '';
        $r = $this->apiRequest($bundle, 'GET', '/device/group');
        $json = ($r !== null) ? json_decode($r['body'], true) : null;
        if ($r === null || $r['status'] !== 200 || !is_array($json)) {
            $this->failApi('Geraeteliste (device/group)', $r);
            return null;
        }
        return $json;
    }

    // Hinweis: Die A2W-Betriebsdaten (Verbindungsstatus, Betriebsart, Zonen-
    // und Speichertemperaturen) liefert die Comfort Cloud bereits INLINE in
    // der device/group-Antwort je Geraet. Ein separater Statusabruf ist nicht
    // noetig -- der fruehere Transfer-Proxy (/remote/v1/app/common/transfer)
    // und /deviceStatus/{guid} sind fuer A2W nicht freigegeben (400/403). Die
    // Auswertung passiert daher komplett im Modul aus getGroups().

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function tokenBundleFromResponse(?array $r, int $issuedAt, string $step): ?array
    {
        $json = ($r !== null) ? json_decode($r['body'], true) : null;
        if ($r === null || $r['status'] !== 200 || !is_array($json) || !isset($json['access_token'])) {
            return $this->fail($step . ' fehlgeschlagen' . $this->statusSuffix($r));
        }
        return [
            'accessToken'  => (string)$json['access_token'],
            'refreshToken' => (string)($json['refresh_token'] ?? ''),
            'expiresAt'    => $issuedAt + (int)($json['expires_in'] ?? 3600),
            'scope'        => (string)($json['scope'] ?? self::SCOPE),
            'clientId'     => '',
        ];
    }

    // Signierter Aufruf der Geraete-API (accsmart).
    private function apiRequest(array $bundle, string $method, string $path, ?array $jsonBody = null, bool $includeClientId = true): ?array
    {
        $this->versionRejected = false;
        $this->agreementRequired = false;
        $headers = $this->apiHeaders($bundle, $includeClientId);
        $body = ($jsonBody !== null) ? json_encode($jsonBody) : null;
        $r = $this->request($method, self::ACC_BASE . $path, $headers, $body);

        // Diagnose-Mitschnitt (nur Pfad ohne Query, Status, gekuerzter Koerper;
        // keine Header/Token). Query kann eine Geraete-GUID enthalten -> weg.
        $pathForTrace = explode('?', $path)[0];
        $reqBody = ($body !== null) ? ' req=' . substr($body, 0, 120) : '';
        if ($r === null) {
            $this->apiTrace[] = $method . ' ' . $pathForTrace . ' -> (kein Ergebnis: ' . $this->lastError . ')';
        } else {
            $this->apiTrace[] = $method . ' ' . $pathForTrace . ' -> ' . $r['status'] . $reqBody . ' resp=' . substr((string)$r['body'], 0, 200);
        }
        if (count($this->apiTrace) > 12) {
            $this->apiTrace = array_slice($this->apiTrace, -12);
        }
        return $r;
    }

    private function apiHeaders(array $bundle, bool $includeClientId): array
    {
        $timestamp = date('Y-m-d H:i:s');
        $headers = [
            'content-type: application/json;charset=utf-8',
            'user-agent: G-RAC',
            'x-app-name: Comfort Cloud',
            'x-app-timestamp: ' . $timestamp,
            'x-app-type: 1',
            'x-app-version: ' . $this->appVersion,
            'x-cfc-api-key: ' . $this->apiKey($timestamp, (string)($bundle['accessToken'] ?? '')),
            'x-user-authorization-v2: Bearer ' . ($bundle['accessToken'] ?? ''),
        ];
        if ($includeClientId && ($bundle['clientId'] ?? '') !== '') {
            $headers[] = 'x-client-id: ' . $bundle['clientId'];
        }
        return $headers;
    }

    // Nachbau der App-Signatur: sha256 ueber feste Bestandteile + Zeitstempel
    // + Token, 'cfc' nach dem 9. Hex-Zeichen eingesetzt. Der Zeitstempel wird
    // dabei — wie in der App — als UTC interpretiert, obwohl er lokal ist.
    private function apiKey(string $timestamp, string $token): string
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));
        $ms = ($dt !== false) ? (string)($dt->getTimestamp() * 1000) : '0';
        $hash = hash('sha256', 'Comfort Cloud' . self::API_KEY_SALT . $ms . 'Bearer ' . $token);
        return substr($hash, 0, 9) . 'cfc' . substr($hash, 9);
    }

    private function request(string $method, string $url, array $headers, ?string $body = null): ?array
    {
        if ($this->cookieFile === null) {
            $this->cookieFile = tempnam(sys_get_temp_dir(), 'wphubcc');
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = strtolower(trim($parts[0]));
                    $val = trim($parts[1]);
                    if ($key === 'set-cookie') {
                        $responseHeaders['set-cookie'][] = $val;
                    } else {
                        $responseHeaders[$key] = $val;
                    }
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $this->lastError = 'HTTP-Fehler: ' . curl_error($ch);
            curl_close($ch);
            $this->dbg('http', $method . ' ' . $this->urlForLog($url) . ' -> ' . $this->lastError);
            return null;
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->dbg('http', $method . ' ' . $this->urlForLog($url) . ' -> ' . $status);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$responseBody];
    }

    private function parseHiddenInputs(string $html): array
    {
        $out = [];
        if (preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $name = null;
                $value = '';
                if (preg_match('/name="([^"]*)"/i', $tag, $m)) {
                    $name = html_entity_decode($m[1], ENT_QUOTES);
                }
                if (preg_match('/value="([^"]*)"/i', $tag, $m)) {
                    $value = html_entity_decode($m[1], ENT_QUOTES);
                }
                if ($name !== null && $name !== '') {
                    $out[$name] = $value;
                }
            }
        }
        return $out;
    }

    private function cookieValue(array $response, string $name): ?string
    {
        foreach (($response['headers']['set-cookie'] ?? []) as $cookie) {
            if (preg_match('/^' . preg_quote($name, '/') . '=([^;]+)/', $cookie, $m)) {
                return urldecode($m[1]);
            }
        }
        return null;
    }

    private function queryParam(string $url, string $name): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }
        parse_str($query, $params);
        return isset($params[$name]) ? (string)$params[$name] : null;
    }

    private function absoluteAuthUrl(string $location): string
    {
        if (strpos($location, 'http') === 0) {
            return $location;
        }
        return self::AUTH_BASE . (strpos($location, '/') === 0 ? '' : '/') . $location;
    }

    private function randomString(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    private function statusSuffix(?array $r): string
    {
        if ($r === null) {
            return ' (' . $this->lastError . ')';
        }
        return ' (HTTP ' . $r['status'] . ')';
    }

    private function fail(string $message): ?array
    {
        $this->lastError = $message;
        $this->dbg('fehler', $message);
        return null;
    }

    private function failApi(string $what, ?array $r): void
    {
        if ($r === null) {
            // lastError kommt bereits aus request()
            $this->lastError = $what . ' fehlgeschlagen (' . $this->lastError . ')';
            return;
        }
        $body = substr((string)$r['body'], 0, 300);
        if ($r['status'] === 401 && strpos($body, '4106') !== false) {
            $this->versionRejected = true;
            $this->lastError = $what . ': Die verwendete App-Version (' . $this->appVersion . ') ist der Cloud zu alt (Code 4106). Sie wird normalerweise automatisch aktualisiert; schlaegt das fehl, im Formular die aktuelle Versionsnummer der Comfort-Cloud-App eintragen.';
        } elseif ($r['status'] === 401 && strpos($body, '4103') !== false) {
            $this->agreementRequired = true;
            $this->lastError = $what . ': Panasonic hat Nutzungsbedingungen/Datenschutzerklaerung aktualisiert (Code 4103). Bestaetigung noetig — Schaltflaeche „Aktualisierte Bedingungen akzeptieren" im Formular oder einmal die offizielle Comfort-Cloud-App oeffnen.';
        } else {
            $this->lastError = $what . ' fehlgeschlagen (HTTP ' . $r['status'] . '): ' . $body;
        }
        $this->dbg('fehler', $this->lastError);
    }

    // Query-Strings koennen Tokens enthalten (code=...) — fuer Logs kappen.
    private function urlForLog(string $url): string
    {
        $q = strpos($url, '?');
        return ($q === false) ? $url : substr($url, 0, $q) . '?…';
    }

    private function dbg(string $topic, string $text): void
    {
        if ($this->debug !== null) {
            call_user_func($this->debug, $topic, $text);
        }
    }
}
