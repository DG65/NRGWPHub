<?php
/** Offline smoke/regression tests: php .tools/test-kronoterm-client.php */
require_once __DIR__ . '/../../WPHub/libs/KronotermClient.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    echo "OK  {$message}\n";
}

// Basic authentication requires hp_id in JSON, not merely 200.
$requests = [];
$transport = function (array $request) use (&$requests): array {
    $requests[] = $request;
    if (str_contains($request['url'], 'Menu=1')) {
        return ['status' => 200, 'body' => '{"hp_id":42}'];
    }
    if (str_contains($request['url'], 'Subpage=1')) {
        return ['status' => 200, 'body' => json_encode(['TemperaturesAndConfig' => [
            'circle_1_installed' => 0, 'circle_2_installed' => 1,
            'tap_water_installed' => 0, 'pool_active' => 0,
        ]])];
    }
    if (str_contains($request['url'], 'TopPage=5')) {
        return ['status' => 200, 'body' => '{"ModbusReg":[]}'];
    }
    if (str_contains($request['url'], 'Subpage=6')) {
        return ['status' => 200, 'body' => '{"TemperaturesAndConfig":{"heating_circle_2_temp":31.5}}'];
    }
    if (str_contains($request['url'], 'Subpage=4')) {
        return ['status' => 502, 'body' => 'failure'];
    }
    return ['status' => 200, 'body' => '{}'];
};
$client = new WPHUB_KronotermClient('account', 'top-secret', $transport);
check($client->login() === 'basic', 'Basic login');
$snapshot = $client->readSnapshot();
check($snapshot['hp_id'] === 42, 'Menu hp_id');
check($snapshot['inventory']['loop1'] === false && $snapshot['inventory']['loop2'] === true, 'Loop flags');
check($snapshot['inventory']['dhw'] === false, 'DHW configuration');
check($snapshot['inventory']['heating_buffer'] === null, 'Buffer remains unknown');
check($snapshot['page_status']['loop1'] === 'not_configured', 'Skip unconfigured loop');
check($snapshot['page_status']['loop2'] === 'available', 'Configured loop fetched');
check($snapshot['page_status']['reservoir'] === 'unavailable', 'Optional page failure isolated');
check($snapshot['page_status']['main'] === 'available', 'Main still available');
check($snapshot['page_status']['dhw'] === 'not_configured', 'Skip unconfigured DHW');
check($snapshot['page_status']['pool'] === 'available', 'Inactive pool flag is not proof of absent installation');
check(!array_filter($requests, fn($r) => str_contains($r['url'], 'Subpage=9')), 'No DHW request');
check(!array_filter($requests, fn($r) => str_contains($r['url'], 'Subpage=5')), 'No loop1 request');
check(!array_filter($requests, fn($r) => $r['method'] !== 'GET'), 'Read snapshot sends GET only');

$inventory = WPHUB_KronotermClient::extractInventory(['TemperaturesAndConfig' => [
    'circle_1_installed' => 'false', 'circle_2_installed' => 'true',
    'circle_3_installed' => '', 'circle_4_installed' => '1',
]]);
check($inventory['loop1'] === false && $inventory['loop2'] === true, 'String flags normalized');
check($inventory['loop3'] === null && $inventory['loop4'] === true, 'Unknown not changed to false');
check($inventory['dhw'] === null && $inventory['cooling_buffer'] === null, 'Missing remains unknown');

$webCalls = [];
$webTransport = function (array $req) use (&$webCalls): array {
    $webCalls[] = $req;
    if ($req['method'] === 'POST') {
        return ['status' => 200, 'body' => '<html>Signed in</html>'];
    }
    if ($req['basicCredentials'] !== null) {
        return ['status' => 401, 'body' => 'Unauthorized'];
    }
    if (str_contains($req['url'], 'Menu=1')) {
        return ['status' => 200, 'body' => '{"hp_id":"selected"}'];
    }
    return ['status' => 200, 'body' => '{}'];
};
$web = new WPHUB_KronotermClient('account', 'web-secret', $webTransport);
check($web->login() === 'web', 'Web session fallback and verified handshake');
check(count($webCalls) === 3 && $webCalls[1]['method'] === 'POST', 'Basic then web login then handshake');
check($webCalls[2]['basicCredentials'] === null, 'Web-session request without Basic credentials');
check($web->getPage('main') === [], 'Web-session page access');

$failed = new WPHUB_KronotermClient('account', 'never-log-me', static function (array $req): array {
    return ['status' => 200, 'body' => '<html>Login required</html>'];
});
try {
    $failed->login();
    throw new RuntimeException('Expected login failure');
} catch (RuntimeException $ex) {
    check(!str_contains($ex->getMessage(), 'never-log-me'), 'No password in login error');
}

$refreshCount = 0;
$refresh = new WPHUB_KronotermClient('account', 'secret', static function (array $req) use (&$refreshCount): array {
    if (str_contains($req['url'], 'Menu=1')) {
        ++$refreshCount;
        return ['status' => 200, 'body' => '{"hp_id":1}'];
    }
    if ($refreshCount === 1) {
        return ['status' => 200, 'body' => '{"result":"action","js":"window.location=\"/\""}'];
    }
    return ['status' => 200, 'body' => '{"ok":true}'];
});
check($refresh->getPage('main')['ok'] === true, 'Recover once from session redirect');
check($refreshCount === 2, 'Only one reauthentication');

echo "All Kronoterm-client tests passed.\n";
