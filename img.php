<?php
/**
 * img.php — serve a imagem de uma notícia através do próprio servidor.
 *
 * POR QUE ISTO EXISTE
 *   A peça de notícias mandava para a TV o endereço original da imagem,
 *   no servidor do veículo. Funcionava no navegador do computador e
 *   falhava na parede — e o motivo é que o box não é um navegador comum:
 *   alguns veículos recusam conexões que não reconhecem, outros bloqueiam
 *   por origem, e o WebView barra imagem em http quando a página é https.
 *
 *   Buscando aqui e servindo daqui, a TV pede a imagem ao servidor da
 *   empresa — que ela já alcança, já confia, e que responde igual para
 *   todas as telas.
 *
 *   Efeito colateral bem-vindo: a imagem entra no cache do aparelho junto
 *   com o resto, então continua aparecendo se a internet cair.
 */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/comum.php';

$url = isset($_GET['u']) ? trim((string)$_GET['u']) : '';

/* Só http(s), e nada de endereço interno: sem isto, alguém poderia usar
   este arquivo para alcançar máquinas da rede local a partir de fora. */
if (!preg_match('#^https?://#i', $url)) { http_response_code(400); exit; }
$host = parse_url($url, PHP_URL_HOST);
if (!$host
    || preg_match('#^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|\[?::1)#i', $host)) {
  http_response_code(403); exit;
}

/* Referer montado a partir do próprio endereço da imagem: aponta para a
   raiz do veículo, que é o que ele espera ver. */
$esquema_ref = (parse_url($url, PHP_URL_SCHEME) ?: 'https') . '://' . $host . '/';

$chave  = 'imgnot_' . md5($url);
$pasta  = __DIR__ . '/uploads/cache';
$arq    = $pasta . '/' . $chave;
$meta   = $arq . '.tipo';

/* Cache de 12 horas em disco. Notícia sai do ar rápido, mas a mesma
   imagem é pedida por todas as telas ao mesmo tempo — buscar uma vez e
   servir para todas evita bater no veículo dez vezes por minuto. */
$valido = is_file($arq) && (time() - filemtime($arq)) < 43200;

if (!$valido) {
  if (!is_dir($pasta)) @mkdir($pasta, 0775, true);

  /* tvi_http devolve o corpo bruto — serve para binário sem mudança.
     O Referer ajuda: alguns veículos servem a imagem só quando o pedido
     parece vir da própria página deles. */
  $bin = function_exists('tvi_http')
    ? tvi_http($url, array('timeout' => 12,
        'headers' => array('Referer: ' . $esquema_ref)))
    : null;

  if ($bin === null || $bin === '') {
    /* Falhou agora: se houver cópia velha, ela serve. Uma imagem de ontem
       é melhor que um quadrado vazio na parede. */
    if (is_file($arq)) { $valido = true; }
    else { http_response_code(404); exit; }
  } else {
    @file_put_contents($arq, $bin);

    /* Descobre o tipo pelos primeiros bytes, não pela extensão do
       endereço: muito veículo serve /foto?id=123 sem extensão nenhuma. */
    $tipo = 'image/jpeg';
    if (strncmp($bin, "\x89PNG", 4) === 0)                 $tipo = 'image/png';
    elseif (strncmp($bin, 'GIF8', 4) === 0)                $tipo = 'image/gif';
    elseif (strncmp($bin, 'RIFF', 4) === 0
            && strpos(substr($bin, 0, 16), 'WEBP') !== false) $tipo = 'image/webp';
    @file_put_contents($meta, $tipo);
    $valido = true;
  }
}

$tipo = is_file($meta) ? trim((string)@file_get_contents($meta)) : 'image/jpeg';
if (!preg_match('#^image/#', $tipo)) $tipo = 'image/jpeg';

header('Content-Type: ' . $tipo);
header('Cache-Control: public, max-age=43200');
header('Content-Length: ' . (string)filesize($arq));
readfile($arq);
