<?php
declare(strict_types=1);

class GatewayException extends RuntimeException {
    public function __construct(string $message, public readonly string $kind, int $status = 0) { parent::__construct($message, $status); }
}
final class Client {
    public function __construct(
        private readonly string $url,
        private readonly string $base,
        private readonly string $token,
        private readonly string $agent,
        private readonly string $ca = '',
        private readonly ?Closure $transport = null,
    ) {}

    public static function fromEnvironment(string $root, string $baseOverride = ''): self {
        ## The credential must be able to live OUTSIDE this directory. This kit is meant to
        ## be cloned and published, so a real token sitting in its own tree is one `git add
        ## -f`, one zip, or one stray copy away from going public with it — and a .gitignore
        ## in this repo protects nothing once the folder is a repo of its own. IC_ENV_FILE
        ## points at a config file anywhere on disk; $root/.env stays the default so a fresh
        ## clone still works out of the box.
        ## $root/.env is read first and may hold nothing but IC_ENV_FILE — a pointer at the
        ## real config elsewhere on disk. That is the shape to use on a machine where this
        ## directory is destined to be published: the tree carries a path, never a token, so
        ## a stray zip or folder copy leaks nothing. A fresh public clone that just puts its
        ## credentials straight into $root/.env keeps working, because the pointer is
        ## optional. Process environment still beats both — loadEnv never overwrites a key
        ## that is already set, so the first file to define a key wins.
        self::loadEnv($root . '/.env');
        $chained = trim((string)getenv('IC_ENV_FILE'));
        if ($chained !== '') self::loadEnv($chained);
        $url = rtrim((string)getenv('IC_URL'));
        $base = trim((string)getenv('IC_BASE'));
        $ca = trim((string)getenv('IC_CA'));
        // One token authenticates every agent on a workspace, so IC_TOKEN is the ordinary
        // form. IC_AGENT still selects a block, because a shared install can point two
        // agents at different servers — but the agent name is now a label the server
        // records, not a credential, so an agent-flagged token is no longer required.
        // IC_TOKEN_<AGENT> still wins where it is set, for an install holding tokens for
        // more than one workspace. IC_AGENT is set in .env on a single-agent install, or
        // in each process's own environment on a shared one (process environment wins).
        $agent = strtolower(trim((string)getenv('IC_AGENT')));
        $key = strtoupper($agent);
        $url = trim((string)getenv("IC_URL_$key")) ?: $url;
        $base = trim((string)getenv("IC_BASE_$key")) ?: $base;
        $ca = trim((string)getenv("IC_CA_$key")) ?: $ca;
        $token = ($agent !== '' ? trim((string)getenv("IC_TOKEN_$key")) : '') ?: trim((string)getenv('IC_TOKEN'));
        $name = $agent !== '' ? strtolower(trim((string)getenv("IC_AGENT_$key"))) : '';
        if ($name !== '') $agent = $name;
        // --base retargets one call at another workspace on the same token: the library
        // lives in a place, the boards live in a space, and one wake needs both.
        if ($baseOverride !== '') {
            if (!preg_match('/^[a-z0-9_.-]+$/', $baseOverride)) throw new RuntimeException('--base is not a valid workspace name.');
            $base = $baseOverride;
        }
        if ($url === '' || $base === '' || $agent === '' || $token === '') {
            throw new RuntimeException('Missing IC_URL, IC_BASE, IC_AGENT, or IC_TOKEN. Either copy .env.example to .env, or — if this directory must stay free of credentials — set IC_ENV_FILE to a config file outside it. One token authenticates every agent on a workspace, so IC_TOKEN is the ordinary form. IC_AGENT names the caller: set it in the config for a single-agent install, or per process on a shared one.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('IC_URL is not a valid URL.');
        // The token rides in a header, so plain http would send it in the clear; only a local server may skip TLS.
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https' && !in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1', '[::1]'], true)) throw new RuntimeException('IC_URL must be https (plain http is allowed only for localhost).');
        if (!preg_match('/^[a-z0-9_]+$/', $agent)) throw new RuntimeException('IC_AGENT is not a valid agent name.');
        return new self($url, $base, $token, $agent, $ca);
    }

    public function agent(): string {
        return $this->agent;
    }

    public function request(string $action, array $data = []): array {
        if ($this->transport !== null) return self::validateResponse(($this->transport)($action, $data), 200);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required.');
        // Agent is a claimed enabled-room label. Authentication establishes owner authority.
        $body = json_encode(array_merge(['action' => $action, 'base' => $this->base, 'agent' => $this->agent], $data), JSON_THROW_ON_ERROR);
        $curl = curl_init($this->url);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($this->ca !== '') $options[CURLOPT_CAINFO] = $this->ca;
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false) throw new GatewayException('Connection failed.', 'transport');
        try { $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException("IC returned non-JSON content (HTTP $status)."); }
        return self::validateResponse($result, $status);
    }

    public static function validateResponse(mixed $result, int $status): array {
        if (!is_array($result)) throw new GatewayException('IC response is not a JSON object.', 'protocol', $status);
        if ($status >= 400 || ($result['ok'] ?? null) !== true) {
            $kind = in_array($status, [401,403], true) ? 'auth' : ($status === 404 ? 'missing' : 'gateway');
            // Keep diagnostics classified; never echo arbitrary server text into logs. The
            // gateway's own fixed sentences are the exception: they carry no secrets and are
            // the only thing that tells an agent which setup step is missing.
            $known = (string)($result['error'] ?? '');
            if (!preg_match('/\A(?:Superagent \'[a-z0-9_]+\' is not enabled in that vault[^\n]{0,80}|Missing agent name\.[^\n]{0,120}|Invalid token|Malformed token|Missing or invalid Authorization header|Base not found: [a-z0-9_.]{1,80}|Place has no registered owner: [a-z0-9_]{1,80})\z/', $known)) $known = '';
            throw new GatewayException("IC request failed (HTTP $status)." . ($known !== '' ? " $known" : ''), $kind, $status);
        }
        $output = $result['output'] ?? $result['content'] ?? '';
        // The gateway now says so itself; the text checks below stay for older servers.
        if (($result['failed'] ?? false) === true) {
            // Only exact, fixed validation feedback from a successful tool envelope is public.
            // An engine abort reaching a tool's output trails the ~abort~ sentinel; drop it to compare.
            if (is_string($output)) $output = preg_replace('/\s*~abort~\s*\z/', '', $output);
            $known = $status === 200 && is_string($output) && in_array($output, [
                'Too many parameters. Make sure all parameters are properly put in quote marks.',
                'Missing location.',
                'The content parameter contains a ~data~ marker line. Pass visible text as content= and the data block as data=, without marker lines.',
                'The data parameter contains a ~data~ marker line. Pass the data block as data= without a marker line.',
                'No content parameter given. To clear the visible body on purpose, pass content="" explicitly.',
                'No data parameter given. Writing only the body is what @write is for — passing this tool one half would silently leave the other stale.',
                'Nothing to write. Pass the visible body as content, the data block as data, or both. To clear a half on purpose, pass it explicitly empty.',
                'Invalid page name given. Pass the page name only, such as location="notes.some.page": no spaces, no ?action= query, no structured value such as a PowerShell hashtable.',
                '@chat requires a subaction (list, read or save). To read one day: @chat read agent=NAME date=YYYY-MM-DD. save takes agent, date and a label and/or description. See library:tools.chat for syntax.',
                'No replacement made - the target page or text was not found. Read the target with @read to check its contents and access, then use the exact in= text. A whole-page target stops at the data fold: text below it needs location=page#section.',
                'Invalid @handoff respond arguments. An apostrophe or quote inside message= probably cut it short. Retry with message="your complete reply" and no extra words outside its quotes; from a shell, pass message= as its own argument, or double each apostrophe inside single quotes.',
            ], true);
            // The dispatcher's unknown-tool sentence is fixed apart from the tool name, and its
            // hint is what stops an agent guessing the same missing name again.
            if (!$known && $status === 200 && is_string($output))
                $known = (bool)preg_match('/\AUnknown shortcut: @[a-z0-9_-]{1,40}\.(?: There is no @[a-z]+ tool\.[^\n]{0,300}| Call @tools to list the tools you can use\.)\z/', $output);
            throw new GatewayException('IC tool aborted.' . ($known ? ' ' . $output : ''), 'tool');
        }
        if (is_string($output) && (preg_match('/(?:^|\n)\s*~abort~\s*(?:$|\n)/', $output) || preg_match('/\A\s*(?:Error:|Target not found:|Invalid token|Missing agent name|Unknown shortcut:)/i', $output))) {
            $kind = preg_match('/\A\s*Target not found:/i', $output) ? 'missing' : 'tool';
            throw new GatewayException('IC tool aborted.', $kind);
        }
        return $result;
    }

    private static function loadEnv(string $file): void {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (getenv($key) !== false) continue;
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') $value = substr($value, 1, -1);
            putenv($key . '=' . $value);
        }
    }
}
