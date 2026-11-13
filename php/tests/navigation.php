<?php
declare(strict_types=1);
$AUDIT_PAGES = [];

function audit_rendered_pages(): void
{
    global $AUDIT_PAGES;
    $routesSource = (string)file_get_contents(dirname(__DIR__) . '/src/app.php');
    preg_match_all("~\\[\\s*'(GET|POST)'\\s*,\\s*'([^']+)'~", $routesSource, $matches, PREG_SET_ORDER);
    $routes = [];
    foreach ($matches as $match) {
        $pattern = preg_replace('/<int:\w+>/', '__NUMBER__', $match[2]);
        $routes[$match[1]][] = '~^' . str_replace('__NUMBER__', '[0-9]+', preg_quote($pattern, '~')) . '$~D';
    }
    ok(count($AUDIT_PAGES) >= 15 && count($routes['GET'] ?? []) >= 20, '導線検査の対象画面・ルートが揃っている');
    $scripts = [];
    $checked = [];
    foreach ($AUDIT_PAGES as $source => $html) {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        foreach (['a' => ['href', 'GET'], 'form' => ['action', 'POST']] as $tag => [$attribute, $defaultMethod]) {
            foreach ($doc->getElementsByTagName($tag) as $element) {
                $url = $element->getAttribute($attribute);
                if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) continue;
                $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
                if (str_starts_with($path, '/static/')) continue;
                $method = $tag === 'form' ? strtoupper($element->getAttribute('method') ?: 'GET') : 'GET';
                $key = $method . ' ' . $path;
                if (isset($checked[$key])) continue;
                $checked[$key] = true;
                $found = false;
                foreach ($routes[$method] ?? [] as $pattern) {
                    if (preg_match($pattern, $path)) { $found = true; break; }
                }
                ok($found, "導線 {$key} に実在する受け口がある", "画面 {$source}");
            }
        }
        $inline = [];
        foreach ($doc->getElementsByTagName('script') as $script) {
            $type = $script->getAttribute('type');
            if ($script->hasAttribute('src') || ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript'], true))) continue;
            $inline[] = $script->textContent;
        }
        if ($inline) $scripts[$source] = implode("\n;\n", $inline);
    }
    $nodeCode = 'const vm=require("node:vm");let s="";process.stdin.setEncoding("utf8");process.stdin.on("data",x=>s+=x);process.stdin.on("end",()=>{let n=0;for(const [name,code] of Object.entries(JSON.parse(s))){try{new vm.Script(code,{filename:name});n++}catch(e){console.error(name+": "+e.message);process.exitCode=1}}console.log("JavaScript構文: "+n+"画面")});';
    $proc = proc_open(['node', '-e', $nodeCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { ok(false, '全画面のJavaScript構文検査を起動'); return; }
    fwrite($pipes[0], json_encode($scripts, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($proc);
    ok($status === 0, '全画面のJavaScriptに構文エラーがない', $out . $err);
    echo '導線検査: ' . count($AUDIT_PAGES) . '画面 / ' . count($checked) . "リンク・フォーム\n" . $out;
}
