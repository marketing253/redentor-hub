<?php
/**
 * Auxílio Graduação — instalação e diagnóstico.
 * Abra no navegador depois de subir os arquivos. Apague quando terminar.
 */
declare(strict_types=1);

/* Schema embutido — assim o instalador funciona mesmo sem a pasta db/. */
const SCHEMA_EMBUTIDO = <<<'SQL'
-- =====================================================================
-- Auxílio Graduação — schema MySQL (Redentor Hub)
-- A empresa custeia 70% da mensalidade. Ajuste o percentual por aluno.
-- =====================================================================

CREATE TABLE IF NOT EXISTS aux_alunos (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  usuario            VARCHAR(60)  NOT NULL,            -- login do aluno no Hub
  nome               VARCHAR(120) NOT NULL,
  matricula          VARCHAR(30)  DEFAULT NULL,
  setor              VARCHAR(60)  DEFAULT NULL,
  email              VARCHAR(120) DEFAULT NULL,
  telefone           VARCHAR(20)  DEFAULT NULL,
  pix_tipo           ENUM('cpf','email','telefone','aleatoria') DEFAULT NULL,
  pix_chave          VARCHAR(140) DEFAULT NULL,
  pix_atualizado_em  DATETIME     DEFAULT NULL,
  instituicao        VARCHAR(120) NOT NULL,
  curso              VARCHAR(120) NOT NULL,
  valor_mensalidade  DECIMAL(10,2) NOT NULL,
  percentual         DECIMAL(5,2)  NOT NULL DEFAULT 70.00,
  qtd_mensalidades   SMALLINT      NOT NULL,
  dia_vencimento     TINYINT       NOT NULL DEFAULT 10,
  inicio_competencia CHAR(7)       NOT NULL,           -- AAAA-MM da 1ª parcela
  contrato_arquivo   VARCHAR(255)  DEFAULT NULL,
  contrato_enviado_em DATETIME     DEFAULT NULL,
  status             ENUM('ativo','suspenso','encerrado') NOT NULL DEFAULT 'ativo',
  observacao         VARCHAR(500)  DEFAULT NULL,
  criado_por         VARCHAR(60)   DEFAULT NULL,
  criado_em          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_aux_usuario (usuario),
  KEY ix_aux_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aux_mensalidades (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id            INT      NOT NULL,
  parcela             SMALLINT NOT NULL,
  competencia         CHAR(7)  NOT NULL,               -- AAAA-MM
  vencimento          DATE     NOT NULL,
  prazo_envio         DATE     NOT NULL,               -- dia 5 da competência
  valor_boleto        DECIMAL(10,2) DEFAULT NULL,      -- confirmado pela contabilidade
  valor_empresa       DECIMAL(10,2) DEFAULT NULL,      -- 70%
  valor_aluno         DECIMAL(10,2) DEFAULT NULL,      -- 30%
  boleto_arquivo      VARCHAR(255) DEFAULT NULL,
  boleto_enviado_em   DATETIME     DEFAULT NULL,
  boleto_atrasado     TINYINT(1)   NOT NULL DEFAULT 0,
  comprovante_arquivo VARCHAR(255) DEFAULT NULL,
  comprovante_enviado_em DATETIME  DEFAULT NULL,
  status ENUM('aguardando_boleto','em_analise','rejeitado','aprovado','pago','concluido')
                      NOT NULL DEFAULT 'aguardando_boleto',
  observacao          VARCHAR(500) DEFAULT NULL,
  analisado_por       VARCHAR(60)  DEFAULT NULL,
  analisado_em        DATETIME     DEFAULT NULL,
  pago_em             DATE         DEFAULT NULL,
  atualizado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_aux_parcela (aluno_id, competencia),
  KEY ix_aux_status_comp (status, competencia),
  CONSTRAINT fk_aux_mens_aluno FOREIGN KEY (aluno_id)
    REFERENCES aux_alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aux_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id       INT DEFAULT NULL,
  mensalidade_id INT DEFAULT NULL,
  usuario        VARCHAR(60) NOT NULL,
  acao           VARCHAR(40) NOT NULL,
  detalhe        VARCHAR(300) DEFAULT NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_aux_log_aluno (aluno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SQL;

ini_set('display_errors','1'); error_reporting(E_ALL);
session_start();

/* ---------------------------------------------------------------------
   Proteção: este arquivo cria/altera tabelas, dispara e-mail de teste
   (usando a caixa SMTP real) e mostra diagnóstico do servidor. Antes,
   ele não pedia nenhuma senha — qualquer pessoa na internet podia abrir
   esta página. Agora exige a mesma "chave_teste" do config.php, do
   mesmo jeito que avisos.php/backup.php/drive_autorizar.php já fazem.
   Assim que a instalação estiver concluída, o ideal é apagar este
   arquivo do servidor (como o rodapé da página já orienta).
   --------------------------------------------------------------------- */
$cfgProtecao = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
$chaveEsperada = (string)($cfgProtecao['chave_teste'] ?? '');
$chaveRecebida = (string)($_GET['chave'] ?? '');
if ($chaveEsperada === '' || !hash_equals($chaveEsperada, $chaveRecebida)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acesso negado.\nAbra este endereço com ?chave=SUA_CHAVE_TESTE (definida em auxilio/config.php).";
    exit;
}

$itens = [];
$erroFatal = null;

function add(array &$i, bool $ok, string $titulo, string $detalhe = ''): void {
    $i[] = ['ok' => $ok, 't' => $titulo, 'd' => $detalhe];
}

add($itens, version_compare(PHP_VERSION, '8.0', '>='), 'PHP ' . PHP_VERSION,
    version_compare(PHP_VERSION, '8.0', '>=') ? '' : 'Troque para PHP 8.0 ou superior no painel da Hostinger.');
foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'curl'] as $ext) {
    add($itens, extension_loaded($ext), "Extensão $ext",
        extension_loaded($ext) ? '' : "Ative a extensão $ext no painel do servidor.");
}

$cfgPath = __DIR__ . '/config.php';
$cfg = is_file($cfgPath) ? require $cfgPath : null;
require_once __DIR__ . '/migracoes.php';
add($itens, (bool)$cfg, 'config.php encontrado', $cfg ? '' : 'Arquivo config.php ausente na raiz do módulo.');

if ($cfg) {
    $preenchido = strpos((string)$cfg['db']['base'], 'AJUSTE') === false;
    add($itens, $preenchido, 'Credenciais preenchidas',
        $preenchido ? '' : 'Abra config.php e troque base, usuário e senha do banco.');

    $pdo = null;
    try {
        $c = $cfg['db'];
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4",
            $c['usuario'], $c['senha'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        add($itens, true, 'Conexão com o banco', 'Base ' . $c['base']);
    } catch (Throwable $e) {
        add($itens, false, 'Conexão com o banco', $e->getMessage());
    }

    if ($pdo && ($_GET['criar'] ?? '') === '1') {
        try {
            $arq = __DIR__ . '/db/auxilio_schema.sql';
            $sql = is_file($arq) ? file_get_contents($arq) : SCHEMA_EMBUTIDO;
            // tira as linhas de comentário ANTES de separar os comandos
            $limpo = preg_replace('/^\s*--.*$/m', '', (string)$sql);
            $n = 0;
            foreach (explode(';', (string)$limpo) as $cmd) {
                $cmd = trim($cmd);
                if ($cmd === '') continue;
                $pdo->exec($cmd);
                $n++;
            }
            $criado = "Comandos executados: $n.";
        } catch (Throwable $e) { $erroFatal = 'Falha ao criar as tabelas: ' . $e->getMessage(); }
    }

    if (($_GET['drive'] ?? '') === '1') {
        require_once __DIR__ . '/drive.php';
        if (empty($cfg['drive']['ativo'])) {
            $erroFatal = 'Backup desligado: coloque drive.ativo = true no config.php.';
        } else {
            [$tk, $e] = driveToken($cfg);
            if (!$tk) { $erroFatal = "Google recusou as credenciais: $e"; }
            else {
                $teste = sys_get_temp_dir() . '/auxilio-teste.txt';
                file_put_contents($teste, 'teste de backup ' . date('c'));
                [$ok, $res] = driveEnvia($cfg, $teste, 'auxilio-teste-' . date('Ymd-His') . '.txt', 'text/plain');
                @unlink($teste);
                if ($ok) $criado = 'Arquivo de teste enviado ao Drive (id ' . $res . ').';
                else     $erroFatal = "Falha no envio: $res";
            }
        }
    }

    if (($_GET['email'] ?? '') === '1') {
        require_once __DIR__ . '/email.php';
        $para = (string)(($cfg['email_contabilidade'][0]) ?? '');
        $para = (string)($_GET['para'] ?? $para);
        [$ok, $e] = enviaEmail($cfg, $para, 'Auxílio Graduação — teste de envio',
            "Se você recebeu esta mensagem, o SMTP está configurado corretamente.",
            moldeEmail('Teste de envio', '<p>Se você recebeu esta mensagem, o SMTP está funcionando.</p>'),
            true);
        $criado = $ok ? "E-mail de teste enviado para $para. Confira também a caixa de spam." : '';
        if (!$ok) $erroFatal = "Falha no envio para $para:\n$e";
    }

    if ($pdo && ($_GET['estrutura'] ?? '') === '1') {
        try {
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $f = garanteEstrutura($pdo);
            $criado = $f ? 'Banco atualizado: ' . implode(', ', $f) . '.'
                         : 'Banco já estava na versão ' . AUX_SCHEMA_VERSAO . ' — nada a fazer.';
        } catch (Throwable $e) { $erroFatal = 'Falha ao atualizar: ' . $e->getMessage(); }
    }

    if ($pdo && ($_GET['colunas'] ?? '') === '1') {
        $novas = [
            'email'             => "VARCHAR(120) DEFAULT NULL",
            'telefone'          => "VARCHAR(20) DEFAULT NULL",
            'pix_tipo'          => "ENUM('cpf','email','telefone','aleatoria') DEFAULT NULL",
            'pix_chave'         => "VARCHAR(140) DEFAULT NULL",
            'pix_atualizado_em' => "DATETIME DEFAULT NULL",
            'senha_hash'        => "VARCHAR(255) DEFAULT NULL",
            'precisa_trocar'    => "TINYINT(1) NOT NULL DEFAULT 1",
            'acesso_enviado_em' => "DATETIME DEFAULT NULL",
            'ultimo_acesso'     => "DATETIME DEFAULT NULL",
        ];
        $feitas = 0;
        foreach ($novas as $col => $tipo) {
            try { $pdo->exec("ALTER TABLE aux_alunos ADD COLUMN `$col` $tipo"); $feitas++; }
            catch (Throwable $e) { /* já existe */ }
        }
        $criado = "Colunas verificadas. Adicionadas agora: $feitas.";
    }

    if ($pdo && ($_GET['multi'] ?? '') === '1') {
        try {
            $pdo->exec('ALTER TABLE aux_alunos DROP INDEX uk_aux_usuario');
            $pdo->exec('ALTER TABLE aux_alunos ADD INDEX ix_aux_usuario (usuario)');
            $criado = 'Pronto: o mesmo aluno já pode ter mais de um curso.';
        } catch (Throwable $e) {
            $criado = 'Nada a fazer — vários cursos por aluno já estavam liberados.';
        }
    }

    if ($pdo && ($_GET['prazos'] ?? '') === '1') {
        try {
            $dia = (int)($cfg['dia_prazo'] ?? 3);
            $up  = $pdo->prepare('UPDATE aux_mensalidades SET prazo_envio=? WHERE id=?');
            $n   = 0;
            foreach ($pdo->query('SELECT id, competencia, prazo_envio FROM aux_mensalidades')
                         ->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $novo = $m['competencia'] . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
                if ($novo !== $m['prazo_envio']) { $up->execute([$novo, $m['id']]); $n++; }
            }
            $criado = "Prazos recalculados para o dia $dia: $n parcela(s) ajustadas.";
        } catch (Throwable $e) { $erroFatal = 'Falha ao recalcular prazos: ' . $e->getMessage(); }
    }

    if ($pdo) {
        try {
            $uk = $pdo->query("SHOW INDEX FROM aux_alunos WHERE Key_name='uk_aux_usuario'")->fetchAll();
            add($itens, !$uk, 'Vários cursos por aluno liberados',
                $uk ? 'Clique em "Permitir vários cursos" abaixo.' : '');
        } catch (Throwable $e) { /* tabela ainda não existe */ }
        foreach (['aux_alunos', 'aux_mensalidades', 'aux_log'] as $t) {
            $existe = (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
            add($itens, $existe, "Tabela $t", $existe ? '' : 'Use o botão "Criar tabelas" abaixo.');
        }
    }
}

$dir = ($cfg && !empty($cfg['dir_uploads'])) ? rtrim((string)$cfg['dir_uploads'], '/')
     : __DIR__ . '/uploads_auxilio';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
add($itens, is_dir($dir) && is_writable($dir), 'Pasta de uploads gravável (' . $dir . ')',
    is_writable($dir) ? '' : 'Ajuste a permissão da pasta para 750 (ou 755) no gerenciador de arquivos.');
add($itens, class_exists('ZipArchive'), 'Extensão zip (necessária para o backup)',
    class_exists('ZipArchive') ? '' : 'Ative a extensão zip no painel do servidor.');
add($itens, is_file($dir . '/.htaccess'), 'uploads_auxilio protegida',
    is_file($dir . '/.htaccess') ? 'Acesso direto bloqueado' : 'Suba o .htaccess dentro da pasta.');
$frontHub = is_file(dirname(__DIR__) . '/apps/auxilio.html');   // dentro do Redentor Hub
add($itens, $frontHub, 'Tela do módulo em apps/auxilio.html',
    $frontHub ? '' : 'Envie o arquivo auxilio.html para a pasta apps do Hub.');
add($itens, is_file(__DIR__ . '/api/auxilio.php'), 'Backend em auxilio/api/auxilio.php');
$hub = false;
try { $hub = $pdo && $pdo->query("SHOW TABLES LIKE 'portal_usuarios'")->fetchColumn(); } catch (Throwable $e) {}
add($itens, (bool)$hub, 'Banco do Redentor Hub (portal_usuarios)',
    $hub ? 'Sessão e permissões vêm do Hub' : 'Confira base e usuário no config.php.');

$falhas = count(array_filter($itens, fn($i) => !$i['ok']));
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Auxílio Graduação — instalação</title>
<style>
body{margin:0;background:#1b1e24;color:#e8ecf2;font:15px/1.55 "Segoe UI",system-ui,Arial,sans-serif}
.marca{background:#3B4192;border-bottom:3px solid #D9A93F;padding:14px 20px}
.marca b{display:block;font-size:16px;letter-spacing:.4px}
.marca span{font-size:12px;color:#c6cbee}
.wrap{max-width:760px;margin:0 auto;padding:22px 18px 60px}
.card{background:#23272f;border:1px solid #333a45;border-radius:12px;padding:18px;margin-bottom:16px}
h2{font-size:16px;margin:0 0 14px}
.it{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #2c323d;align-items:flex-start}
.it:last-child{border:0}
.mk{width:20px;flex:0 0 20px;font-weight:700}
.ok{color:#2fbf71}.no{color:#f4623a}
.d{color:#9aa4b2;font-size:13px}
a.btn{display:inline-block;background:#3B4192;color:#fff;text-decoration:none;padding:10px 16px;
  border-radius:8px;margin-right:8px}
a.gh{background:transparent;border:1px solid #333a45;color:#e8ecf2}
.res{border-left:3px solid #D9A93F;padding:10px 14px;background:rgba(217,169,63,.1);border-radius:6px;
  font-size:14px;margin-bottom:16px}
</style></head><body>
<div class="marca"><b>Auto Viação Redentor</b><span>Auxílio Graduação · instalação</span></div>
<div class="wrap">
  <?php if (!empty($criado)): ?><div class="res"><?= $criado ?> Recarregue a página.</div><?php endif; ?>
  <?php if ($erroFatal): ?><div class="res"><pre style="white-space:pre-wrap;margin:0;font-size:12px"><?=
    htmlspecialchars($erroFatal) ?></pre></div><?php endif; ?>
  <div class="res"><?= $falhas ? "$falhas item(ns) precisam de ajuste antes do teste."
      : 'Tudo certo. Pode entrar pelo login de teste.' ?></div>
  <div class="card"><h2>Verificação</h2>
    <?php foreach ($itens as $i): ?>
      <div class="it"><div class="mk <?= $i['ok'] ? 'ok' : 'no' ?>"><?= $i['ok'] ? '✓' : '✕' ?></div>
        <div><div><?= htmlspecialchars($i['t']) ?></div>
        <?php if ($i['d']): ?><div class="d"><?= htmlspecialchars($i['d']) ?></div><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>
  <div class="card"><h2>Ações</h2>
    <a class="btn" href="?estrutura=1">Conferir e atualizar o banco</a>
    <a class="btn gh" href="?prazos=1">Recalcular prazos (dia 3)</a>
    <a class="btn gh" href="?email=1">Testar envio de e-mail</a>
    <a class="btn gh" href="?drive=1">Testar backup no Drive</a>
    <a class="btn gh" href="login.php">Ir para o login de teste</a>
    <p class="d" style="margin-bottom:0">Terminado o teste, apague <b>instalar.php</b> e <b>login.php</b> do servidor.</p>
  </div>
</div>
</body></html>
