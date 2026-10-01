<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   lembretes_email.php — aviso diário dos lembretes da contabilidade.

   Manda para o e-mail da contabilidade a lista do que está em aberto:
   título, descrição, prazo e há quanto tempo está atrasado. Sem
   lembrete pendente, não manda nada.

   COMO AGENDAR (todo dia às 08:30)
     Hostinger: hPanel > Avançado > Cron Jobs > Criar novo
       Comando:  php /home/SEU_USUARIO/public_html/lembretes_email.php
       Minuto 30, Hora 8, demais campos *
     Ou por URL (n8n, EasyPanel ou cron externo):
       https://SEUDOMINIO/lembretes_email.php?k=SUA_CHAVE
     A chave fica em cron_secrets.php ('lembretes_email'; se não houver,
     vale a 'lembrete_backup' que já existe).

   Pode rodar mais de uma vez no dia sem medo: depois do primeiro envio
   bem-sucedido, os seguintes só respondem "já enviado hoje".
   ============================================================ */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if(function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

$__cronsec = @include __DIR__.'/cron_secrets.php';
$CHAVE = is_array($__cronsec) ? ($__cronsec['lembretes_email'] ?? ($__cronsec['lembrete_backup'] ?? '')) : '';

$viaCli = (php_sapi_name() === 'cli');
if(!$viaCli) header('Content-Type: text/plain; charset=utf-8');
if(!$viaCli && ($CHAVE === '' || !hash_equals($CHAVE, (string)($_GET['k'] ?? '')))){
  http_response_code(403);
  exit('Acesso negado.');
}

require __DIR__.'/db_config.php';
require __DIR__.'/lembretes_lib.php';

$db = portal_db();
if(!$db) exit("ERRO: sem conexão com o banco.\n");
$db->set_charset('utf8mb4');
$db->query("CREATE TABLE IF NOT EXISTS tvi_config (
  chave VARCHAR(60) PRIMARY KEY, valor VARCHAR(255) NOT NULL,
  atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$r = lembretes_enviar_email($db, false);
echo ($r['ok'] ? 'OK: ' : 'ERRO: ') . $r['msg'] . "\n";
