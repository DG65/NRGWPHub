<?php

// WPHUB_VaillantClient — HTTP-Client fuer die (inoffizielle) myVAILLANT-API
// (aroTHERM/aroTHERM plus Waermepumpen). Referenz fuer den kompletten Ablauf:
// signalkraft/myPyllant (Python, aktiv gepflegt, Stand 09/2026) --
// https://github.com/signalkraft/myPyllant (api.py/const.py/utils.py).
//
// Login laeuft ueber Keycloak (identity.vaillant-group.com) mit OAuth2
// Authorization Code + PKCE, automatisiert wie beim Panasonic-Login (siehe
// ComfortCloudClient.php): GET der Login-Seite, Formular mit Benutzername/
// Passwort abschicken, Code aus dem Redirect-Location-Header ziehen, gegen
// Access-/Refresh-Token tauschen. Zusaetzliche Huerde gegenueber Panasonic:
// die Login-Seite verlangt ein geloestes ALTCHA-Proof-of-Work (siehe
// solveAltchaChallenge()) als Formularfeld.
//
// Stand 14.09.2026: NUR nach der myPyllant-Referenz gebaut, NOCH NIE gegen
// die echte Cloud gelaufen -- Dietmar hat keine Vaillant-Anlage zum Testen.
// Braucht einen Nutzer mit echtem myVAILLANT-Konto, der den ersten Login
// verifiziert (siehe README/CHANGELOG). Umfang bewusst schmal gehalten:
// nur Systemliste + Basiswerte (Aussentemperatur, Systemdruck, Vorlauf-
// temperatur, Warmwasser/Pufferspeicher-Temperaturen aus state.system.*,
// vom Python-Modell direkt bestaetigt) -- KEINE Steuerung (Sollwerte setzen
// o.ae.), das waere ohne Testkonto zu riskant zu raten. Nur Regler-Typ "tli"
// (der mit Abstand haeufigste, auch myPyllants eigener Standardwert) wird
// unterstuetzt; "vrc700"/"scf"-Systeme werden übersprungen statt geraten.
//
// Globaler Klassenname bewusst mit WPHUB_-Praefix (Verbund-Konvention
// 25.07.2026, mehrere NRG-Stack-Module koennen im selben PHP-Prozess laufen).
//
// Kein Passwort und kein Token wird geloggt.

class WPHUB_VaillantClient
{
    const AUTH_BASE          = 'https://identity.vaillant-group.com/auth/realms';
    const ALTCHA_CHALLENGE   = 'https://identity.vaillant-group.com/api/altcha/challenge';
    const API_BASE_TLI       = 'https://api.vaillant-group.com/service-connected-control/end-user-app-api/v1';
    // myPyllant.const.API_URL_BASE['vrc700'] -- eigene Basis-URL, NICHT
    // API_BASE_TLI mit anderem Pfad (Quelltext gegengelesen 25.09.2026).
    const API_BASE_VRC700    = 'https://api.vaillant-group.com/service-connected-control/vrc700/v1';
    const CLIENT_ID          = 'myvaillant';
    const REDIRECT_URI       = 'enduservaillant.page.link://login';
    const UA                 = 'okhttp/4.9.2';
    // Oeffentlicher, im myPyllant-Quelltext bereits veroeffentlichter
    // Abonnement-Schluessel der myVAILLANT-App (kein Geheimnis von uns).
    const SUBSCRIPTION_KEY   = '1e0a2f3511fb4c5bbb1c7f9fedd20b1c';

    private $country;
    private $lastError = '';
    private $cookieFile = null;
    private $debug = null; // callable(string $topic, string $text)

    public function __construct(string $country, ?callable $debug = null)
    {
        $this->country = $country;
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

    private function realm(): string
    {
        return 'vaillant-' . $this->country . '-b2c';
    }

    /**
     * Login: PKCE-Code besorgen (Formular scrapen, ALTCHA loesen, absenden),
     * dann gegen Access-/Refresh-Token tauschen. Ablauf 1:1 nach
     * myPyllant.api.MyPyllantAPI.get_code()/get_token().
     */
    public function login(string $email, string $password): ?array
    {
        $this->lastError = '';
        $this->resetCookies();

        $verifier = $this->randomString(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        // Schritt 1: Authorize-Endpunkt der Login-Seite laden.
        $r = $this->request('GET', self::AUTH_BASE . '/' . $this->realm() . '/protocol/openid-connect/auth?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_ID,
            'code'                  => 'code_challenge', // so auch in der Referenz, wirkungsloses Zusatzfeld
            'redirect_uri'          => self::REDIRECT_URI,
            'code_challenge_method' => 'S256',
            'code_challenge'        => $challenge,
        ]), ['user-agent: ' . self::UA]);
        if ($r === null || $r['status'] !== 200) {
            return $this->fail('Login-Seite (Schritt 1/3) fehlgeschlagen' . $this->statusSuffix($r));
        }

        // Schritt 2: Login-Formular-Aktion aus dem HTML ziehen (enthaelt
        // Keycloak-Session-Code in der URL), Zugangsdaten + geloestes ALTCHA
        // absenden.
        $loginUrl = $this->extractLoginFormUrl($r['body']);
        if ($loginUrl === null) {
            return $this->fail('Login-Seite (Schritt 1/3): Login-Formular nicht gefunden -- Realm/Land falsch?');
        }
        $payload = ['username' => $email, 'password' => $password, 'credentialId' => ''];
        $altcha = $this->request('GET', self::ALTCHA_CHALLENGE, ['user-agent: ' . self::UA]);
        if ($altcha !== null && $altcha['status'] === 200) {
            $challengeJson = json_decode($altcha['body'], true);
            if (is_array($challengeJson)) {
                $solved = $this->solveAltchaChallenge($challengeJson);
                if ($solved !== null) {
                    $payload['altcha'] = $solved;
                }
            }
        }
        // Kein ALTCHA-Feld bei Fehlschlag -- best effort, wie in der Referenz.

        $r = $this->request('POST', $loginUrl, [
            'user-agent: ' . self::UA,
            'content-type: application/x-www-form-urlencoded',
        ], http_build_query($payload));
        if ($r === null || $r['status'] !== 302 || !isset($r['headers']['location'])) {
            return $this->fail('Anmeldung (Schritt 2/3) fehlgeschlagen -- E-Mail oder Passwort falsch?' . $this->statusSuffix($r));
        }
        $code = $this->queryParam($r['headers']['location'], 'code');
        if ($code === null) {
            return $this->fail('Anmeldung (Schritt 2/3): kein Autorisierungscode erhalten');
        }

        // Schritt 3: Code gegen Access-/Refresh-Token tauschen.
        $issuedAt = time();
        $r = $this->request('POST', self::AUTH_BASE . '/' . $this->realm() . '/protocol/openid-connect/token', [
            'user-agent: ' . self::UA,
            'content-type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => self::CLIENT_ID,
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => self::REDIRECT_URI,
        ]));
        return $this->tokenBundleFromResponse($r, $issuedAt, 'Token-Tausch (Schritt 3/3)');
    }

    public function refresh(array $bundle): ?array
    {
        $this->lastError = '';
        if (($bundle['refreshToken'] ?? '') === '') {
            return $this->fail('Kein Refresh-Token vorhanden -- Neuanmeldung erforderlich');
        }
        $issuedAt = time();
        $r = $this->request('POST', self::AUTH_BASE . '/' . $this->realm() . '/protocol/openid-connect/token', [
            'user-agent: ' . self::UA,
            'content-type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'refresh_token' => $bundle['refreshToken'],
        ]));
        return $this->tokenBundleFromResponse($r, $issuedAt, 'Token-Erneuerung');
    }

    /** Konfigurierte Anlagen des Kontos -- je eine Zeile ['systemId','homeName']. */
    public function getHomes(array $bundle): ?array
    {
        $this->lastError = '';
        $r = $this->apiRequest($bundle, 'GET', self::API_BASE_TLI . '/homes');
        $json = ($r !== null) ? json_decode($r['body'], true) : null;
        if ($r === null || $r['status'] !== 200 || !is_array($json)) {
            $this->failApi('Anlagenliste (homes)', $r);
            return null;
        }
        $out = [];
        foreach ($json as $home) {
            if (!is_array($home) || !isset($home['systemId']) || (string)$home['systemId'] === '') {
                continue;
            }
            $out[] = ['systemId' => (string)$home['systemId'], 'homeName' => (string)($home['homeName'] ?? '')];
        }
        return $out;
    }

    /**
     * Regler-Typ einer Anlage (meist "tli") -- vor dem eigentlichen
     * Systemabruf pruefen, siehe myPyllant.api.get_control_identifier().
     * "tli" bei jedem Fehler (Referenz-Fallback), damit ein einzelner
     * kaputter Abruf nicht die ganze Anlage lahmlegt.
     */
    public function getControlIdentifier(array $bundle, string $systemId): string
    {
        $r = $this->apiRequest($bundle, 'GET', self::API_BASE_TLI . '/systems/' . rawurlencode($systemId) . '/meta-info/control-identifier');
        $json = ($r !== null && $r['status'] === 200) ? json_decode($r['body'], true) : null;
        return (is_array($json) && isset($json['controlIdentifier'])) ? (string)$json['controlIdentifier'] : 'tli';
    }

    /**
     * Rohes System-JSON (configuration/state/properties/current_system) fuer
     * eine tli-Anlage, Schluessel per snakeCaseKeysDeep() konvertiert --
     * siehe dort, warum das noetig ist (Fund 25.09.2026: die rohe API liefert
     * camelCase, nicht snake_case, siehe myPyllant.api.get_systems()).
     */
    public function getSystem(array $bundle, string $systemId): ?array
    {
        $this->lastError = '';
        $r = $this->apiRequest($bundle, 'GET', self::API_BASE_TLI . '/systems/' . rawurlencode($systemId) . '/tli');
        if ($r === null || $r['status'] !== 200) {
            $this->failApi('Systemdaten (systems/' . $systemId . '/tli)', $r);
            return null;
        }
        $parsed = self::parseTliBody((string)$r['body']);
        if ($parsed === null) {
            $this->failApi('Systemdaten (systems/' . $systemId . '/tli) -- ungueltiges JSON', $r);
        }
        return $parsed;
    }

    /**
     * Reine Dekodierfunktion fuer eine tli-System-Antwort -- ausgelagert,
     * damit der Pruefstand sie OHNE echtes HTTP direkt mit einem
     * vorgefertigten JSON-Body testen kann (dieselbe Funktion, die auch
     * getSystem() im Live-Betrieb nutzt). NULL nur bei ungueltigem JSON.
     */
    public static function parseTliBody(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }
        return self::snakeCaseKeysDeep($json);
    }

    /**
     * Rohes System-JSON fuer eine vrc700-Anlage (Sensocomfort/aeltere
     * Vaillant-/Marken-Regelung ueber dieselbe myVAILLANT-Cloud). Eigene
     * Basis-URL (NICHT die TLI-Basis mit einem anderen Pfadsuffix -- eine
     * komplett eigene Route, siehe myPyllant.const.API_URL_BASE) und ein
     * Textersatz VOR dem JSON-Dekodieren ("domesticHotWater"->"dhw",
     * "DomesticHotWater"->"Dhw"), 1:1 aus myPyllant.api.get_systems()
     * uebernommen (Quelltext am 25.09.2026 gegengelesen, siehe
     * WPHub-CLAUDE.md). Feldnamen nach der Konvertierung sind fuer
     * DHW-Werte dadurch ANDERS als bei tli (z. B. "..._dhw" statt
     * "..._d_h_w") -- welche Felder eine echte vrc700-Anlage tatsaechlich
     * liefert, ist noch UNGEPRUEFT (kein Testkonto). maintainDeviceVariablesVaillant()
     * liest deshalb bewusst nur die Felder, die bei tli bereits bestaetigt sind;
     * findet keines davon einen Treffer, bleibt die Anlage ohne Werte, aber
     * ERREICHBAR und ERKANNT (Fortschritt gegenueber dem bisherigen
     * Komplett-Ueberspringen) -- das komplette Roh-JSON geht zusaetzlich per
     * SendDebug raus, um die echten Feldnamen von einem Tester zu bekommen.
     */
    public function getSystemVrc700(array $bundle, string $systemId): ?array
    {
        $this->lastError = '';
        $r = $this->apiRequest($bundle, 'GET', self::API_BASE_VRC700 . '/systems/' . rawurlencode($systemId));
        if ($r === null || $r['status'] !== 200) {
            $this->failApi('Systemdaten vrc700 (systems/' . $systemId . ')', $r);
            return null;
        }
        $parsed = self::parseVrc700Body((string)$r['body']);
        if ($parsed === null) {
            $this->failApi('Systemdaten vrc700 (systems/' . $systemId . ') -- ungueltiges JSON nach Ersetzung', $r);
        }
        return $parsed;
    }

    /**
     * Reine Dekodierfunktion fuer eine vrc700-System-Antwort -- siehe
     * parseTliBody(), gleiches Testbarkeits-Muster. Fuehrt zusaetzlich den
     * domesticHotWater/DomesticHotWater-Textersatz VOR dem Dekodieren aus.
     */
    public static function parseVrc700Body(string $body): ?array
    {
        $raw = str_replace(['domesticHotWater', 'DomesticHotWater'], ['dhw', 'Dhw'], $body);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        return self::snakeCaseKeysDeep($json);
    }

    /**
     * camelCase -> snake_case, rekursiv ueber verschachtelte Arrays/Objekte --
     * 1:1-Nachbau von myPyllant.utils.dict_to_snake_case() (Regex
     * `(?<!^)(?=[A-Z])`, Unterstrich vor JEDEM Grossbuchstaben ausser am
     * Anfang, dann klein). Wichtig fuer Akronyme: "topDHW" wird zu
     * "top_d_h_w" (jeder Grossbuchstabe einzeln), NICHT "top_dhw" -- das
     * bestaetigt, warum die bestehenden tli-Feldnamen in
     * maintainDeviceVariablesVaillant() genau so und nicht anders geschrieben
     * sind (sie wurden aus myPyllants eigenen, bereits konvertierten
     * Pydantic-Modellnamen uebernommen, nicht aus der rohen API).
     */
    private static function snakeCaseKeysDeep($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $isList = array_keys($data) === range(0, count($data) - 1);
        if ($isList) {
            return array_map([self::class, 'snakeCaseKeysDeep'], $data);
        }
        $out = [];
        foreach ($data as $k => $v) {
            $newKey = is_string($k) ? strtolower((string)preg_replace('/(?<!^)(?=[A-Z])/', '_', $k)) : $k;
            $out[$newKey] = self::snakeCaseKeysDeep($v);
        }
        return $out;
    }

    /**
     * ALTCHA-Proof-of-Work loesen (PBKDF2-Brute-Force bis ein Schluessel mit
     * dem geforderten Praefix gefunden ist -- die Schwierigkeit steckt in der
     * Laenge von keyPrefix, typischerweise 1-2 Byte, daher schnell). 1:1 nach
     * myPyllant.utils.solve_altcha_challenge(). Erwartete Struktur (Antwort
     * von GET /api/altcha/challenge): {"parameters":{"algorithm":...,
     * "cost":...,"keyLength":...,"keyPrefix":"<hex>","nonce":"<hex>",
     * "salt":"<hex>"},"signature":"..."}.
     */
    public function solveAltchaChallenge(array $challenge): ?string
    {
        $p = $challenge['parameters'] ?? null;
        if (!is_array($p) || !isset($p['nonce'], $p['salt'], $p['keyPrefix'], $p['cost'], $challenge['signature'])) {
            return null;
        }
        $nonce = @hex2bin((string)$p['nonce']);
        $salt = @hex2bin((string)$p['salt']);
        $keyPrefix = @hex2bin((string)$p['keyPrefix']);
        if ($nonce === false || $salt === false || $keyPrefix === false) {
            return null;
        }
        $cost = (int)$p['cost'];
        $keyLength = isset($p['keyLength']) ? (int)$p['keyLength'] : 32;
        $digest = ['PBKDF2/SHA-512' => 'sha512', 'PBKDF2/SHA-384' => 'sha384'][(string)($p['algorithm'] ?? '')] ?? 'sha256';
        $prefixLen = strlen($keyPrefix);

        $counter = 0;
        // Erwartete Versuchszahl ~256^prefixLen (meist 1 Byte -> ~256
        // Versuche) -- ein harter oberer Limit schuetzt vor einer Endlos-
        // schleife, falls die Challenge nie passt (z.B. falsches Format).
        $maxTries = 5_000_000;
        while ($counter < $maxTries) {
            $derived = hash_pbkdf2($digest, $nonce . pack('N', $counter), $salt, $cost, $keyLength, true);
            if (substr($derived, 0, $prefixLen) === $keyPrefix) {
                $solution = ['counter' => $counter, 'derivedKey' => bin2hex($derived), 'time' => 0];
                $payload = ['challenge' => ['parameters' => $p, 'signature' => $challenge['signature']], 'solution' => $solution];
                return base64_encode(json_encode($payload));
            }
            $counter++;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Interna
    // ------------------------------------------------------------------

    private function getAuthorizedHeaders(array $bundle): array
    {
        return [
            'Authorization: Bearer ' . ($bundle['accessToken'] ?? ''),
            'x-app-identifier: VAILLANT',
            'Accept: application/json, text/plain, */*',
            'x-client-locale: de-DE',
            'x-idm-identifier: KEYCLOAK',
            'ocp-apim-subscription-key: ' . self::SUBSCRIPTION_KEY,
            'User-Agent: ' . self::UA,
        ];
    }

    private function apiRequest(array $bundle, string $method, string $url): ?array
    {
        return $this->request($method, $url, $this->getAuthorizedHeaders($bundle));
    }

    private function tokenBundleFromResponse(?array $r, int $issuedAt, string $what): ?array
    {
        $json = ($r !== null) ? json_decode($r['body'], true) : null;
        if ($r === null || $r['status'] >= 400 || !is_array($json) || !isset($json['access_token'])) {
            $this->failApi($what, $r);
            return null;
        }
        return [
            'accessToken'  => (string)$json['access_token'],
            'refreshToken' => (string)($json['refresh_token'] ?? ''),
            'expiresAt'    => $issuedAt + (int)($json['expires_in'] ?? 300),
            'country'      => $this->country,
        ];
    }

    // Login-Formular-Aktion aus der Keycloak-HTML-Seite ziehen (<form
    // action="...login-actions/authenticate?..." ...>) -- gleiches Prinzip
    // wie ComfortCloudClient::parseHiddenInputs(), hier reicht die Action-URL.
    private function extractLoginFormUrl(string $html): ?string
    {
        if (preg_match('/<form[^>]+action="([^"]*login-actions\/authenticate[^"]*)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        return null;
    }

    private function resetCookies(): void
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'wphubvai');
    }

    private function request(string $method, string $url, array $headers, ?string $body = null): ?array
    {
        if ($this->cookieFile === null) {
            $this->cookieFile = tempnam(sys_get_temp_dir(), 'wphubvai');
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

    private function queryParam(string $url, string $name): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }
        parse_str($query, $params);
        return isset($params[$name]) ? (string)$params[$name] : null;
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
            $this->lastError = $what . ' fehlgeschlagen (' . $this->lastError . ')';
            return;
        }
        $this->lastError = $what . ' fehlgeschlagen (HTTP ' . $r['status'] . '): ' . substr((string)$r['body'], 0, 300);
        $this->dbg('fehler', $this->lastError);
    }

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
