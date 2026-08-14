<?php
/**
 * rss_urbs.php — transforma a página de Boletins do Transporte da URBS
 * num feed RSS, para o TV Indoor (e o portal) poderem consumir.
 *
 * Endereço para cadastrar como fonte de notícias:
 *   https://SEUDOMINIO/rss_urbs.php
 *
 * Por que existe: a URBS publica os boletins numa página comum, sem feed.
 * Sem isto, avisar nas TVs sobre desvio de itinerário dependeria de alguém
 * abrir o site e copiar à mão.
 *
 * Cuidados que valem a leitura:
 *  - A página é lida no máximo a cada 15 minutos e fica guardada. Uma TV
 *    que troca de peça a cada 20 segundos não pode bater no site da
 *    prefeitura o tempo todo.
 *  - Se a URBS estiver fora do ar, o feed devolve a última versão boa em
 *    vez de um erro: numa parede, boletim de uma hora atrás vale mais que
 *    tela vazia.
 *  - A data de publicação não aparece na listagem, então guardamos quando
 *    cada boletim foi visto pela primeira vez e usamos isso.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

const URBS_PAGINA   = 'https://www.urbs.curitiba.pr.gov.br/portal/boletins-do-transporte/';
const URBS_MINUTOS  = 15;      // tempo que a leitura fica guardada
const URBS_MAX      = 15;      // boletins no feed

/* Servidor sem a extensão mbstring: as funções mb_* somem e o arquivo
   quebra com "função indefinida". Já aconteceu antes neste portal. */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = null) { return strlen((string)$s); }
    function mb_substr($s, $i, $l = null, $e = null) {
        return $l === null ? substr((string)$s, $i) : substr((string)$s, $i, $l);
    }
}

require_once __DIR__ . '/db_config.php';
$db = function_exists('portal_db') ? portal_db() : null;
if ($db) $db->set_charset('utf8mb4');

/* ---------- guarda-tudo simples, em tabela própria ---------- */
function urbsTabelas(?mysqli $db): void {
    if (!$db) return;
    $db->query("CREATE TABLE IF NOT EXISTS urbs_cache (
        chave VARCHAR(60) PRIMARY KEY,
        valor MEDIUMTEXT,
        em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS urbs_boletins (
        id INT PRIMARY KEY,
        visto_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function urbsLer(?mysqli $db, string $k, int $segundos): ?string {
    if (!$db) return null;
    $k = $db->real_escape_string($k);
    $r = $db->query("SELECT valor FROM urbs_cache WHERE chave='$k'
                     AND em > DATE_SUB(NOW(), INTERVAL $segundos SECOND)");
    return ($r && $r->num_rows) ? (string)$r->fetch_assoc()['valor'] : null;
}
function urbsGravar(?mysqli $db, string $k, string $v): void {
    if (!$db) return;
    $k = $db->real_escape_string($k); $v = $db->real_escape_string($v);
    $db->query("INSERT INTO urbs_cache (chave,valor,em) VALUES ('$k','$v',NOW())
                ON DUPLICATE KEY UPDATE valor=VALUES(valor), em=NOW()");
}

/* ---------- busca a página ---------- */
$URBS_DIAG = [];

/**
 * Busca a página imitando um navegador.
 *
 * A URBS devolvia 403 para a nossa requisição: o site recusa acesso que
 * não pareça alguém navegando. Isso é proteção contra robôs, e a leitura
 * aqui é legítima — uma consulta a cada 15 minutos a uma página pública,
 * para reproduzir um aviso de serviço nas TVs. Então mandamos os mesmos
 * cabeçalhos que um Chrome manda, guardamos cookies entre as tentativas
 * (muitos filtros exigem isso) e tentamos mais de um caminho antes de
 * desistir.
 */
function urbsTentar(string $url, array $extra = []): array {
    global $URBS_DIAG;
    if (!function_exists('curl_init')) return ['', 0, 'sem curl'];

    $cookies = sys_get_temp_dir() . '/urbs-cookies.txt';
    $cab = array_merge([
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
        'Cache-Control: max-age=0',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ], $extra);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ]);
    $html = curl_exec($ch);
    $cod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [is_string($html) ? $html : '', $cod, $err];
}

function urbsBaixar(string $url): string {
    global $URBS_DIAG;
    $tentativas = [
        ['nome' => 'navegador',           'url' => $url, 'extra' => []],
        /* Alguns filtros liberam quando existe um Referer do próprio site,
           porque isso indica navegação e não acesso direto. */
        ['nome' => 'com referência',      'url' => $url,
         'extra' => ['Referer: https://www.urbs.curitiba.pr.gov.br/portal/']],
        /* E outros bloqueiam por causa do "www". */
        ['nome' => 'sem www',
         'url'  => str_replace('://www.', '://', $url), 'extra' => []],
    ];

    foreach ($tentativas as $t) {
        [$html, $cod, $err] = urbsTentar($t['url'], $t['extra']);
        $URBS_DIAG['tentativas'][] = [
            'como'  => $t['nome'],
            'http'  => $cod,
            'bytes' => strlen($html),
            'erro'  => $err,
            'achou' => (stripos($html, 'boletim/?id=') !== false),
        ];
        if ($cod === 200 && stripos($html, 'boletim/?id=') !== false) {
            $URBS_DIAG['funcionou'] = $t['nome'];
            return $html;
        }
        /* Guarda o começo da recusa: é o que diz QUEM está bloqueando. */
        if ($cod !== 200 && empty($URBS_DIAG['recusa'])) {
            $URBS_DIAG['recusa'] = trim(preg_replace('/\s+/', ' ',
                                   strip_tags(substr($html, 0, 600))));
        }
    }
    return '';
}

/**
 * Tira os boletins do HTML.
 *
 * Cada boletim aparece três vezes na página: a imagem, o título e o
 * "Leia Mais" — todos apontando para o mesmo endereço. Em vez de depender
 * das classes do tema (que mudam a cada redesenho do site), agrupamos por
 * número do boletim e ficamos com o texto mais longo, que é sempre o
 * título. O resumo é o texto solto entre o título e o "Leia Mais".
 */
function urbsExtrair(string $html): array {
    if ($html === '') return [];
    $itens = [];

    $achou = preg_match_all(
        '#<a[^>]+href="([^"]*boletins-do-transporte/boletim/\?id=(\d+))"[^>]*>(.*?)</a>#is',
        $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    if (!$achou) return [];

    $porId = [];
    foreach ($m as $x) {
        $link  = html_entity_decode($x[1][0], ENT_QUOTES, 'UTF-8');
        /* O site mistura endereço completo e caminho relativo. Sem
           completar, o link do feed não abre em lugar nenhum. */
        if (strpos($link, '//') === 0)      $link = 'https:' . $link;
        elseif (strpos($link, '/') === 0)   $link = 'https://www.urbs.curitiba.pr.gov.br' . $link;
        elseif (strpos($link, 'http') !== 0) $link = 'https://www.urbs.curitiba.pr.gov.br/' . ltrim($link, '/');
        $id    = (int)$x[2][0];
        $texto = trim(html_entity_decode(strip_tags($x[3][0]), ENT_QUOTES, 'UTF-8'));
        $texto = preg_replace('/\s+/u', ' ', (string)$texto);
        $fim   = $x[0][1] + strlen($x[0][0]);

        if (!isset($porId[$id])) {
            $porId[$id] = ['id' => $id, 'link' => $link, 'titulo' => '', 'resumo' => '', 'ordem' => count($porId)];
        }
        /* Título: o texto mais longo entre as três ocorrências. */
        if (mb_strlen($texto) > mb_strlen($porId[$id]['titulo']) && strcasecmp($texto, 'Leia Mais') !== 0) {
            $porId[$id]['titulo'] = $texto;
            $porId[$id]['fimTitulo'] = $fim;
        }
    }

    /* Resumo: o que vem depois do título e antes do próximo link. */
    foreach ($porId as $id => &$b) {
        if (empty($b['fimTitulo'])) continue;
        $trecho = substr($html, $b['fimTitulo'], 1200);
        $corte  = stripos($trecho, '<a ');
        if ($corte !== false) $trecho = substr($trecho, 0, $corte);
        $txt = trim(preg_replace('/\s+/u', ' ',
               html_entity_decode(strip_tags($trecho), ENT_QUOTES, 'UTF-8')));
        $txt = trim($txt, " \t\n\r\0\x0B–-|»");
        if (mb_strlen($txt) > 20) $b['resumo'] = mb_substr($txt, 0, 400);
    }
    unset($b);

    foreach ($porId as $b) {
        if ($b['titulo'] === '') continue;
        $itens[] = ['id' => $b['id'], 'link' => $b['link'],
                    'titulo' => $b['titulo'], 'resumo' => $b['resumo']];
    }
    /* Boletim mais novo tem número maior. */
    usort($itens, fn($a, $c) => $c['id'] <=> $a['id']);
    return array_slice($itens, 0, URBS_MAX);
}

/** Quando cada boletim foi visto pela primeira vez — vira a data do item. */
function urbsDatas(?mysqli $db, array $itens): array {
    if (!$db || !$itens) return [];
    $ids = implode(',', array_map(fn($i) => (int)$i['id'], $itens));
    $datas = [];
    $r = $db->query("SELECT id, visto_em FROM urbs_boletins WHERE id IN ($ids)");
    while ($r && $x = $r->fetch_assoc()) $datas[(int)$x['id']] = (string)$x['visto_em'];
    $novos = [];
    foreach ($itens as $i) if (!isset($datas[(int)$i['id']])) $novos[] = (int)$i['id'];
    if ($novos) {
        $vals = implode(',', array_map(fn($n) => "($n, NOW())", $novos));
        $db->query("INSERT IGNORE INTO urbs_boletins (id, visto_em) VALUES $vals");
        foreach ($novos as $n) $datas[$n] = date('Y-m-d H:i:s');
    }
    return $datas;
}

/* ---------- monta o feed ---------- */
urbsTabelas($db);

$html = urbsLer($db, 'pagina', URBS_MINUTOS * 60);
if ($html === null) {
    $baixado = urbsBaixar(URBS_PAGINA);
    if ($baixado !== '' && stripos($baixado, 'boletim/?id=') !== false) {
        $html = $baixado;
        urbsGravar($db, 'pagina', $html);
    } else {
        /* URBS fora do ar: usa a última leitura boa, de qualquer idade. */
        $html = urbsLer($db, 'pagina', 999999999) ?? '';
    }
}

$itens = urbsExtrair($html);
$datas = urbsDatas($db, $itens);

/* ?diag=1 — por que o feed veio vazio.
   Sem isto, "sem notícias aproveitáveis" pode ser: servidor sem saída para
   a internet, URBS recusando o acesso, ou a página tendo mudado de
   formato. São três consertos diferentes. */
if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DIAGNÓSTICO DO FEED DA URBS\n";
    echo str_repeat('=', 46) . "\n\n";
    echo "Página: " . URBS_PAGINA . "\n";
    echo "Banco de dados: " . ($db ? 'conectado (cache ligado)' : 'SEM CONEXÃO — sem cache') . "\n";
    echo "curl no servidor: " . (function_exists('curl_init') ? 'sim' : 'NÃO — sem ele não há como buscar') . "\n\n";

    if (!empty($URBS_DIAG['tentativas'])) {
        echo "Tentativas de busca agora:\n";
        foreach ($URBS_DIAG['tentativas'] as $t) {
            printf("  %-16s HTTP %-4s %8s bytes  %s%s\n",
                $t['como'], $t['http'] ?: '-',
                number_format($t['bytes'], 0, ',', '.'),
                $t['achou'] ? 'com boletins' : 'sem boletins',
                $t['erro'] ? '  (' . $t['erro'] . ')' : '');
        }
        if (!empty($URBS_DIAG['funcionou'])) {
            echo "\n  Funcionou com: " . $URBS_DIAG['funcionou'] . "\n";
        }
        if (!empty($URBS_DIAG['recusa'])) {
            echo "\n  O que a URBS respondeu ao recusar:\n  \"" .
                 substr($URBS_DIAG['recusa'], 0, 300) . "\"\n";
        }
    } else {
        echo "Não buscou agora: usou a leitura guardada (isso é normal, vale 15 min).\n";
    }

    echo "\nHTML em mãos: " . number_format(strlen($html), 0, ',', '.') . " bytes\n";
    $links = preg_match_all('#boletins-do-transporte/boletim/\?id=(\d+)#', $html, $mm);
    echo "Links de boletim encontrados no HTML: " . (int)$links . "\n";
    echo "Boletins extraídos: " . count($itens) . "\n\n";

    if ($itens) {
        echo "Primeiros títulos:\n";
        foreach (array_slice($itens, 0, 3) as $i) echo "  · " . $i['titulo'] . "\n";
        echo "\nO feed está funcionando. Se a TV continua sem notícias, o problema\n"
           . "está no cadastro da fonte no TV Indoor, não aqui.\n";
    } elseif (strlen($html) === 0) {
        $cods = array_column($URBS_DIAG['tentativas'] ?? [], 'http');
        if (in_array(403, $cods, true)) {
            echo "A URBS recusou o acesso (403) em todas as tentativas.\n\n"
               . "O site tem proteção contra leitura automática e nem imitando um\n"
               . "navegador passamos. Não há o que ajustar aqui do lado do código.\n\n"
               . "Caminhos possíveis:\n"
               . "  1. Pedir à URBS a liberação do IP do servidor, ou perguntar se\n"
               . "     existe um endereço oficial de dados dos boletins.\n"
               . "  2. Usar a peça de aviso do próprio TV Indoor para os boletins\n"
               . "     que interessam às nossas linhas, publicados à mão.\n";
        } else {
            echo "O servidor não conseguiu baixar a página da URBS.\n"
               . "Causas comuns: a hospedagem bloqueia saída para a internet, ou a\n"
               . "URBS recusa acesso vindo de servidor.\n";
        }
    } elseif ($links === 0) {
        echo "A página foi baixada, mas não tem nenhum link de boletim.\n"
           . "Provavelmente a URBS mudou o endereço ou o formato da listagem,\n"
           . "ou devolveu uma página de bloqueio. Começo do que veio:\n\n"
           . substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 400) . "\n";
    } else {
        echo "Há links de boletim, mas nenhum título foi extraído — o formato\n"
           . "da página mudou. Isso tem conserto rápido.\n";
    }
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=600');

function xml(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
  <title>URBS · Boletins do Transporte</title>
  <link><?php echo xml(URBS_PAGINA); ?></link>
  <description>Desvios de itinerário, alterações de horário e avisos das linhas de Curitiba. Leitura da página oficial da URBS, atualizada a cada <?php echo URBS_MINUTOS; ?> minutos.</description>
  <language>pt-BR</language>
  <lastBuildDate><?php echo date('r'); ?></lastBuildDate>
<?php if (!$itens): ?>
  <!-- Sem itens: página fora do ar ou mudou de formato. O feed continua
       válido e vazio, e quem consome trata como "sem notícias" em vez de
       quebrar. -->
<?php endif; ?>
<?php foreach ($itens as $i):
    $quando = $datas[(int)$i['id']] ?? date('Y-m-d H:i:s');
?>
  <item>
    <title><?php echo xml($i['titulo']); ?></title>
    <link><?php echo xml($i['link']); ?></link>
    <guid isPermaLink="true"><?php echo xml($i['link']); ?></guid>
    <pubDate><?php echo date('r', strtotime($quando)); ?></pubDate>
    <description><?php echo xml($i['resumo'] ?: $i['titulo']); ?></description>
  </item>
<?php endforeach; ?>
</channel>
</rss>
