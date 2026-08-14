<?php
/* ============================================================
   qr.php — gerador de QR code em PHP puro, sem biblioteca.

   Existe porque a alternativa seria chamar uma API de fora para desenhar
   um quadrado preto e branco: a parede pararia de mostrar o código no dia
   em que aquele serviço saísse do ar ou passasse a cobrar, e o endereço
   do Instagram da empresa estaria sendo enviado para um terceiro a cada
   exibição. Duzentas linhas resolvem isso de vez.

   Escopo deliberadamente estreito: modo byte, correção M, versões 1 a 10
   (até ~150 caracteres). É o suficiente para uma URL de perfil, e evitar
   o caso geral é o que mantém o arquivo legível.

   Uso:  echo qr_svg('https://instagram.com/avredentor_oficial', 8);
   ============================================================ */

/* ── Tabelas do padrão ISO/IEC 18004 ─────────────────────────
   Estes números não são escolha de implementação: vêm da norma. */

// Capacidade em bytes por versão, correção M
$QR_CAP_M = array(1=>14, 2=>26, 3=>42, 4=>62, 5=>84, 6=>106, 7=>122, 8=>152, 9=>180, 10=>213);

// Total de códigos (dados + correção) por versão
$QR_TOTAL = array(1=>26, 2=>44, 3=>70, 4=>100, 5=>134, 6=>172, 7=>196, 8=>242, 9=>292, 10=>346);

// Códigos de dados por versão, correção M
$QR_DADOS_M = array(1=>16, 2=>28, 3=>44, 4=>64, 5=>86, 6=>108, 7=>124, 8=>154, 9=>182, 10=>216);

// Blocos de correção por versão, correção M
$QR_BLOCOS_M = array(1=>1, 2=>1, 3=>1, 4=>2, 5=>2, 6=>4, 7=>4, 8=>4, 9=>5, 10=>5);

// Posição dos padrões de alinhamento por versão
$QR_ALINHA = array(
  1=>array(), 2=>array(6,18), 3=>array(6,22), 4=>array(6,26), 5=>array(6,30),
  6=>array(6,34), 7=>array(6,22,38), 8=>array(6,24,42), 9=>array(6,26,46), 10=>array(6,28,50),
);

/* ── Aritmética em campo de Galois GF(256) ───────────────────
   A correção de erro do QR é Reed-Solomon, que opera num corpo finito:
   multiplicar é somar expoentes numa tabela, não multiplicar de verdade. */
function qr_gf_tabelas(){
  static $exp = null, $log = null;
  if($exp !== null) return array($exp, $log);
  $exp = array_fill(0, 512, 0);
  $log = array_fill(0, 256, 0);
  $x = 1;
  for($i = 0; $i < 255; $i++){
    $exp[$i] = $x;
    $log[$x] = $i;
    $x <<= 1;
    if($x & 0x100) $x ^= 0x11D;         // polinômio primitivo do padrão
  }
  for($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
  return array($exp, $log);
}

function qr_gf_mul($a, $b){
  if($a === 0 || $b === 0) return 0;
  list($exp, $log) = qr_gf_tabelas();
  return $exp[$log[$a] + $log[$b]];
}

/* Polinômio gerador de grau $n. */
function qr_gerador($n){
  $g = array(1);
  list($exp, $log) = qr_gf_tabelas();
  for($i = 0; $i < $n; $i++){
    $novo = array_fill(0, count($g) + 1, 0);
    foreach($g as $k => $c){
      $novo[$k]     ^= qr_gf_mul($c, 1);
      $novo[$k + 1] ^= qr_gf_mul($c, $exp[$i]);
    }
    $g = $novo;
  }
  return $g;
}

/* Códigos de correção de um bloco de dados. */
function qr_correcao($dados, $n){
  $g = qr_gerador($n);
  $res = array_merge($dados, array_fill(0, $n, 0));
  for($i = 0; $i < count($dados); $i++){
    $c = $res[$i];
    if($c === 0) continue;
    for($j = 0; $j < count($g); $j++){
      $res[$i + $j] ^= qr_gf_mul($g[$j], $c);
    }
  }
  return array_slice($res, count($dados));
}

/* ── Montagem dos bits ───────────────────────────────────────── */
function qr_bits($texto, $versao){
  $bits = '';
  $bits .= '0100';                                    // modo byte
  $tam = $versao < 10 ? 8 : 16;                       // tamanho do contador
  $bits .= str_pad(decbin(strlen($texto)), $tam, '0', STR_PAD_LEFT);
  for($i = 0; $i < strlen($texto); $i++){
    $bits .= str_pad(decbin(ord($texto[$i])), 8, '0', STR_PAD_LEFT);
  }
  return $bits;
}

/* ── Matriz ───────────────────────────────────────────────────── */
function qr_nova_matriz($n){
  $m = array();
  for($i = 0; $i < $n; $i++) $m[] = array_fill(0, $n, null);   // null = livre
  return $m;
}

function qr_por_localizador(&$m, $lin, $col){
  $n = count($m);
  for($r = -1; $r <= 7; $r++){
    for($c = -1; $c <= 7; $c++){
      $y = $lin + $r; $x = $col + $c;
      if($y < 0 || $y >= $n || $x < 0 || $x >= $n) continue;
      $borda  = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
             || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
      $centro = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
      $m[$y][$x] = ($borda || $centro) ? 1 : 0;
    }
  }
}

function qr_por_alinhamento(&$m, $versao){
  global $QR_ALINHA;
  $pos = $QR_ALINHA[$versao];
  $n = count($m);
  foreach($pos as $ly){
    foreach($pos as $lx){
      // não sobrepõe os localizadores
      if(($ly <= 8 && $lx <= 8) || ($ly <= 8 && $lx >= $n - 9) || ($ly >= $n - 9 && $lx <= 8)) continue;
      for($r = -2; $r <= 2; $r++){
        for($c = -2; $c <= 2; $c++){
          $borda  = (abs($r) === 2 || abs($c) === 2);
          $centro = ($r === 0 && $c === 0);
          $m[$ly + $r][$lx + $c] = ($borda || $centro) ? 1 : 0;
        }
      }
    }
  }
}

function qr_por_temporizacao(&$m){
  $n = count($m);
  for($i = 8; $i < $n - 8; $i++){
    $v = ($i % 2 === 0) ? 1 : 0;
    if($m[6][$i] === null) $m[6][$i] = $v;
    if($m[$i][6] === null) $m[$i][6] = $v;
  }
}

/* Reserva as áreas de formato, que são preenchidas depois. */
function qr_reserva_formato(&$m){
  $n = count($m);
  for($i = 0; $i < 9; $i++){
    if($m[8][$i] === null) $m[8][$i] = 2;
    if($m[$i][8] === null) $m[$i][8] = 2;
  }
  for($i = 0; $i < 8; $i++){
    if($m[8][$n - 1 - $i] === null) $m[8][$n - 1 - $i] = 2;
    if($m[$n - 1 - $i][8] === null) $m[$n - 1 - $i][8] = 2;
  }
  $m[$n - 8][8] = 1;                    // módulo escuro, sempre 1
}

/* Máscara 0: a mais simples do padrão, e suficiente aqui. */
function qr_mascara($lin, $col){
  return (($lin + $col) % 2 === 0);
}

function qr_preenche(&$m, $bits){
  $n = count($m);
  $i = 0;
  $total = strlen($bits);
  $col = $n - 1;
  $subindo = true;

  while($col > 0){
    if($col === 6) $col--;              // pula a coluna de temporização
    for($k = 0; $k < $n; $k++){
      $lin = $subindo ? ($n - 1 - $k) : $k;
      for($d = 0; $d < 2; $d++){
        $c = $col - $d;
        if($m[$lin][$c] !== null) continue;
        $bit = ($i < $total) ? (int)$bits[$i] : 0;
        $i++;
        if(qr_mascara($lin, $c)) $bit ^= 1;
        $m[$lin][$c] = $bit;
      }
    }
    $col -= 2;
    $subindo = !$subindo;
  }
}

/* Informação de formato: correção M com máscara 0.
   O valor abaixo já vem com BCH e XOR aplicados, conforme a norma. */
function qr_formato(&$m){
  $bits = '101010000010010';
  $n = count($m);
  for($i = 0; $i <= 5; $i++)  $m[8][$i] = (int)$bits[$i];
  $m[8][7] = (int)$bits[6];
  $m[8][8] = (int)$bits[7];
  $m[7][8] = (int)$bits[8];
  for($i = 9; $i <= 14; $i++) $m[14 - $i][8] = (int)$bits[$i];

  for($i = 0; $i <= 7; $i++)  $m[$n - 1 - $i][8] = (int)$bits[$i];
  for($i = 8; $i <= 14; $i++) $m[8][$n - 15 + $i] = (int)$bits[$i];
}

/* ── Função principal ─────────────────────────────────────────
   Devolve a matriz de módulos, ou null se o texto não couber. */
function qr_matriz($texto){
  global $QR_CAP_M, $QR_TOTAL, $QR_DADOS_M, $QR_BLOCOS_M;

  $versao = 0;
  foreach($QR_CAP_M as $v => $cap){
    if(strlen($texto) <= $cap){ $versao = $v; break; }
  }
  if(!$versao) return null;             // longo demais para este escopo

  $nDados  = $QR_DADOS_M[$versao];
  $nBlocos = $QR_BLOCOS_M[$versao];
  $nEc     = (int)(($QR_TOTAL[$versao] - $nDados) / $nBlocos);

  // bits de dados, com terminador e preenchimento
  $bits = qr_bits($texto, $versao);
  $bits .= str_repeat('0', min(4, $nDados * 8 - strlen($bits)));
  while(strlen($bits) % 8 !== 0) $bits .= '0';
  $pad = array('11101100', '00010001');
  $p = 0;
  while(strlen($bits) < $nDados * 8){ $bits .= $pad[$p % 2]; $p++; }

  // bytes
  $bytes = array();
  for($i = 0; $i < strlen($bits); $i += 8) $bytes[] = bindec(substr($bits, $i, 8));

  // divide em blocos e calcula a correção de cada um
  $porBloco = (int)($nDados / $nBlocos);
  $resto    = $nDados % $nBlocos;
  $blocos = array(); $ecs = array(); $off = 0;
  for($b = 0; $b < $nBlocos; $b++){
    $tam = $porBloco + ($b >= $nBlocos - $resto ? 1 : 0);
    $bl = array_slice($bytes, $off, $tam);
    $off += $tam;
    $blocos[] = $bl;
    $ecs[]    = qr_correcao($bl, $nEc);
  }

  // intercala, como manda o padrão
  $saida = array();
  $maior = 0;
  foreach($blocos as $b) $maior = max($maior, count($b));
  for($i = 0; $i < $maior; $i++){
    foreach($blocos as $b) if(isset($b[$i])) $saida[] = $b[$i];
  }
  for($i = 0; $i < $nEc; $i++){
    foreach($ecs as $e) if(isset($e[$i])) $saida[] = $e[$i];
  }

  $bitsFinal = '';
  foreach($saida as $by) $bitsFinal .= str_pad(decbin($by), 8, '0', STR_PAD_LEFT);

  // monta a matriz
  $n = 17 + 4 * $versao;
  $m = qr_nova_matriz($n);
  qr_por_localizador($m, 0, 0);
  qr_por_localizador($m, 0, $n - 7);
  qr_por_localizador($m, $n - 7, 0);
  qr_por_alinhamento($m, $versao);
  qr_por_temporizacao($m);
  qr_reserva_formato($m);

  // limpa as reservas antes de preencher os dados
  for($y = 0; $y < $n; $y++)
    for($x = 0; $x < $n; $x++)
      if($m[$y][$x] === 2) $m[$y][$x] = null;

  // marca de novo como ocupado, sem valor, para o preenchimento pular
  $reserva = qr_nova_matriz($n);
  qr_por_localizador($reserva, 0, 0);
  qr_por_localizador($reserva, 0, $n - 7);
  qr_por_localizador($reserva, $n - 7, 0);
  qr_por_alinhamento($reserva, $versao);
  qr_por_temporizacao($reserva);
  qr_reserva_formato($reserva);
  for($y = 0; $y < $n; $y++)
    for($x = 0; $x < $n; $x++)
      if($reserva[$y][$x] !== null) $m[$y][$x] = $reserva[$y][$x] === 2 ? 0 : $reserva[$y][$x];

  // preenche onde ainda está livre
  $livre = qr_nova_matriz($n);
  for($y = 0; $y < $n; $y++)
    for($x = 0; $x < $n; $x++)
      $livre[$y][$x] = ($reserva[$y][$x] === null) ? null : 1;

  qr_preenche($livre, $bitsFinal);
  for($y = 0; $y < $n; $y++)
    for($x = 0; $x < $n; $x++)
      if($reserva[$y][$x] === null) $m[$y][$x] = $livre[$y][$x];

  qr_formato($m);
  return $m;
}

/* Desenha em SVG: escala sem perder nitidez, que é o que importa numa TV. */
function qr_svg($texto, $modulo = 8, $cor = '#0C0E1C', $fundo = '#FFFFFF'){
  $m = qr_matriz($texto);
  if(!$m) return '';
  $n = count($m);
  $q = 4;                                // margem obrigatória do padrão
  $lado = ($n + $q * 2) * $modulo;

  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$lado.'" height="'.$lado.'" '
       . 'viewBox="0 0 '.($n + $q*2).' '.($n + $q*2).'" shape-rendering="crispEdges">'
       . '<rect width="100%" height="100%" fill="'.$fundo.'"/><path fill="'.$cor.'" d="';
  for($y = 0; $y < $n; $y++){
    for($x = 0; $x < $n; $x++){
      if($m[$y][$x]) $svg .= 'M'.($x+$q).' '.($y+$q).'h1v1h-1z';
    }
  }
  return $svg.'"/></svg>';
}

function qr_data_uri($texto, $modulo = 8, $cor = '#0C0E1C', $fundo = '#FFFFFF'){
  $svg = qr_svg($texto, $modulo, $cor, $fundo);
  return $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : '';
}
