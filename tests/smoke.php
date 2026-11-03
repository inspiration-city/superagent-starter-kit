<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Client.php';
require $root . '/src/Knowledge.php';
require $root . '/src/Context.php';

try {
    ## --agent, same flag bin/ic takes: on a shared install one token serves every agent
    ## and the name is a label, so the test should not need an IC_AGENT= prefix of its own.
    foreach ($argv as $i => $arg) {
        if ($arg === '--agent' && isset($argv[$i + 1])) putenv('IC_AGENT=' . strtolower(trim($argv[$i + 1])));
        elseif (str_starts_with($arg, '--agent=')) putenv('IC_AGENT=' . strtolower(trim(substr($arg, 8))));
    }
    $client = Client::fromEnvironment($root);
    $who = $client->request('whoami');
    if (($who['base'] ?? '') === '' || ($who['userid'] ?? '') === '') throw new RuntimeException('whoami response is incomplete.');
    $tools = $client->request('tools');
    $catalog = (string)($tools['content'] ?? '');
    foreach (['@read', '@list', '@snapshot', '@stat', '@move', '@copy', '@untrash'] as $tool) {
        if (!str_contains($catalog, $tool)) throw new RuntimeException("Live catalog does not contain $tool.");
    }
    $skill = $client->request('exec', ['name' => 'read', 'args' => ['location' => 'local.skills.file_tools']]);
    if (!str_contains((string)($skill['output'] ?? ''), '# File Tools')) throw new RuntimeException('Could not read local.skills.file_tools.');
    ## context: a bundle always comes back, always names its sources, and never writes.
    $bundle = Context::gather($client, 'garden', 'my.space');
    foreach (['bookmarks', 'memory', 'links', 'state', 'pages', 'conversations'] as $surface) {
        if (!isset($bundle['surfaces'][$surface])) throw new RuntimeException("context is missing the $surface surface.");
        if (trim((string)($bundle['surfaces'][$surface]['source'] ?? '')) === '') throw new RuntimeException("context surface $surface names no source page.");
        if (trim((string)($bundle['surfaces'][$surface]['note'] ?? '')) === '') throw new RuntimeException("context surface $surface explains nothing when it is empty.");
    }
    if (($bundle['base'] ?? '') === '') throw new RuntimeException('context did not resolve the workspace.');
    $text = Context::render($bundle);
    foreach (['CONTEXT', '[BOOKMARKS]', '[CONVERSATIONS]'] as $marker) {
        if (!str_contains($text, $marker)) throw new RuntimeException("context output is missing $marker.");
    }
    ## A bad page is refused rather than sent, and a nonsense phrase is an empty bundle, not an error.
    $empty = Context::gather($client, 'zzqqxx_nothing_matches_here', '');
    if ($empty['surfaces']['pages']['items'] !== []) throw new RuntimeException('context invented page results for a nonsense phrase.');

    $knowledge = Knowledge::refresh($client, $root);
    foreach (['tools.md', 'skills.md', 'help.md', 'help-index.md', 'file-tools.md'] as $file) {
        if (!in_array($file, $knowledge, true) || !is_file($root . '/.superagent/knowledge/' . json_decode(file_get_contents($root . '/.superagent/knowledge/manifest.json'), true)['generation'] . '/' . $file)) throw new RuntimeException("Knowledge refresh did not create $file.");
    }
    echo "PASS: authenticated, discovered live tools, read the file-tools skill, assembled a live context bundle, and refreshed local knowledge. No IC writes performed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
