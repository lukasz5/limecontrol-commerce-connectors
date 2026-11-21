<?php
declare(strict_types=1);

// Drive the production CLI, application HTTPS client, outbox and HTTP preflight.
$base = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/shoper-feedback-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$services = [];
register_shutdown_function(static function () use (&$services, $tmp) {
    foreach ($services as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($tmp . '/*') as $file) {
        unlink($file);
    }
    rmdir($tmp);
});

function check(bool $passed, string $why): void {
    if (!$passed) {
        throw new RuntimeException($why);
    }
}
function command(array $args, array $env, bool $ok = true): string {
    $proc = proc_open($args, [1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (($code === 0) !== $ok) {
        throw new RuntimeException('Unexpected command status: ' . $code . ' ' . $error . @file_get_contents($GLOBALS['tmp'].'/server.log'));
    }
    return trim($out);
}
function launch(array $args, array $env) {
    global $services, $tmp;
    $process = proc_open($args, [0=>['file','/dev/null','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']], $unused, null, $env);
    $services[] = $process;
}
$env = array_merge(getenv(), [
    'LC_SQLITE_PATH'=>$tmp.'/db', 'LC_STORE'=>'fixture', 'LC_SIGNING_SECRET'=>str_repeat('s', 42),
    'LC_INGEST_URL'=>'https://receiver.invalid/ingest', 'SHOPER_HOST'=>'shoper.invalid', 'SHOPER_API_TOKEN'=>'fixture-token',
    'SHOPER_DELIVERED_STATUS_IDS'=>'3', 'SHOPER_CANCELLED_STATUS_IDS'=>'7', 'SHOPER_RECONCILED_STATUS_IDS'=>'5',
    'SHOPER_TIMEZONE'=>'Europe/Warsaw', 'no_proxy'=>'', 'NO_PROXY'=>'',
]);
command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',$tmp.'/key.pem',
    '-out',$tmp.'/cert.pem','-subj','/CN=shoper.invalid','-addext','subjectAltName=DNS:shoper.invalid,DNS:receiver.invalid'], $env);
launch(['python3', __DIR__ . '/mock.py', $tmp], $env);
for ($i = 0; !is_file($tmp.'/port') && $i < 150; ++$i) {
    usleep(20000);
}
check(is_file($tmp.'/port'), 'Loopback fixture unavailable: ' . file_get_contents($tmp.'/server.log'));
$env['HTTPS_PROXY'] = $env['https_proxy'] = 'http://127.0.0.1:' . trim(file_get_contents($tmp.'/port'));
$php = [PHP_BINARY,'-d','curl.cainfo='.$tmp.'/cert.pem','-d','openssl.cafile='.$tmp.'/cert.pem'];
$cli = static fn(array $args, bool $ok = true) => command([...$php,$base.'/cli.php', ...$args], $env, $ok);
$order = ['order_id'=>'101','status_id'=>'3','status_date'=>'2026-01-01 11:00:00','sum'=>'100.00','paid'=>'100.00',
    'email'=>'buyer@example.invalid','user_id'=>'12','user_order'=>'1','billing_address'=>['name'=>'PRIVATE'],'notes_priv'=>'PRIVATE'];
$rows = [];
for ($i = 1; $i <= 51; ++$i) {
    $rows[] = ['order_id'=>'101','id'=>(string)$i,'product_id'=>(string)($i+500),'stock_id'=>'9','quantity'=>'2.00',
        'name'=>"Produkt $i",'code'=>"SKU-$i",'text_options'=>[['value'=>'PRIVATE']]];
}
$orders = ['100'=>['order_id'=>'100']];
$lines = [101=>$rows];
$save = static function () use (&$orders,&$lines,$tmp) {
    file_put_contents($tmp.'/orders.json', json_encode(['orders'=>$orders,'lines'=>$lines], JSON_THROW_ON_ERROR));
};
$events = static fn() => array_map(static fn($raw)=>json_decode($raw,true,32,JSON_THROW_ON_ERROR),file($tmp.'/events.jsonl',FILE_IGNORE_NEW_LINES));
$save();
$cli(['sync']);
check(!file_exists($tmp.'/events.jsonl'), 'First poll enrolled historical orders.');
$orders[101] = $order;
$save();
$cli(['sync']);
check($events()[0]['status'] === 'pending', 'Unreconciled order became eligible.');
$inspection = json_decode($cli(['inspect','101']), true);
check(count($inspection['items']) === 51 && !isset($inspection['email']), 'Incomplete/excessive operator inspection.');
$quantities = array_fill(1, 51, 0);
$quantities[1] = 1;
$qtyJson = json_encode($quantities,JSON_THROW_ON_ERROR);
$cli(['reconcile','101',$inspection['fingerprint'],'{"1":1}','1760000000'],false);
$cli(['reconcile','101',$inspection['fingerprint'],$qtyJson,'1760000000']);
$cli(['sync']);
$eligible = $events()[1];
check($eligible['status'] === 'eligible' && count($eligible['items']) === 51
    && $eligible['customer_ref'] === '12' && $eligible['items'][0]['quantity'] === 1 && $eligible['returned_items'][0]['quantity'] === 1
    && $eligible['return_closed_at'] === 1760000000 && $eligible['delivered_at'] === 1767261600
    && !str_contains(json_encode($eligible), 'PRIVATE'), 'Wrong partial-return snapshot or privacy projection.');
$cli(['sync']);
check(count($events()) === 2, 'Unchanged polling duplicated ingestion.');
// Launch the real public entrypoint for on-demand preflight.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
check($socket !== false, 'Could not allocate public fixture port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
launch([...$php,'-S',$address,'-t',$base.'/public'], $env);
for ($i=0;$i<100;++$i) {
    $probe = @stream_socket_client('tcp://'.$address, $errno, $errstr, 0.2);
    if ($probe) { fclose($probe); break; }
    usleep(20000);
}
$preflight = static function (string $id, bool $sign = true, int $want = 200) use ($address,$env): array {
    $raw = json_encode(['version'=>1,'order_id'=>$id], JSON_THROW_ON_ERROR);
    $ts = (string)time();
    $signature = $sign ? hash_hmac('sha256',$ts.'.'.$raw,$env['LC_SIGNING_SECRET']) : 'invalid';
    $headers = ['Content-Type: application/json','X-LC-Store: fixture','X-LC-Timestamp: '.$ts,'X-LC-Signature: '.$signature];
    $curl = curl_init('http://'.$address.'/index.php');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $all = curl_exec($curl);
    $code = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    check(is_string($all) && $code === $want, 'Unexpected public preflight HTTP '.$code);
    [$meta,$body] = explode("\r\n\r\n",$all,2);
    check(stripos($meta,'cache-control: no-store') !== false,'Missing cache control.');
    if ($code === 200) {
        preg_match('/^X-LC-Timestamp: (\d+)/mi',$meta,$m);
        check(stripos($meta, 'X-LC-Signature: '.hash_hmac('sha256',$m[1].'.'.$body,$env['LC_SIGNING_SECRET'])) !== false,
            'Invalid signed public readback.');
    }
    return json_decode($body,true,32,JSON_THROW_ON_ERROR);
};
$preflight('101',false,401);
$preflight('100',true,404);
check($preflight('101')['revision'] === $eligible['revision'], 'Duplicate revision changed.');
$lines[101][0]['quantity'] = '4';
$save();
check($preflight('101')['status'] === 'pending', 'Changed lines reused previous evidence.');
$cli(['reconcile','101',$inspection['fingerprint'],$qtyJson,'1760000000'],false);
$orders[101]['status_id'] = '5';
$orders[102] = array_replace($order,['order_id'=>'102','status_id'=>'5','user_order'=>'0']);
$lines[102] = [array_replace($rows[1], ['order_id'=>'102'])];
$save();
$cli(['sync']);
check($preflight('101')['status'] === 'pending','Special status bypassed a stale manual decision.');
$guest = $preflight('102');
check($guest['status'] === 'eligible' && !isset($guest['customer_ref']), 'Automatic guest order or account isolation failed.');
$orders[102]['status_id'] = '9';
$save();
check($preflight('102')['status'] === 'pending', 'Return-in-progress was not stopped before the next poll.');
$cli(['hold','102']);
$orders[102]['status_id'] = '5';
$save();
check($preflight('102')['status'] === 'pending', 'Special status bypassed an operator hold.');
$autoInspect = json_decode($cli(['inspect','102']),true);
$cli(['reconcile','102',$autoInspect['fingerprint'],'{"2":2}','1760000000']);
check($preflight('102')['status'] === 'refunded','Full return incorrectly qualified for a product invitation.');
$orders[102]['status_id'] = '7';
$save();
check($preflight('102')['status'] === 'cancelled', 'Cancellation missing.');
$orders[102]['status_id'] = '5';
$lines[102][0]['order_id'] = '101';
$save();
$preflight('102',true,503);
echo "PASS Shoper CLI, TLS REST pagination, signed ingestion and HTTP preflight, verified line returns, status automation, untracked orders and isolation\n";
