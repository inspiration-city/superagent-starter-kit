<?php
declare(strict_types=1);

/**
 * CONTEXT — one call that assembles the live workspace context for a task.
 *
 * Every surface is read from IC at the moment it is asked for, and nothing is written
 * anywhere. This is deliberately NOT the refresh cache beside it: refresh keeps a local
 * copy of documentation that changes rarely, and this keeps no copy of anything. A
 * resident's bookmarks, memory, links, state and search results are live or they are
 * wrong, and a stale bundle is worse than no bundle because it looks current.
 *
 * Each surface is optional and its absence is reported, not fatal. A workspace with no
 * my.state pages says "none" and the rest of the bundle still arrives; a missing target leaves the other surfaces available. Authentication, transport,
 * protocol and unexpected tool failures stop the command.
 *
 * Every item names its source page, because the bundle is a pointer to IC, never a
 * replacement for it. Read the page.
 */
final class Context {
    ## Bounded on purpose: this output goes into an agent's context window, and an
    ## unbounded bundle is a bundle nobody can afford to ask for twice.
    private const MAX_ROWS = 12;          ## rows shown per surface
    private const MAX_CELL = 88;          ## characters per cell before it is cut
    ## Bookmarks are the routing table, not a result set — half a map is worse than none,
    ## and each row is one short line. It gets the whole thing.
    private const MAX_ROUTES = 40;

    public static function gather(Client $client, string $phrase, string $page): array {
        if ($page !== '' && !preg_match('/^[a-z0-9._-]+$/i', $page)) throw new RuntimeException('Invalid context page.');
        if (strlen($phrase) > 512 || strlen($page) > 128) throw new RuntimeException('Context input is too long.');
        $who = $client->request('whoami');
        if (empty($who['base']) || empty($who['userid']) || ($who['agent'] ?? '') !== $client->agent()) throw new RuntimeException('Context identity response is incomplete or mismatched.');
        $out = [
            'base' => (string)($who['base'] ?? ''),
            'user' => (string)($who['userid'] ?? ''),
            'agent' => $client->agent(),
            'phrase' => $phrase,
            'page' => $page,
            'surfaces' => [],
        ];
        $out['surfaces']['bookmarks'] = self::bookmarks($client);
        $out['surfaces']['memory'] = self::memory($client, $phrase);
        $out['surfaces']['links'] = self::links($client, $page);
        $out['surfaces']['state'] = self::state($client);
        $out['surfaces']['pages'] = self::pages($client, $phrase);
        $out['surfaces']['conversations'] = self::conversations($client, $phrase);
        foreach ($out['surfaces'] as $name => &$surface) {
            $cap = $name === 'bookmarks' ? self::MAX_ROUTES : self::MAX_ROWS;
            $surface['omitted'] = max(0, count($surface['items']) - $cap);
            $surface['items'] = array_slice($surface['items'], 0, $cap);
            foreach ($surface['items'] as &$item) foreach ($item as &$value) $value = self::cut((string)$value, self::MAX_CELL);
            unset($item, $value);
            if (isset($surface['tags'])) $surface['tags'] = self::cut((string)$surface['tags'], self::MAX_CELL);
        }
        unset($surface);
        return $out;
    }

    ## ---- surfaces ---------------------------------------------------------
    ## Each returns ['source'=>page, 'note'=>one line, 'items'=>[...]]. 'note' is what the
    ## reader sees when items is empty, and it says WHY — not configured, nothing matched,
    ## or the error the gateway gave — because those three call for different next moves.

    private static function bookmarks(Client $client): array {
        $raw = self::exec($client, '@read location="my.bookmarks"');
        if ($raw === null) return self::miss('my.bookmarks', 'not found or unavailable (missing target)');
        $rows = self::rows($raw);
        if (! $rows) return self::miss('my.bookmarks', 'not configured — no named routes yet');
        $items = [];
        foreach ($rows as $r) {
            $items[] = ['name' => $r[0] ?? '', 'where' => $r[1] ?? '', 'what' => $r[2] ?? ''];
        }
        return ['source' => 'my.bookmarks', 'note' => count($items) . ' named routes', 'items' => $items];
    }

    private static function memory(Client $client, string $phrase): array {
        $raw = self::exec($client, '@read location="my.memory"');
        if ($raw === null) return self::miss('my.memory', 'not found or unavailable (missing target)');
        $rows = self::rows($raw);
        if (! $rows) return self::miss('my.memory', 'not configured — no memories yet');
        $total = count($rows);
        ## The index is hundreds of rows on a used workspace, so without a phrase this
        ## surface is a list of everything and worth nothing. Say the size and stop.
        if ($phrase === '') return ['source' => 'my.memory', 'note' => "$total memories — give a phrase to filter", 'items' => []];
        $items = [];
        foreach ($rows as $r) {
            $key = $r[0] ?? '';
            if (! self::matches($key, $phrase)) continue;
            $items[] = ['memory' => $key, 'page' => 'local.memory.' . ($r[1] ?? ''), 'modified' => $r[3] ?? ''];
        }
        $note = $items ? count($items) . " of $total memories match" : "none of $total memories match";
        return ['source' => 'my.memory', 'note' => $note, 'items' => $items];
    }

    private static function links(Client $client, string $page): array {
        if ($page === '') return self::miss('my.links', 'give a page to see its links, tags and backlinks');
        $out = self::exec($client, '@report links="' . $page . '" template="{+page}|{+tags}"');
        $in = self::exec($client, '@report backlinks="' . $page . '" template="{+page}"');
        if ($out === null && $in === null) return self::miss('my.links', 'not found or unavailable (missing target)');
        $items = [];
        $tags = '';
        foreach (self::lines((string)$out) as $line) {
            [$target, $t] = array_pad(explode('|', $line, 2), 2, '');
            if ($target !== '') $items[] = ['direction' => 'out', 'page' => $target];
            if ($t !== '' && $tags === '') $tags = $t;
        }
        foreach (self::lines((string)$in) as $line) $items[] = ['direction' => 'in', 'page' => $line];
        $mine = self::exec($client, '@read location="my.links:' . $page . ':3"');
        $tags = trim((string)$mine) !== '' ? trim((string)$mine) : $tags;
        $note = $items || $tags !== '' ? count($items) . ' relationships' . ($tags !== '' ? ", tags: $tags" : '')
                                       : 'no links, tags or backlinks on this page';
        return ['source' => 'my.links', 'note' => $note, 'items' => $items, 'tags' => $tags];
    }

    private static function state(Client $client): array {
        $raw = self::exec($client, '@list scan="my.state" match=file count=false');
        if ($raw === null) return self::miss('my.state.*', 'not found or unavailable (missing target)');
        $items = [];
        foreach (self::lines((string)$raw) as $line) {
            if (str_starts_with($line, 'my.state')) $items[] = ['page' => $line];
        }
        if (! $items) return self::miss('my.state.*', 'not configured — nothing in progress');
        return ['source' => 'my.state.*', 'note' => count($items) . ' in progress', 'items' => $items];
    }

    private static function pages(Client $client, string $phrase): array {
        if ($phrase === '') return self::miss('search', 'give a phrase to search pages');
        $raw = self::exec($client, '@search find="' . self::quote($phrase) . '" template="{+page}" count=' . self::MAX_ROWS);
        if ($raw === null) return self::miss('search', 'search is unavailable');
        $items = [];
        foreach (self::lines((string)$raw) as $line) {
            if (str_contains($line, ' ') || $line === '') continue;   ## a prose line is a message, not a page
            $items[] = ['page' => $line];
        }
        if (! $items) return self::miss('search', 'no pages match');
        return ['source' => 'search', 'note' => count($items) . ' pages match', 'items' => $items];
    }

    private static function conversations(Client $client, string $phrase): array {
        if ($phrase === '') return self::miss('search chat', 'give a phrase to search conversations');
        $raw = self::exec($client, '@search chat="' . self::quote($phrase) . '" limit=' . self::MAX_ROWS);
        if ($raw === null) return self::miss('search chat', 'the conversation store is unavailable');
        $items = [];
        foreach (self::lines((string)$raw) as $line) {
            ## The rows come back as IC markup: - link:chats.<agent>?action=recent&id=<date>('**...**') ...
            if (! str_contains($line, 'action=recent')) continue;
            $at = strpos($line, 'link:');
            $page = substr($line, $at + 5, strpos($line, '?', $at) - $at - 5);
            $id = strpos($line, 'id=');
            $date = substr($line, $id + 3, 10);
            $items[] = ['page' => $page, 'date' => $date, 'excerpt' => self::excerpt($line)];
        }
        if (! $items) return self::miss('search chat', 'no conversations match');
        return ['source' => 'search chat', 'note' => count($items) . ' conversation days match', 'items' => $items];
    }

    ## ---- rendering --------------------------------------------------------

    public static function render(array $bundle): string {
        $head = "CONTEXT — {$bundle['base']} as {$bundle['user']} (agent: {$bundle['agent']})";
        $ask = [];
        if ($bundle['phrase'] !== '') $ask[] = 'phrase "' . $bundle['phrase'] . '"';
        if ($bundle['page'] !== '') $ask[] = 'page ' . $bundle['page'];
        $out = [$head, $ask ? implode('   ', $ask) : 'no phrase or page given — pass one for the filtered surfaces', ''];
        foreach ($bundle['surfaces'] as $name => $s) {
            $out[] = '[' . strtoupper($name) . ']  ' . $s['source'] . ' — ' . $s['note'];
            $cap = $name === 'bookmarks' ? self::MAX_ROUTES : self::MAX_ROWS;
            foreach (array_slice($s['items'], 0, $cap) as $item) {
                $cells = [];
                ## Cut each CELL, never the assembled block: flattening a block takes its row
                ## separators with it and runs every result of a surface onto one line.
                foreach ($item as $v) $cells[] = self::cut((string)$v, self::MAX_CELL);
                $out[] = '    ' . implode('   ', $cells);
            }
            if (($s['omitted'] ?? 0) > 0) $out[] = '    . ' . $s['omitted'] . ' more';
            $out[] = '';
        }
        $out[] = 'Every line above names its source page. Read the page — this bundle is a pointer, not a copy, and nothing here was cached.';
        return implode("
", $out);
    }

    ## ---- plumbing ---------------------------------------------------------

    private static function miss(string $source, string $why): array {
        return ['source' => $source, 'note' => $why, 'items' => []];
    }

    ## One surface failing is a note on that surface, never a failed bundle. A gateway
    ## error here usually means the page is not there or this agent may not read it, and
    ## both of those are answers.
    private static function exec(Client $client, string $command): ?string {
        return self::safe(function () use ($client, $command) {
            $r = $client->request('exec', ['command' => $command]);
            return (string)($r['output'] ?? $r['content'] ?? '');
        }, null);
    }

    private static function safe(callable $fn, mixed $fallback): mixed {
        try { return $fn(); }
        catch (GatewayException $e) {
            if ($e->kind !== 'missing') throw $e;
            return $fallback;
        }
    }

    private static function quote(string $v): string {
        return str_replace(['"', "\r", "\n"], ['', ' ', ' '], $v);
    }

    ## Data rows only: the +header row and any prose above the table are skipped, so a page
    ## that grows a heading or a comment line does not start emitting them as results.
    private static function rows(string $raw): array {
        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (strlen($line) < 3 || $line[0] !== '|' || $line[1] === '+' || $line[1] === '-') continue;
            $rows[] = array_map('trim', explode('|', trim($line, '|')));
        }
        return $rows;
    }

    private static function lines(string $raw): array {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    private static function matches(string $haystack, string $needle): bool {
        return stripos(str_replace('_', ' ', $haystack), str_replace('_', ' ', $needle)) !== false;
    }

    private static function excerpt(string $line): string {
        $at = strrpos($line, '//');
        return $at === false ? '' : trim(substr($line, $at + 2));
    }

    private static function cut(string $v, int $len): string {
        $v = trim(str_replace(["\r", "\n"], ' ', $v));
        if (!preg_match('//u', $v)) return '(invalid UTF-8)';
        if (strlen($v) <= $len) return $v;
        $prefix = substr($v, 0, $len - 1);
        while (!preg_match('//u', $prefix)) $prefix = substr($prefix, 0, -1);
        return $prefix . '.';
    }
}
