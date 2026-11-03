<?php
 declare(strict_types=1);
 final class Knowledge {
    public static function refresh(Client $client, string $root): array {
        $who = $client->request('whoami');
        if (empty($who['base']) || empty($who['userid'])) throw new RuntimeException('Incomplete refresh identity.');
        $sources = [
            'tools.md' => (string)($client->request('tools')['content'] ?? ''),
            'skills.md' => self::exec($client, '@skills'),
            'help.md' => self::exec($client, '@read location="local.skills.help"'),
            'help-index.md' => self::exec($client, '@read location="help"'),
            'file-tools.md' => self::exec($client, '@read location="local.skills.file_tools"'),
        ];
        $markers = ['tools.md'=>'@read', 'skills.md'=>'|', 'help.md'=>'# Help', 'file-tools.md'=>'# File Tools'];
        foreach ($sources as $file => $content) {
            Client::validateResponse(['ok'=>true,'output'=>$content], 200);
            if (trim($content) === '' || (isset($markers[$file]) && !str_contains($content, $markers[$file]))) throw new RuntimeException("Invalid knowledge document: $file");
        }
        $dir = $root . '/.superagent/knowledge';
        $generation = 'generation-' . bin2hex(random_bytes(8));
        $stage = $dir . '/' . $generation;
        if (!mkdir($stage, 0700, true)) throw new RuntimeException('Could not stage knowledge.');
        $manifest = ['refreshed_at'=>gmdate('c'),'base'=>$who['base'],'user'=>$who['userid'],'files'=>array_keys($sources),'generation'=>$generation];
        $temp = $dir . '/.manifest-' . $generation;
        try {
            foreach ($sources as $file => $content) self::save($stage . '/' . $file, $content . "\n");
            self::save($stage . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            self::save($temp, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            if (!rename($temp, $dir . '/manifest.json')) throw new RuntimeException('Could not publish knowledge manifest.');
        } catch (Throwable $e) {
            foreach (array_merge(array_keys($sources), ['manifest.json']) as $file) if (is_file($stage . '/' . $file)) unlink($stage . '/' . $file);
            if (is_dir($stage)) rmdir($stage);
            if (is_file($temp)) unlink($temp);
            throw $e;
        }
        return array_keys($sources);
    }
    private static function save(string $path, string $content): void {
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Could not write knowledge cache.');
    }
    private static function exec(Client $client, string $command): string {
        $result = $client->request('exec', ['command'=>$command]);
        return (string)($result['output'] ?? $result['content'] ?? '');
    }
 }
