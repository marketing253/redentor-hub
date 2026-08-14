<?php
/**
 * noticias.php — notícias do portal, personalizadas por usuário.
 *
 * As fontes são as MESMAS cadastradas no TV Indoor (tvi_config →
 * 'fontes_proprias'): cadastrou lá, aparece aqui. Cada pessoa escolhe
 * em Meu Perfil quais quer ver; a escolha fica em portal_dados.
 *
 * Ações:
 *   ?a=fontes   → catálogo do TV Indoor + a escolha da pessoa
 *   ?a=salvar   → grava a escolha (POST: ids[], qtd)
 *   ?a=itens    → devolve as notícias já mescladas e ordenadas
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                              'secure' => $secure, 'samesite' => 'Lax']);
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/db_config.php';

function saida(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['uid'])) saida(['ok' => false, 'erro' => 'Sessão expirada.']);
$db = portal_db();
if (!$db) saida(['ok' => false, 'erro' => 'Sem conexão com o banco.']);

$uid = (int)$_SESSION['uid'];
$r = $db->query("SELECT username FROM portal_usuarios WHERE id=$uid LIMIT 1");
$eu = ($r && $r->num_rows) ? $r->fetch_assoc()['username'] : '';
if ($eu === '') saida(['ok' => false, 'erro' => 'Usuário não encontrado.']);

/* ---------- armazenamento (portal_dados) ---------- */
function dadoLer(mysqli $db, string $chave): ?string {
    $c = $db->real_escape_string($chave);
    $r = $db->query("SELECT valor FROM portal_dados WHERE chave='$c' LIMIT 1");
    return ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : null;
}
function dadoGravar(mysqli $db, string $chave, string $valor): void {
    $c = $db->real_escape_string($chave);
    $v = $db->real_escape_string($valor);
    $db->query("INSERT INTO portal_dados (chave,valor) VALUES ('$c','$v')
                ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
}

/* ---------- fontes cadastradas no TV Indoor ---------- */
function fontesTV(mysqli $db): array {
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='fontes_proprias' LIMIT 1");
    if (!$r || !$r->num_rows) return [];
    $l = json_decode((string)$r->fetch_assoc()['valor'], true);
    return is_array($l) ? $l : [];
}

/* ---------- leitura de RSS ---------- */
function baixar(string $url, int $limite = 400000): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RedentorHub/1.0)',
        CURLOPT_ENCODING => '',
    ]);
    $t = curl_exec($ch);
    curl_close($ch);
    return is_string($t) ? substr($t, 0, $limite) : '';
}

/** Descobre o endereço do feed a partir do endereço do site. */
function acharFeed(mysqli $db, string $url): string {
    $ch = 'rssfeed:' . md5($url);
    $g = dadoLer($db, $ch);
    if ($g !== null) {
        $j = json_decode($g, true);
        if (is_array($j) && (time() - (int)($j['t'] ?? 0)) < 86400) return (string)($j['u'] ?? '');
    }
    $achado = '';
    $txt = baixar($url, 200000);
    if ($txt !== '' && (stripos($txt, '<rss') !== false || stripos($txt, '<feed') !== false)) {
        $achado = $url;                                  // já era um feed
    } elseif ($txt !== '') {
        if (preg_match_all('#<link[^>]+>#i', $txt, $m)) {
            foreach ($m[0] as $tag) {
                if (!preg_match('#type=["\']application/(rss|atom)\+xml["\']#i', $tag)) continue;
                if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $h)) {
                    $achado = $h[1];
                    if (strpos($achado, '//') === 0) $achado = 'https:' . $achado;
                    elseif ($achado[0] === '/') {
                        $p = parse_url($url);
                        $achado = $p['scheme'] . '://' . $p['host'] . $achado;
                    }
                    break;
                }
            }
        }
    }
    if ($achado === '') {
        $base = rtrim($url, '/');
        foreach (['/feed/', '/rss', '/rss.xml', '/feed.xml', '/index.xml', '/atom.xml'] as $suf) {
            $t = baixar($base . $suf, 60000);
            if ($t !== '' && (stripos($t, '<rss') !== false || stripos($t, '<feed') !== false)) {
                $achado = $base . $suf; break;
            }
        }
    }
    dadoGravar($db, $ch, json_encode(['t' => time(), 'u' => $achado]));
    return $achado;
}

function lerFeed(mysqli $db, string $siteUrl, string $nomeFonte): array {
    $ch = 'rssitens:' . md5($siteUrl);
    $g = dadoLer($db, $ch);
    if ($g !== null) {
        $j = json_decode($g, true);
        if (is_array($j) && (time() - (int)($j['t'] ?? 0)) < 900) return (array)($j['i'] ?? []);
    }
    $feed = acharFeed($db, $siteUrl);
    $itens = [];
    if ($feed !== '') {
        $xmlTxt = baixar($feed);
        if ($xmlTxt !== '') {
            libxml_use_internal_errors(true);
            $x = simplexml_load_string($xmlTxt);
            if ($x !== false) {
                $lista = isset($x->channel->item) ? $x->channel->item
                       : (isset($x->entry) ? $x->entry : []);
                foreach ($lista as $i) {
                    $titulo = trim((string)$i->title);
                    if ($titulo === '') continue;
                    $link = trim((string)$i->link);
                    if ($link === '' && isset($i->link['href'])) $link = (string)$i->link['href'];
                    $data = (string)($i->pubDate ?? $i->updated ?? $i->published ?? '');
                    $img  = '';
                    if (isset($i->enclosure['url'])) $img = (string)$i->enclosure['url'];
                    if ($img === '') {
                        $m = $i->children('http://search.yahoo.com/mrss/');
                        if (isset($m->content['url'])) $img = (string)$m->content['url'];
                        elseif (isset($m->thumbnail['url'])) $img = (string)$m->thumbnail['url'];
                    }
                    $itens[] = [
                        'titulo' => mb_substr(html_entity_decode($titulo, ENT_QUOTES, 'UTF-8'), 0, 160),
                        'link'   => $link,
                        'quando' => $data ? (int)strtotime($data) : 0,
                        'img'    => $img,
                        'fonte'  => $nomeFonte,
                    ];
                    if (count($itens) >= 12) break;
                }
            }
            libxml_clear_errors();
        }
    }
    dadoGravar($db, $ch, json_encode(['t' => time(), 'i' => $itens], JSON_UNESCAPED_UNICODE));
    return $itens;
}

/* ---------- ações ---------- */
$a = $_GET['a'] ?? '';
$chaveEu = 'noticias_pref:' . $eu;

if ($a === 'fontes') {
    $pref = json_decode((string)dadoLer($db, $chaveEu), true);
    saida(['ok' => true, 'fontes' => fontesTV($db),
           'escolhidas' => (array)($pref['ids'] ?? []),
           'qtd' => (int)($pref['qtd'] ?? 8),
           'ligado' => !isset($pref['ligado']) || !empty($pref['ligado'])]);
}

if ($a === 'salvar') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;
    $ids = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? []))));
    $qtd = max(3, min(24, (int)($body['qtd'] ?? 8)));
    dadoGravar($db, $chaveEu, json_encode([
        'ids' => array_slice($ids, 0, 12), 'qtd' => $qtd,
        'ligado' => !empty($body['ligado']),
    ], JSON_UNESCAPED_UNICODE));
    saida(['ok' => true]);
}

if ($a === 'itens') {
    $pref  = json_decode((string)dadoLer($db, $chaveEu), true);
    if (isset($pref['ligado']) && empty($pref['ligado'])) saida(['ok' => true, 'ligado' => false, 'itens' => []]);
    $ids   = (array)($pref['ids'] ?? []);
    $qtd   = max(3, min(24, (int)($pref['qtd'] ?? 8)));
    $todas = fontesTV($db);
    if (!$ids) $ids = array_map(fn($f) => (int)$f['id'], array_slice($todas, 0, 3));

    // uma lista por fonte, cada uma já ordenada da mais nova para a mais velha
    $porFonte = [];
    foreach ($todas as $f) {
        if (!in_array((int)$f['id'], $ids, true)) continue;
        $lista = lerFeed($db, (string)$f['url'], (string)$f['nome']);
        usort($lista, fn($x, $y) => ($y['quando'] ?? 0) <=> ($x['quando'] ?? 0));
        if ($lista) $porFonte[] = $lista;
    }
    // intercala: uma de cada fonte por rodada, senão a que publica mais toma a faixa toda
    $itens = [];
    $vistos = [];
    for ($rodada = 0; count($itens) < $qtd; $rodada++) {
        $sobrou = false;
        foreach ($porFonte as $lista) {
            if (!isset($lista[$rodada])) continue;
            $sobrou = true;
            $it = $lista[$rodada];
            $chave = mb_strtolower(preg_replace('/\W+/u', '', mb_substr($it['titulo'], 0, 60)));
            if (isset($vistos[$chave])) continue;          // mesma notícia em dois sites
            $vistos[$chave] = true;
            $itens[] = $it;
            if (count($itens) >= $qtd) break;
        }
        if (!$sobrou) break;
    }
    saida(['ok' => true, 'ligado' => true, 'itens' => $itens,
           'fontes_usadas' => count($porFonte)]);
}

saida(['ok' => false, 'erro' => 'Ação desconhecida.']);
