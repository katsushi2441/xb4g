<?php
/**
 * 議員発言ログ — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code / Codex / Claude Desktop から、収録した国会発言を引くための橋。
 * 「〇〇議員はこの話題で何と言ったか」「この話題で誰が何件話しているか」を、
 * 一次情報（会議録）のURLつきで返す。
 *
 * 設置:
 *   claude mcp add giin -- php /path/to/giin_mcp.php
 *   Codex は ~/.codex/config.toml に
 *     [mcp_servers.giin]
 *     command = "php"
 *     args = ["/path/to/giin_mcp.php"]
 *
 * 設計（kdbagent・kaimom・klcrm・kjishin・kvcart と同じ約束）:
 *   - **製品本体の関数をそのまま呼ぶ薄い橋**。ここで別のロジックを作らない。
 *   - **読み取り専用。** 発言は会議録の写しなので、AIが書き換えてよいものではない。
 *   - **要約して返さない。** 抜粋と会議録URLを返し、要約するかは呼び出し側に任せる。
 *     ここで要約すると、誤りが一次情報から切り離されて独り歩きする。
 *   - notes を必ず返し、AIが件数だけ抜いて「よく発言している」と断言しないようにする。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('GIIN_MCP_VERSION', '1.0.0');

$dir = __DIR__;
foreach (['lib/db.php', 'lib/text.php', 'lib/themes.php'] as $f) {
    if (!is_file("$dir/$f")) { fwrite(STDERR, "$f が見つかりません\n"); exit(1); }
}
ob_start();
require "$dir/lib/db.php";
require "$dir/lib/text.php";
require "$dir/lib/themes.php";
ob_end_clean();

if (!function_exists('giin_db')) { fwrite(STDERR, "読み込めませんでした\n"); exit(1); }

$NOTES = [
    '件数は発言の回数であって、熱心さや正しさを表すものではありません。長い質疑ほど発言が細かく分かれます。',
    'kind は q=議員としての質疑 / gov=大臣等としての答弁 / chair=委員長としての議事整理。**既定は q だけ**です。混ぜると委員長経験者の件数が跳ね上がります。',
    '本文は会議録の写しです。**要約して断言せず、speech_url を示してください。**',
    '賛成・反対の判定はしていません。語が出てきた発言を機械的に集めたものです。',
];

function j($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
function err($m) { return [false, j(['error' => $m])]; }
function kindof($a) { $k = (string)($a['kind'] ?? 'q'); return isset(G_KINDS[$k]) ? $k : 'q'; }

// ---------------- ツール ----------------

function t_members(array $a)
{
    $w = []; $args = [];
    if (!empty($a['party']))    { $w[] = 'party LIKE ?';    $args[] = '%' . $a['party'] . '%'; }
    if (!empty($a['district'])) { $w[] = 'district LIKE ?'; $args[] = '%' . $a['district'] . '%'; }
    if (!empty($a['q']))        { $w[] = '(plain LIKE ? OR kana LIKE ?)';
                                  $args[] = '%' . $a['q'] . '%'; $args[] = '%' . $a['q'] . '%'; }
    $sql = 'SELECT slug,plain,kana,house,district,party,kaiha,wins,n_q,n_gov,n_chair,profile FROM giin'
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY n_q DESC';
    return [true, j(['members' => g_all($sql, $args), 'notes' => $GLOBALS['NOTES']])];
}

function t_speeches(array $a)
{
    $kind = kindof($a);
    $w = ['s.kind = ?']; $args = [$kind];
    if (!empty($a['member'])) {
        $g = g_one('SELECT id,plain FROM giin WHERE slug=? OR plain=?', [$a['member'], $a['member']]);
        if (!$g) { return err('その議員は収録していません: ' . $a['member']); }
        $w[] = 's.giin_id = ?'; $args[] = (int)$g['id'];
    }
    if (!empty($a['q']))    { $w[] = 's.body LIKE ?'; $args[] = '%' . $a['q'] . '%'; }
    if (!empty($a['from'])) { $w[] = 's.date >= ?';   $args[] = $a['from']; }
    if (!empty($a['until'])){ $w[] = 's.date <= ?';   $args[] = $a['until']; }
    if (!empty($a['theme'])) {
        $t = g_theme((string)$a['theme']);
        if (!$t) { return err('そのことがらはありません: ' . $a['theme']); }
        $ta = [];
        $w[] = g_words_where($t['words'], $ta);
        $args = array_merge($args, $ta);
    }
    $ws = implode(' AND ', $w);
    $total = (int)g_val("SELECT COUNT(*) FROM speech s WHERE $ws", $args);
    $limit = min(50, max(1, (int)($a['limit'] ?? 10)));
    $args[] = $limit;
    $rows = g_all("SELECT s.date,s.house,s.meeting,s.issue,s.body,s.speech_url,s.meeting_url,
                          g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
                   WHERE $ws ORDER BY s.date DESC LIMIT ?", $args);
    $out = array_map(fn($r) => [
        'date' => $r['date'], 'member' => $r['plain'], 'slug' => $r['slug'], 'party' => $r['party'],
        'house' => $r['house'], 'meeting' => $r['meeting'], 'issue' => $r['issue'],
        'excerpt' => g_excerpt($r['body'], 300),
        'speech_url' => $r['speech_url'], 'meeting_url' => $r['meeting_url'],
    ], $rows);
    return [true, j(['total' => $total, 'kind' => $kind, 'returned' => count($out),
                     'speeches' => $out, 'notes' => $GLOBALS['NOTES']])];
}

function t_who(array $a)
{
    $kind = kindof($a);
    $args = [$kind]; $w = 's.kind = ?';
    if (!empty($a['theme'])) {
        $t = g_theme((string)$a['theme']);
        if (!$t) { return err('そのことがらはありません: ' . $a['theme']); }
        $ta = []; $w .= ' AND ' . g_words_where($t['words'], $ta);
        $args = array_merge($args, $ta);
    } elseif (!empty($a['q'])) {
        $w .= ' AND s.body LIKE ?'; $args[] = '%' . $a['q'] . '%';
    } else {
        return err('theme か q のどちらかを指定してください');
    }
    $rows = g_all("SELECT g.plain,g.slug,g.party,g.district,g.house,COUNT(*) c
                   FROM speech s JOIN giin g ON g.id=s.giin_id WHERE $w
                   GROUP BY g.id ORDER BY c DESC", $args);
    return [true, j(['kind' => $kind, 'members' => $rows, 'notes' => $GLOBALS['NOTES']])];
}

function t_themes(array $a)
{
    $out = [];
    foreach (g_themes() as $t) {
        $args = []; $w = g_words_where($t['words'], $args);
        $out[] = ['slug' => $t['slug'], 'name' => $t['name'], 'lead' => $t['lead'],
                  'words' => $t['words'],
                  'n_q' => (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind='q' AND $w", $args)];
    }
    return [true, j(['themes' => $out, 'notes' => $GLOBALS['NOTES']])];
}

function t_news(array $a)
{
    $w = []; $args = [];
    if (!empty($a['theme'])) {
        $w[] = 'n.id IN (SELECT news_id FROM news_theme WHERE theme=?)'; $args[] = $a['theme'];
    }
    if (!empty($a['source'])) { $w[] = 'n.source = ?'; $args[] = $a['source']; }
    $limit = min(50, max(1, (int)($a['limit'] ?? 15)));
    $args[] = $limit;
    $rows = g_all('SELECT n.date,n.source,n.title,n.url,n.publisher,n.kind,n.submitter FROM news n'
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                . ' ORDER BY n.date DESC LIMIT ?', $args);
    return [true, j(['news' => $rows, 'notes' => [
        '国会に出された議案と、省庁の報道発表です。一般ニュースは含みません。',
        '見出しと日付と発表元だけを持っています。中身は url で確認してください。',
    ]])];
}

function t_stats(array $a)
{
    return [true, j([
        'members' => (int)g_val('SELECT COUNT(*) FROM giin'),
        'speech_total' => (int)g_val('SELECT COUNT(*) FROM speech'),
        'speech_q' => (int)g_val("SELECT COUNT(*) FROM speech WHERE kind='q'"),
        'speech_gov' => (int)g_val("SELECT COUNT(*) FROM speech WHERE kind='gov'"),
        'speech_chair' => (int)g_val("SELECT COUNT(*) FROM speech WHERE kind='chair'"),
        'range_from' => g_meta('range_from'),
        'last_speech' => g_val('SELECT MAX(date) FROM speech'),
        'updated_at' => g_meta('updated_at'),
        'news' => (int)g_val('SELECT COUNT(*) FROM news'),
        'notes' => $GLOBALS['NOTES'],
    ])];
}

$TOOLS = [
 ['name' => 'giin_members', 'description' => '収録している議員の一覧。会派・選挙区・氏名で絞れる。n_q は議員としての質疑の件数、n_gov は答弁、n_chair は議事整理。',
  'inputSchema' => ['type' => 'object', 'properties' => [
    'q' => ['type' => 'string', 'description' => '氏名・読みの一部'],
    'party' => ['type' => 'string', 'description' => '会派・政党の一部（例: 国民民主）'],
    'district' => ['type' => 'string', 'description' => '選挙区の一部（例: 愛知3、東海）'],
  ]]],
 ['name' => 'giin_speeches', 'description' => '発言を引く。議員・ことがら・語・期間で絞れる。**抜粋と会議録URLを返す。要約はしない。**',
  'inputSchema' => ['type' => 'object', 'properties' => [
    'member' => ['type' => 'string', 'description' => '議員のslugまたは氏名'],
    'theme' => ['type' => 'string', 'description' => 'ことがらのslug（giin_themes で一覧）'],
    'q' => ['type' => 'string', 'description' => '発言本文に含む語'],
    'kind' => ['type' => 'string', 'enum' => ['q', 'gov', 'chair'], 'description' => '既定 q（議員としての質疑）'],
    'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD 以降'],
    'until' => ['type' => 'string', 'description' => 'YYYY-MM-DD 以前'],
    'limit' => ['type' => 'integer', 'description' => '最大50、既定10'],
  ]]],
 ['name' => 'giin_who_talks', 'description' => 'そのことがら／語について、だれが何件発言しているかを多い順に返す。',
  'inputSchema' => ['type' => 'object', 'properties' => [
    'theme' => ['type' => 'string', 'description' => 'ことがらのslug'],
    'q' => ['type' => 'string', 'description' => '語（theme の代わり）'],
    'kind' => ['type' => 'string', 'enum' => ['q', 'gov', 'chair']],
  ]]],
 ['name' => 'giin_themes', 'description' => 'ことがらの一覧と、拾っている語と、該当する質疑の件数。',
  'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
 ['name' => 'giin_news', 'description' => '国会に出された議案と、省庁の報道発表。一般ニュースは含まない。',
  'inputSchema' => ['type' => 'object', 'properties' => [
    'theme' => ['type' => 'string'], 'source' => ['type' => 'string', 'enum' => ['gian', 'press']],
    'limit' => ['type' => 'integer'],
  ]]],
 ['name' => 'giin_stats', 'description' => '収録の規模と最終更新。',
  'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
];

function out($o) { echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; flush(); }

$in = fopen('php://stdin', 'r');
while (($line = fgets($in)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $rid = $req['id'] ?? null;
    $method = (string)($req['method'] ?? '');
    $params = $req['params'] ?? [];
    if ($rid === null && strpos($method, 'notifications/') === 0) { continue; }
    switch ($method) {
        case 'initialize':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => [
                'protocolVersion' => (string)($params['protocolVersion'] ?? '2024-11-05'),
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'giin', 'version' => GIIN_MCP_VERSION],
                'instructions' =>
                    '国会会議録から取り込んだ議員の発言を引く窓口です（読み取り専用）。'
                    . '**件数は発言の回数であって、熱心さや正しさではありません。**'
                    . '長い質疑ほど発言が細かく分かれて件数が増えます。'
                    . '**kind を混ぜないでください。** q=議員としての質疑、gov=大臣等としての答弁、'
                    . 'chair=委員長としての議事整理。既定は q です。混ぜると委員長を務めた議員の'
                    . '件数が跳ね上がり「よく質問している人」に見えます。'
                    . '**発言は会議録の写しです。要約して断言せず、speech_url を示してください。**'
                    . '賛成・反対の判定はしていません。語が出てきた発言を機械的に集めたものです。',
            ]]);
            break;
        case 'ping':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => new stdClass()]);
            break;
        case 'tools/list':
            out(['jsonrpc' => '2.0', 'id' => $rid, 'result' => ['tools' => $TOOLS]]);
            break;
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = $params['arguments'] ?? [];
            $map = ['giin_members' => 't_members', 'giin_speeches' => 't_speeches',
                    'giin_who_talks' => 't_who', 'giin_themes' => 't_themes',
                    'giin_news' => 't_news', 'giin_stats' => 't_stats'];
            try {
                if (isset($map[$name])) { [$ok, $text] = $map[$name]($args); }
                else { [$ok, $text] = err("使えないツールです: $name"); }
            } catch (Throwable $e) { [$ok, $text] = err($e->getMessage()); }
            out(['jsonrpc' => '2.0', 'id' => $rid,
                 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => !$ok]]);
            break;
        default:
            if ($rid !== null) {
                out(['jsonrpc' => '2.0', 'id' => $rid,
                     'error' => ['code' => -32601, 'message' => "未対応のメソッド: $method"]]);
            }
    }
}
