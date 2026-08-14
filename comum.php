<?php
/* ============================================================
   comum.php — o que tvindoor.php, widget.php e player.php usam igual.

   Antes cada arquivo tinha a própria cópia de cache, busca HTTP e corte de
   texto. Três cópias significam três lugares para corrigir quando algo
   muda — e na prática só um é corrigido, e os outros passam meses errados
   sem ninguém notar. Aconteceu aqui: o cache do Instagram foi escrito de
   novo em tvindoor.php porque o do widget não estava disponível.

   Cada arquivo continua funcionando sozinho se este não existir: todos
   fazem include com @ e verificam function_exists antes de declarar. Isso
   é de propósito — arquivo compartilhado que quebra tudo quando falta é
   pior que duplicação.
   ============================================================ */

/* ── Texto com acento ─────────────────────────────────────────
   mbstring não existe em toda hospedagem. Sem plano B, nome com acento
   corta no meio de um caractere e vira lixo na tela. */
if(!function_exists('_len')){
  function _len($s){
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
  }
}

if(!function_exists('_cut')){
  function _cut($s, $i, $n){
    return function_exists('mb_substr') ? mb_substr($s, $i, $n, 'UTF-8') : substr($s, $i, $n);
  }
}

/* Tira marcação, entidade e espaço repetido de um texto qualquer. */
if(!function_exists('tvi_limpar')){
  function tvi_limpar($t){
    $t = preg_replace('#<!\[CDATA\[(.*?)\]\]>#s', '$1', (string)$t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $sem = preg_replace('/\s+/u', ' ', $t);
    return trim($sem !== null ? $sem : preg_replace('/\s+/', ' ', $t));
  }
}

/* ── Busca HTTP ───────────────────────────────────────────────
   Uma implementação, com plano B para servidor sem cURL. Antes eram
   quatro blocos quase iguais espalhados, cada um com um tempo limite
   diferente por acidente. */
if(!function_exists('tvi_http')){
  function tvi_http($url, $opcoes = array()){
    $seg    = isset($opcoes['timeout'])  ? (int)$opcoes['timeout'] : 10;
    $cab    = isset($opcoes['headers'])  ? (array)$opcoes['headers'] : array();
    /* User-Agent de navegador, não "TVIndoor/1.0".
       Vários veículos — Jovem Pan entre eles — recusam com 403 qualquer
       conexão cujo agente eles não reconhecem. O feed existe, a imagem
       está lá, e a peça ficava sem foto por causa de uma linha de
       cabeçalho. Identificar-se como navegador é o que todo leitor de RSS
       faz, e não é subterfúgio: o conteúdo é público e feito para ser
       lido assim. */
    $agente = isset($opcoes['agent']) ? $opcoes['agent']
      : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      . '(KHTML, like Gecko) Chrome/122.0 Safari/537.36 TVIndoorRedentor/1.0';
    $seguir = !isset($opcoes['follow']) || $opcoes['follow'];

    if(function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => $seg,
        CURLOPT_CONNECTTIMEOUT => min(8, $seg),
        CURLOPT_FOLLOWLOCATION => $seguir,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_USERAGENT      => $agente,
      ));
      $corpo  = curl_exec($ch);
      $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $erro   = curl_error($ch);
      curl_close($ch);

      if(!empty($opcoes['detalhe'])){
        return array('corpo' => $corpo, 'codigo' => $codigo, 'erro' => $erro);
      }
      return ($codigo && $codigo < 400) ? $corpo : null;
    }

    $ctx = stream_context_create(array('http' => array(
      'timeout'        => $seg,
      'ignore_errors'  => true,
      'follow_location'=> $seguir ? 1 : 0,
      'header'         => implode("\r\n", array_merge($cab, array('User-Agent: '.$agente))),
    )));
    $corpo = @file_get_contents($url, false, $ctx);

    if(!empty($opcoes['detalhe'])){
      $codigo = 0;
      if(isset($http_response_header[0]) &&
         preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $codigo = (int)$m[1];
      return array('corpo' => $corpo ?: '', 'codigo' => $codigo,
                   'erro' => $corpo === false ? 'falha na requisição' : '');
    }
    return $corpo ?: null;
  }
}

/* ── Cache em tabela ──────────────────────────────────────────
   Uma implementação para previsão, notícias, Instagram e futebol. Antes
   havia duas, com nomes diferentes, e a segunda nasceu só porque a
   primeira estava em outro arquivo. */
if(!function_exists('tvi_cache_ler')){
  function tvi_cache_ler($db, $chave, $ttl){
    if(!$db) return null;
    $c = $db->real_escape_string($chave);
    $r = @$db->query("SELECT valor, atualizado_em FROM tvi_cache WHERE chave='$c' LIMIT 1");
    if(!$r || !$r->num_rows) return null;
    $x = $r->fetch_assoc();
    $d = json_decode($x['valor'], true);
    if($d === null) return null;
    return array(
      'dados' => $d,
      'velho' => (time() - strtotime($x['atualizado_em'])) > $ttl,
      'idade' => time() - strtotime($x['atualizado_em']),
    );
  }
}

if(!function_exists('tvi_cache_gravar')){
  function tvi_cache_gravar($db, $chave, $dados){
    if(!$db) return;
    $c = $db->real_escape_string($chave);
    $v = $db->real_escape_string(json_encode($dados, JSON_UNESCAPED_UNICODE));
    @$db->query("INSERT INTO tvi_cache (chave,valor,atualizado_em) VALUES ('$c','$v',NOW())
                 ON DUPLICATE KEY UPDATE valor='$v', atualizado_em=NOW()");
  }
}

/* Busca com cache e queda para a última resposta boa.
   Numa parede, conteúdo de uma hora atrás é melhor que espaço vazio — e
   essa decisão estava repetida em cada extensão, com variações sutis. */
if(!function_exists('tvi_com_cache')){
  function tvi_com_cache($db, $chave, $ttl, $buscador){
    $c = tvi_cache_ler($db, $chave, $ttl);
    if($c && !$c['velho']) return $c['dados'];

    $novo = call_user_func($buscador);
    if($novo !== null && $novo !== false){
      tvi_cache_gravar($db, $chave, $novo);
      return $novo;
    }
    return $c ? $c['dados'] : null;
  }
}

/* ── Configuração ─────────────────────────────────────────── */
if(!function_exists('tvi_cfg')){
  function tvi_cfg($db, $chave, $padrao = ''){
    if(!$db) return $padrao;
    $c = $db->real_escape_string($chave);
    $r = @$db->query("SELECT valor FROM tvi_config WHERE chave='$c' LIMIT 1");
    if($r && $r->num_rows) return $r->fetch_assoc()['valor'];
    return $padrao;
  }
}

if(!function_exists('tvi_cfg_set')){
  function tvi_cfg_set($db, $chave, $valor){
    if(!$db) return;
    $c = $db->real_escape_string($chave);
    $v = $db->real_escape_string((string)$valor);
    @$db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$c','$v')
                 ON DUPLICATE KEY UPDATE valor='$v'");
  }
}

/* ── Endereço base ────────────────────────────────────────── */
if(!function_exists('tvi_base_url')){
  function tvi_base_url(){
    $p = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $p.'://'.$_SERVER['HTTP_HOST'];
  }
}

/* ── Cabeçalhos de peça de tela ───────────────────────────────
   Sinalização não pode ser guardada pelo navegador: quando o layout muda,
   precisa aparecer na parede agora. Foi o que segurou uma atualização de
   tamanho por horas. */
if(!function_exists('tvi_sem_cache')){
  function tvi_sem_cache(){
    if(headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
  }
}
