<?php
/**
 * Read-only Kronoterm heating heat-pump Cloud client for NRG-Stack WPHub.
 *
 * Independent PHP implementation, informed by the public API descriptions in
 * Favio25/Kronoterm-homeassistant (MIT); no Python code is included.
 * Endpoint: https://cloud.kronoterm.com/jsoncgi.php
 *
 * Scope of step 06.1: login, menu handshake, information/inventory, page reads.
 * No Symcon variables, device selection or write commands are implemented here.
 */
final class WPHUB_KronotermClient
{
    private const API_URL = 'https://cloud.kronoterm.com/jsoncgi.php';
    private const LOGIN_URL = 'https://cloud.kronoterm.com/?login=1';
    private const PAGES = [
        'menu'          => ['Menu' => '1'],
        'main'          => ['TopPage' => '5', 'Subpage' => '3'],
        'info'          => ['TopPage' => '1', 'Subpage' => '1'],
        'system_data'   => ['TopPage' => '1', 'Subpage' => '2'],
        'shortcuts'     => ['TopPage' => '1', 'Subpage' => '3'],
        'reservoir'     => ['TopPage' => '1', 'Subpage' => '4'],
        'loop1'         => ['TopPage' => '1', 'Subpage' => '5'],
        'loop2'         => ['TopPage' => '1', 'Subpage' => '6'],
        'loop3'         => ['TopPage' => '1', 'Subpage' => '7'],
        'loop4'         => ['TopPage' => '1', 'Subpage' => '8'],
        'dhw'           => ['TopPage' => '1', 'Subpage' => '9'],
        'pool'          => ['TopPage' => '1', 'Subpage' => '10'],
        'main_settings' => ['TopPage' => '3', 'Subpage' => '11'],
        'consumption'   => ['TopPage' => '4', 'Subpage' => '4', 'Action' => '4'],
    ];

    private string $username;
    private string $password;
    private ?string $authMode = null;
    private ?string $cookieFile = null;
    /** @var callable|null A request adapter for isolated offline tests. */
    private $transport;
    private int $timeoutSeconds;

    public function __construct(string $username, string $password, ?callable $transport = null, int $timeoutSeconds = 10)
    {
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Kronoterm-Zugangsdaten fehlen.');
        }
        $this->username = $username;
        $this->password = $password;
        $this->transport = $transport;
        $this->timeoutSeconds = max(2, min(60, $timeoutSeconds));
    }

    public function __destruct()
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    /** Authenticate with a JSON-confirmed menu handshake, never HTTP 200 alone. */
    public function login(): string
    {
        $this->authMode = null;
        if ($this->validMenu($this->request('GET', 'menu', null, 'basic'))) {
            $this->authMode = 'basic';
            return 'basic';
        }
        $login = $this->request('POST', null, [
            'username' => $this->username,
            'password' => $this->password,
        ], 'web-login');
        if (!in_array($login['status'], [200, 302], true) ||
            !$this->validMenu($this->request('GET', 'menu', null, 'web'))) {
            throw new RuntimeException('Kronoterm-Cloud-Anmeldung nicht bestaetigt.');
        }
        $this->authMode = 'web';
        return 'web';
    }

    public function getAuthMode(): ?string
    {
        return $this->authMode;
    }

    /**
     * Fetch exactly one of the named READ-only pages.
     * @return array<string,mixed>
     */
    public function getPage(string $name): array
    {
        if (!isset(self::PAGES[$name])) {
            throw new InvalidArgumentException('Unbekannte Kronoterm-Leseseite.');
        }
        if ($this->authMode === null) {
            $this->login();
        }
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->request('GET', $name, null, (string)$this->authMode);
            if (in_array($response['status'], [401, 403], true) || $this->sessionRedirect($response)) {
                if ($attempt === 0) {
                    $this->login();
                    continue;
                }
                throw new RuntimeException('Kronoterm-Cloud-Sitzung abgelaufen.');
            }
            if ($response['status'] !== 200) {
                throw new RuntimeException('Kronoterm-Cloud: HTTP ' . $response['status'] . ' (' . $name . ').');
            }
            $json = json_decode($response['body'], true);
            if (!is_array($json)) {
                throw new RuntimeException('Kronoterm-Cloud: ungueltiges JSON (' . $name . ').');
            }
            return $json;
        }
        throw new RuntimeException('Kronoterm-Cloud: keine gueltige Sitzung.');
    }

    /**
     * Read historical/trend CONSUMPTION without a control command. Kronoterm
     * expects a POST form for this read endpoint. Preserve duplicate dValues[].
     * A dated response contains kWh daily values, not total energy counters.
     * @return array<string,mixed>
     */
    public function getDailyConsumption(?\DateTimeImmutable $day = null): array
    {
        $day = $day ?? new \DateTimeImmutable('now');
        $form = [
            ['d2','0'], ['type','day'], ['aValues[]','17'],
            ['dValues[]','90'], ['dValues[]','0'], ['dValues[]','91'],
            ['dValues[]','92'], ['dValues[]','1'], ['dValues[]','2'],
            ['dValues[]','24'], ['dValues[]','71'],
            ['year', $day->format('Y')], ['d1', (string)((int)$day->format('z') + 1)],
        ];
        if ($this->authMode === null) $this->login();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->request('POST', 'consumption', $form, (string)$this->authMode);
            if (in_array($response['status'], [401,403], true) || $this->sessionRedirect($response)) {
                if ($attempt === 0) { $this->login(); continue; }
                throw new RuntimeException('Kronoterm-Cloud: Energieabfrage nicht authentifiziert.');
            }
            if ($response['status'] !== 200) {
                throw new RuntimeException('Kronoterm-Cloud: Energieabfrage HTTP ' . $response['status']);
            }
            $json = json_decode($response['body'], true);
            if (!is_array($json)) throw new RuntimeException('Kronoterm-Cloud: Energieantwort kein JSON.');
            return $json;
        }
        throw new RuntimeException('Kronoterm-Cloud: Energieabfrage fehlgeschlagen.');
    }

    /**
     * Recognize controller *configuration*, not necessarily physical installation.
     * null means no reliable information; false means a reliable disabled flag.
     * @return array<string,bool|null>
     */
    public static function extractInventory(array $info): array
    {
        $flags = is_array($info['TemperaturesAndConfig'] ?? null) ? $info['TemperaturesAndConfig'] : [];
        $result = [];
        for ($i = 1; $i <= 4; ++$i) {
            $result['loop' . $i] = self::flag($flags, 'circle_' . $i . '_installed');
        }
        $result['dhw'] = self::flag($flags, 'tap_water_installed');
        $result['pool_active'] = self::flag($flags, 'pool_active');
        $result['alternative_source_sensor_visible'] = self::flag($flags, 'alt_source_temp_visible');
        // No verified Cloud flags for physical heating/cooling buffer installed.
        $result['heating_buffer'] = null;
        $result['cooling_buffer'] = null;
        return $result;
    }

    /**
     * Read an initial snapshot. Optional pages are isolated; their failure does
     * not invalidate a working main page or erase historical Symcon variables.
     * @return array<string,mixed>
     */
    public function readSnapshot(): array
    {
        $menu = $this->getPage('menu');
        if (!array_key_exists('hp_id', $menu) || $menu['hp_id'] === null) {
            throw new RuntimeException('Kronoterm-Cloud: keine eindeutig ausgewaehlte Waermepumpe.');
        }
        $info = $this->getPage('info');
        $inventory = self::extractInventory($info);
        $pages = ['menu' => $menu, 'info' => $info, 'main' => $this->getPage('main')];
        $pageStatus = ['menu' => 'available', 'info' => 'available', 'main' => 'available'];
        $optional = ['shortcuts', 'system_data', 'main_settings', 'reservoir',
                     'loop1', 'loop2', 'loop3', 'loop4', 'dhw', 'pool'];
        foreach ($optional as $name) {
            // Explicitly absent controller configuration may be skipped.
            $flag = $name === 'pool' ? $inventory['pool_active'] : ($inventory[$name] ?? null);
            if ($flag === false && ($name === 'dhw' || strpos($name, 'loop') === 0)) {
                $pageStatus[$name] = 'not_configured';
                continue;
            }
            try {
                $pages[$name] = $this->getPage($name);
                $pageStatus[$name] = 'available';
            } catch (RuntimeException $exception) {
                $pages[$name] = null;
                $pageStatus[$name] = 'unavailable';
            }
        }
        return [
            'hp_id' => $menu['hp_id'],
            'inventory' => $inventory,
            'pages' => $pages,
            'page_status' => $pageStatus,
            'auth_mode' => $this->authMode,
        ];
    }

    private static function flag(array $flags, string $key): ?bool
    {
        if (!array_key_exists($key, $flags)) {
            return null;
        }
        $v = $flags[$key];
        if ($v === true || $v === 1 || $v === '1' || $v === 'true') {
            return true;
        }
        if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
            return false;
        }
        return null;
    }

    /** @param array{status:int,body:string} $r */
    private function validMenu(array $r): bool
    {
        if ($r['status'] !== 200) {
            return false;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) && array_key_exists('hp_id', $data) && $data['hp_id'] !== null;
    }

    /** @param array{status:int,body:string} $r */
    private function sessionRedirect(array $r): bool
    {
        if ($r['status'] !== 200) {
            return false;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) && ($data['result'] ?? null) === 'action'
            && is_string($data['js'] ?? null) && strpos($data['js'], 'window.location') !== false;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function request(string $method, ?string $page, ?array $form, string $auth): array
    {
        $params = $page !== null ? self::PAGES[$page] : [];
        $login = $auth === 'web-login';
        $url = $login ? self::LOGIN_URL : self::API_URL;
        if ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = $login
            ? ['Accept: text/html,application/xhtml+xml', 'Referer: ' . self::LOGIN_URL, 'Origin: https://cloud.kronoterm.com', 'User-Agent: Mozilla/5.0']
            : ['Accept: */*', 'X-Requested-With: XMLHttpRequest', 'Accept-Language: en-US,en;q=0.9',
               'Referer: ' . ($auth === 'basic' ? self::API_URL : self::LOGIN_URL),
               'Origin: https://cloud.kronoterm.com'];
        if ($auth === 'basic') {
            $headers[] = 'phonegap: 1.5.0';
        }
        $cookies = ['lang=en'];
        if (isset($params['TopPage'])) {
            $cookies[] = 'CurrentTopPage=' . $params['TopPage'];
        }
        if (isset($params['Subpage'])) {
            $cookies[] = 'CurrentSubPage=' . $params['Subpage'];
        }
        $options = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'cookieHeader' => implode('; ', $cookies),
            'form' => $form,
            'formEncoded' => $form === null ? '' : self::encodeForm($form),
            'basicCredentials' => $auth === 'basic' ? [$this->username, $this->password] : null,
            'followRedirects' => $login,
        ];
        if ($this->transport !== null) {
            $response = ($this->transport)($options);
        } else {
            $response = $this->curlRequest($options);
        }
        if (!is_array($response) || !isset($response['status']) || !is_int($response['status']) ||
            !isset($response['body']) || !is_string($response['body'])) {
            throw new RuntimeException('Kronoterm-Client: ungueltige HTTP-Antwort.');
        }
        return $response;
    }

    /** Support both associative login forms and repeated-key consumption forms. */
    private static function encodeForm(array $form): string
    {
        if (!array_is_list($form)) {
            return http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }
        $pairs = [];
        foreach ($form as $item) {
            if (!is_array($item) || count($item) !== 2) {
                throw new InvalidArgumentException('Kronoterm: invalid form data.');
            }
            $pairs[] = rawurlencode((string)$item[0]) . '=' . rawurlencode((string)$item[1]);
        }
        return implode('&', $pairs);
    }

    /** @param array<string,mixed> $o */
    private function curlRequest(array $o): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP-cURL-Erweiterung fehlt.');
        }
        if ($this->cookieFile === null) {
            $path = tempnam(sys_get_temp_dir(), 'wphub-kr-');
            if ($path === false) {
                throw new RuntimeException('Keine temporaere Kronoterm-Cookie-Datei moeglich.');
            }
            @chmod($path, 0600);
            $this->cookieFile = $path;
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Kronoterm-HTTP-Client konnte nicht initialisiert werden.');
        }
        $headers = $o['headers'];
        if ($o['method'] === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $curl = [
            CURLOPT_URL => $o['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIE => $o['cookieHeader'],
            CURLOPT_FOLLOWLOCATION => $o['followRedirects'],
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[CURLOPT_PROTOCOLS_STR] = 'https';
            if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
                $curl[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
            }
        }
        if ($o['basicCredentials'] !== null) {
            $curl[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curl[CURLOPT_USERPWD] = $o['basicCredentials'][0] . ':' . $o['basicCredentials'][1];
        }
        if ($o['method'] === 'POST') {
            $curl[CURLOPT_POST] = true;
            $curl[CURLOPT_POSTFIELDS] = $o['formEncoded'];
        }
        curl_setopt_array($ch, $curl);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            // Do not include cURL error strings: URLs and credentials must not leak.
            throw new RuntimeException('Kronoterm-Cloud: HTTP-Verbindungsfehler.');
        }
        return ['status' => $status, 'body' => $body];
    }
}
