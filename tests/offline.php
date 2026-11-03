<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/src/Client.php';
require $root . '/src/Knowledge.php';
require $root . '/src/Context.php';
$count = 0;
function expect(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
function rejects(callable $fn, string $label): void { try { $fn(); } catch (RuntimeException) { expect(true,$label); return; } throw new RuntimeException($label); }
function fake(Closure $handler): Client { return new Client('https://invalid.example', 'space.example', 'fixture-only', 'example', '', $handler); }
$who = ['ok'=>true,'base'=>'space.example','userid'=>'example','agent'=>'example'];
$cacheRoot = sys_get_temp_dir() . '/ic-offline-' . bin2hex(random_bytes(8));
try {
    foreach (['Error: failed', "Target not found: page\n~abort~", "prefix\n~abort~\n"] as $output) rejects(fn()=>Client::validateResponse(['ok'=>true,'output'=>$output],200),'tool abort must fail');
    foreach ([null, [], ['ok'=>false], ['ok'=>'true']] as $result) rejects(fn()=>Client::validateResponse($result,200),'invalid envelope must fail');
    expect(Client::validateResponse(['ok'=>true,'output'=>'Documentation discusses Error handling.'],200)['ok'],'prose must survive');
    try { Client::validateResponse(['ok'=>false],401); } catch (GatewayException $e) { expect($e->kind==='auth','auth classification'); }
    rejects(fn()=>Client::validateResponse(['ok'=>true,'failed'=>true,'output'=>'Any prose at all.'],200),'gateway failed flag must fail');
    $validation = [
        'Too many parameters. Make sure all parameters are properly put in quote marks.',
        'Invalid @handoff respond arguments. An apostrophe or quote inside message= probably cut it short. Retry with message="your complete reply" and no extra words outside its quotes; from a shell, pass message= as its own argument, or double each apostrophe inside single quotes.',
    ];
    foreach ($validation as $message) {
        foreach (['output','content'] as $field) {
            try { Client::validateResponse(['ok'=>true,'failed'=>true,$field=>$message],200); throw new RuntimeException('validation must reject'); }
            catch (GatewayException $e) {
                expect($e->getMessage()==='IC tool aborted. '.$message,'fixed validation feedback appended to existing CLI error');
                expect($e->kind==='tool','validation remains a tool failure');
            }
        }
        expect(Client::validateResponse(['ok'=>true,'output'=>$message],200)['output']===$message,'nonfailed prose remains successful');
        foreach ([0,201,204,302,400,401,403,404,500] as $status) {
            try { Client::validateResponse(['ok'=>true,'failed'=>true,'output'=>$message],$status); throw new RuntimeException('non-200 validation must reject'); }
            catch (GatewayException $e) { expect(!str_contains($e->getMessage(),$message),'fixed tool messages shown only for HTTP 200'); }
        }
        foreach ([false, 'true'] as $ok) {
            try { Client::validateResponse(['ok'=>$ok,'failed'=>true,'output'=>$message],200); throw new RuntimeException('invalid success envelope must reject'); }
            catch (GatewayException $e) { expect(!str_contains($e->getMessage(),$message),'invalid envelope cannot show tool validation'); }
        }
        foreach ([$message.' synthetic-secret', "synthetic-secret\n".$message, ' '.$message, $message."\n", strtolower($message)] as $modified) {
            try { Client::validateResponse(['ok'=>true,'failed'=>true,'output'=>$modified],200); throw new RuntimeException('modified validation must reject'); }
            catch (GatewayException $e) { expect($e->getMessage()==='IC tool aborted.','no prefix suffix whitespace or case-fold disclosure'); }
        }
    }
    foreach (["PHP Fatal error: synthetic-secret C:/private/path\nStack trace: fixture", 'Authorization: Bearer fixture-only-secret', ['error'=>'synthetic-secret'], 123] as $output) {
        try { Client::validateResponse(['ok'=>true,'failed'=>true,'output'=>$output],200); throw new RuntimeException('arbitrary failure must reject'); }
        catch (GatewayException $e) { expect($e->getMessage()==='IC tool aborted.','arbitrary errors paths traces and tokens stay hidden'); }
    }
    rejects(fn()=>Client::validateResponse(['ok'=>true,'output'=>'Unknown shortcut: @nope. Call @tools to list the tools you can use.'],200),'unknown tool must fail');
    try { Client::validateResponse(['ok'=>false,'error'=>"Superagent 'example' is not enabled in that vault (no local.superagent.example)"],403); } catch (GatewayException $e) { expect(str_contains($e->getMessage(),'is not enabled'),'known setup sentence is shown'); }
    try { Client::validateResponse(['ok'=>false,'error'=>'secret-token C:/private/path'],500); } catch (GatewayException $e) { expect(!str_contains($e->getMessage(),'secret'),'unknown server text stays hidden'); }
    rejects(fn()=>Context::gather(fake(fn()=>Client::validateResponse(['ok'=>false],401)),'',''),'auth stops context');
    rejects(fn()=>Context::gather(fake(fn()=>['ok'=>true]),'',''),'incomplete identity stops context');
    rejects(fn()=>Context::gather(fake(fn()=>$who),'','bad"page'),'page rejected before request');
    $missing=fake(function($action) use($who) { if($action==='whoami') return $who; throw new GatewayException('missing','missing'); });
    $bundle=Context::gather($missing,'topic','notes.example');
    expect(count($bundle['surfaces'])===6 && $bundle['base']==='space.example','missing optional surfaces tolerated');
    $rows=implode("\n",array_map(fn($i)=>'|route'.$i.'|notes.example|'.str_repeat('x',180).'|',range(1,60)));
    $bounded=fake(function($action,$data) use($who,$rows) { if($action==='whoami') return $who; return ['ok'=>true,'output'=>str_contains($data['command'],'my.bookmarks')?$rows:'']; });
    $bundle=Context::gather($bounded,'','');
    expect(count($bundle['surfaces']['bookmarks']['items'])===40,'JSON row cap');
    expect($bundle['surfaces']['bookmarks']['omitted']===20,'omission metadata');
    expect(strlen($bundle['surfaces']['bookmarks']['items'][0]['what'])<=88,'JSON cell cap: '.strlen($bundle['surfaces']['bookmarks']['items'][0]['what']));
    expect(str_contains(Context::render($bundle),'20 more'),'text omission metadata');
    $good=fake(function($action,$data) use($who) {
        if($action==='whoami') return $who;
        if($action==='tools') return ['ok'=>true,'content'=>'# Tools @read'];
        $content=match($data['command']) { '@skills'=>'# Skill Hooks |skill|hook|', '@read location="local.skills.help"'=>'# Help', '@read location="local.skills.file_tools"'=>'# File Tools', default=>'# Help index' };
        return ['ok'=>true,'output'=>$content];
    });
    $files=Knowledge::refresh($good,$cacheRoot);
    $manifestPath=$cacheRoot.'/.superagent/knowledge/manifest.json';
    $manifest=file_get_contents($manifestPath); $generation=json_decode($manifest,true)['generation'];
    expect(count($files)===5 && is_file($cacheRoot.'/.superagent/knowledge/'.$generation.'/file-tools.md'),'complete selected cache');
    $bad=fake(function($action) use($who) { return $action==='whoami'?$who:['ok'=>true,'output'=>'Target not found: missing']; });
    rejects(fn()=>Knowledge::refresh($bad,$cacheRoot),'invalid refresh rejected');
    expect(file_get_contents($manifestPath)===$manifest,'failed refresh preserves previous manifest');
    Knowledge::refresh($good,$cacheRoot);
    expect(json_decode(file_get_contents($manifestPath),true)['generation']!==$generation,'second generation published');
    $cli=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/ic');
    foreach (['','--help','help','version','--version'] as $command) {
        $output=[]; exec($cli.' '.$command.' 2>&1',$output,$status);
        expect($status===0 && !str_contains(implode("\n",$output),'Missing IC_'),'credential-free command '.$command);
    }
    // Synthetic configuration only; inspect precedence without making a request.
    $fixtureRoot=$cacheRoot.'/config'; mkdir($fixtureRoot,0700,true);
    $external=$fixtureRoot.'/external.env';
    file_put_contents($external,"IC_URL=https://invalid.example/?api=cli\nIC_BASE=space.external\nIC_AGENT=external\nIC_TOKEN=fixture-only\n");
    file_put_contents($fixtureRoot.'/.env',"IC_ENV_FILE=".$external."\nIC_BASE=space.file\n");
    foreach(['IC_URL','IC_BASE','IC_AGENT','IC_TOKEN','IC_ENV_FILE','IC_CA'] as $key) putenv($key);
    putenv('IC_AGENT=process');
    $configured=Client::fromEnvironment($fixtureRoot);
    expect($configured->agent()==='process','process agent precedence');
    $baseProperty=new ReflectionProperty(Client::class,'base');
    expect($baseProperty->getValue($configured)==='space.file','first file precedence over external');
    expect($baseProperty->getValue(Client::fromEnvironment($fixtureRoot,'place.example'))==='place.example','base override');
    putenv('IC_AGENT=bad-label');
    rejects(fn()=>Client::fromEnvironment($fixtureRoot),'gateway-compatible label validation');
    foreach(['IC_URL','IC_BASE','IC_AGENT','IC_TOKEN','IC_ENV_FILE','IC_CA'] as $key) putenv($key);
    // Help must not read even a supplied external config path.
    foreach(['--help','version','nonsense','--agent'] as $command) {
        $output=[]; exec($cli.' '.$command.' 2>&1',$output,$status);
        expect($status=== (in_array($command,['--help','version'],true)?0:2),'offline exit '.$command);
    }
    $package=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/package');
    $output=[];exec($package.' '.escapeshellarg($cacheRoot.'/export').' 2>&1',$output,$status);
    expect($status===0,'allowlisted package succeeds');
    $export=$cacheRoot.'/export';
    expect(!file_exists($export.'/.env') && !file_exists($export.'/.gstack') && !file_exists($export.'/.superagent'),'private directories excluded');
    $hashes=json_decode(file_get_contents($export.'/SHA256SUMS.json'),true);
    expect(count($hashes)===count(file($root.'/RELEASE_FILES.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)),'manifest exact allowlist');
    foreach($hashes as $file=>$hash) expect(hash_file('sha256',$export.'/'.$file)===$hash,'export hash '.$file);
    $output=[];exec($package.' '.escapeshellarg($export).' 2>&1',$output,$status);
    expect($status===1,'existing destination refused');
    echo "PASS: $count offline checks; no network or real credentials.\n";
} catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage()."\n"); exit(1); }
finally {
    // Only our random temp directory; remove its generated cache files, not arbitrary input.
    if(is_dir($cacheRoot)) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $p) { if($p->isDir()) rmdir($p->getPathname()); else unlink($p->getPathname()); }
        rmdir($cacheRoot);
    }
}
