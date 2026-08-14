<?php
/**
 * Atualizador do portal — sobe um .zip e ele distribui os arquivos
 * nas pastas certas, guarda cópia do que substituiu e confere o banco.
 *
 * Acesso: só quem está logado no Redentor Hub como admin.
 * Endereço: /auxilio/atualizar.php
 *
 * Nunca toca em: config.php, uploads_auxilio/, .htaccess, .user.ini,
 * db_config.php e nos backups — a menos que você marque a opção.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
/* Sem esta linha o servidor usa UTC e os horários aparecem 3 horas
   adiantados: um envio das 15h01 vira 18h01 no cartão.
   Precisa vir DEPOIS do declare(strict_types), que por regra do PHP
   tem de ser a primeira instrução do arquivo. */
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(300);
session_start();

$cfg  = require __DIR__ . '/config.php';
require_once __DIR__ . '/migracoes.php';
require_once __DIR__ . '/manutencao.php';

const RAIZ      = __DIR__ . '/..';           // public_html
const DIR_TMP   = __DIR__ . '/.atualizacoes';
const DIR_BKP   = __DIR__ . '/backups';
const MAX_ZIP   = 60 * 1024 * 1024;
const EXT_OK    = ['php','html','htm','js','css','json','md','sql','txt','png','jpg','jpeg','gif',
                   'svg','ico','webmanifest','woff','woff2','ttf','map','csv'];
/* caminhos que o zip nunca sobrescreve (relativos ao public_html) */
const PROTEGIDOS = ['auxilio/config.php','db_config.php','config.php','.htaccess','.user.ini',
                    'auxilio/.backup_estado.json'];
const PREFIXOS_PROTEGIDOS = ['auxilio/uploads_auxilio/','auxilio/backups/','auxilio/.atualizacoes/'];

/* ---------- quem pode entrar ---------- */
function pdo(array $cfg): PDO {
    $c = $cfg['db'];
    return new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4", $c['usuario'], $c['senha'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
$eu = null;
try {
    if (!empty($_SESSION['uid'])) {
        $tab = (string)(($cfg['hub']['tabela_usuarios']) ?? 'portal_usuarios');
        $st = pdo($cfg)->prepare("SELECT username, name, role FROM `$tab` WHERE id=? LIMIT 1");
        $st->execute([(int)$_SESSION['uid']]);
        $eu = $st->fetch() ?: null;
    }
} catch (Throwable $e) { $eu = null; }

if (!$eu || strtolower((string)$eu['role']) !== 'admin') {
    http_response_code(403);
    exit('<meta charset="utf-8"><p style="font:15px system-ui;padding:24px">'
       . 'Área restrita aos administradores do Redentor Hub. '
       . '<a href="../index.html">Voltar ao portal</a></p>');
}

/* ---------- utilidades ---------- */
function protegido(string $rel): bool {
    if (in_array($rel, PROTEGIDOS, true)) return true;
    foreach (PREFIXOS_PROTEGIDOS as $p) if (strpos($rel, $p) === 0) return true;
    return false;
}
function seguro(string $rel): bool {
    if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) return false;
    if (strpos($rel, "\0") !== false) return false;
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, EXT_OK, true);
}
/** Remove a pasta-raiz comum do zip (ex.: "redentor-hub-v75_7/arquivo.php").
 *
 * Bug corrigido: um zip com só "apps/tvindoor.html" tinha o "apps/" tratado
 * como invólucro sintético do zip (igual "redentor-hub-v75_7/") e removido —
 * o arquivo acabava comparado/gravado como "tvindoor.html" na raiz do site,
 * em vez de "apps/tvindoor.html". Agora só remove a pasta comum se ela NÃO
 * for uma pasta de verdade já existente no site: "apps", "auxilio" etc. são
 * destino real, não invólucro, e continuam intactos no caminho. */
function raizComum(array $nomes): string {
    $primeiro = null;
    foreach ($nomes as $n) {
        $p = explode('/', $n);
        if (count($p) < 2) return '';
        if ($primeiro === null) $primeiro = $p[0];
        elseif ($primeiro !== $p[0]) return '';
    }
    if (!$primeiro || is_dir(RAIZ . '/' . $primeiro)) return '';
    return $primeiro . '/';
}
function limpaAntigos(string $dir, int $manter = 5): void {
    if (!is_dir($dir)) return;
    $itens = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($itens);
    while (count($itens) > $manter) {
        $velho = array_shift($itens);
        $p = "$dir/$velho";
        if (!is_dir($p)) { @unlink($p); continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS),
                                            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($p);
    }
}

$msg = ''; $erro = ''; $previa = null; $relatorio = null;
$token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? $_GET['token'] ?? ''));
$incluirConfig = !empty($_POST['config']);

/* ---------- etapa 1: recebe o zip e monta a prévia ---------- */
if (($_POST['acao'] ?? '') === 'enviar') {
    try {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão zip indisponível no servidor.');
        if (!isset($_FILES['pacote']) || $_FILES['pacote']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não recebi o arquivo. Confira o tamanho e tente de novo.');
        }
        if ($_FILES['pacote']['size'] > MAX_ZIP) throw new RuntimeException('Pacote acima de 60 MB.');
        if (strtolower(pathinfo($_FILES['pacote']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Envie um arquivo .zip.');
        }
        if (!is_dir(DIR_TMP)) mkdir(DIR_TMP, 0750, true);
        $token = bin2hex(random_bytes(8));
        $destino = DIR_TMP . "/$token.zip";
        if (!move_uploaded_file($_FILES['pacote']['tmp_name'], $destino)) {
            throw new RuntimeException('Falha ao gravar o pacote no servidor.');
        }
        $_SESSION['atu_token'] = $token;
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}

if ($token && !$erro && ($_POST['acao'] ?? '') !== 'aplicar') {
    $zipPath = DIR_TMP . "/$token.zip";
    $zip = new ZipArchive();
    if (is_file($zipPath) && $zip->open($zipPath) === true) {
        $nomes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (substr($n, -1) === '/') continue;
            $nomes[] = $n;
        }
        $corte = raizComum($nomes);
        $previa = ['novos' => [], 'muda' => [], 'igual' => [], 'pulado' => [], 'bloqueado' => []];
        foreach ($nomes as $n) {
            $rel = $corte && strpos($n, $corte) === 0 ? substr($n, strlen($corte)) : $n;
            // primeiro os protegidos: são arquivos legítimos que apenas não
            // devem ser trocados (config, uploads, .htaccess). Só o que sobra
            // passa pela checagem de caminho e extensão.
            if (protegido($rel) && !($incluirConfig && $rel === 'auxilio/config.php')) {
                $previa['pulado'][] = $rel; continue;
            }
            if (!seguro($rel)) { $previa['bloqueado'][] = $rel; continue; }
            $alvo = RAIZ . '/' . $rel;
            if (!is_file($alvo)) { $previa['novos'][] = $rel; continue; }
            $atual = md5_file($alvo);
            $novo  = md5((string)$zip->getFromName($n));
            if ($atual === $novo) $previa['igual'][] = $rel; else $previa['muda'][] = $rel;
        }
        $zip->close();
    } else { $erro = 'Pacote não encontrado ou inválido. Envie de novo.'; $token = ''; }
}

/* ---------- etapa 2: aplicação em fatias, com progresso ---------- */
function planoDoZip(string $zipPath, bool $incluirConfig): array {
    $zip = new ZipArchive();
    if (!is_file($zipPath) || $zip->open($zipPath) !== true) return [];
    $nomes = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (substr($n, -1) !== '/') $nomes[] = $n;
    }
    $corte = raizComum($nomes);
    $plano = [];
    foreach ($nomes as $n) {
        $rel = $corte && strpos($n, $corte) === 0 ? substr($n, strlen($corte)) : $n;
        if (protegido($rel) && !($incluirConfig && $rel === 'auxilio/config.php')) {
            $plano[] = ['n' => $n, 'rel' => $rel, 'tipo' => 'protegido']; continue;
        }
        if (!seguro($rel)) { $plano[] = ['n' => $n, 'rel' => $rel, 'tipo' => 'bloqueado']; continue; }
        $alvo = RAIZ . '/' . $rel;
        if (is_file($alvo) && md5_file($alvo) === md5((string)$zip->getFromName($n))) {
            $plano[] = ['n' => $n, 'rel' => $rel, 'tipo' => 'igual']; continue;
        }
        $plano[] = ['n' => $n, 'rel' => $rel, 'tipo' => 'gravar'];
    }
    $zip->close();
    return $plano;
}

$acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? '');
if (in_array($acao, ['iniciar', 'passo', 'finalizar'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['ok' => false];
    try {
        if (!$token) throw new RuntimeException('Pacote não identificado.');
        $zipPath  = DIR_TMP . "/$token.zip";
        $arqPlano = DIR_TMP . "/$token.plano.json";

        if ($acao === 'iniciar') {
            $plano = planoDoZip($zipPath, $incluirConfig);
            if (!$plano) throw new RuntimeException('Pacote inválido ou vazio.');
            $carimbo = date('Y-m-d_His');
            file_put_contents($arqPlano, json_encode([
                'plano' => $plano, 'i' => 0, 'backup' => DIR_BKP . "/$carimbo",
                'gravados' => [], 'protegidos' => 0, 'bloqueados' => 0,
            ], JSON_UNESCAPED_UNICODE));
            $aGravar = count(array_filter($plano, fn($x) => $x['tipo'] === 'gravar'));
            manutencaoLigar((string)$eu['username'], count($plano));
            $resp = ['ok' => true, 'total' => count($plano), 'gravar' => $aGravar, 'pct' => 0];

        } elseif ($acao === 'passo') {
            $e = json_decode((string)file_get_contents($arqPlano), true);
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('Pacote sumiu no meio do caminho.');
            $fim = min(count($e['plano']), $e['i'] + 8);
            for (; $e['i'] < $fim; $e['i']++) {
                $it = $e['plano'][$e['i']];
                if ($it['tipo'] === 'bloqueado') { $e['bloqueados']++; continue; }
                if ($it['tipo'] === 'protegido') { $e['protegidos']++; continue; }
                if ($it['tipo'] === 'igual') continue;
                $alvo = RAIZ . '/' . $it['rel'];
                $conteudo = $zip->getFromName($it['n']);
                if ($conteudo === false) continue;
                if (is_file($alvo)) {
                    $destBkp = $e['backup'] . '/' . $it['rel'];
                    if (!is_dir(dirname($destBkp))) mkdir(dirname($destBkp), 0750, true);
                    @copy($alvo, $destBkp);
                }
                if (!is_dir(dirname($alvo))) mkdir(dirname($alvo), 0755, true);
                if (file_put_contents($alvo, $conteudo) !== false) $e['gravados'][] = $it['rel'];
            }
            $zip->close();
            file_put_contents($arqPlano, json_encode($e, JSON_UNESCAPED_UNICODE));
            $total = count($e['plano']);
            manutencaoProgresso($e['i'], $total, 'Gravando arquivos (' . $e['i'] . ' de ' . $total . ')');
            $resp = ['ok' => true, 'pronto' => $e['i'] >= $total, 'feito' => $e['i'], 'total' => $total,
                     'pct' => $total ? (int)round($e['i'] / $total * 100) : 100,
                     'ultimo' => $e['gravados'] ? end($e['gravados']) : ''];

        } else { // finalizar
            $e = json_decode((string)file_get_contents($arqPlano), true);
            manutencaoProgresso(max(1, (int)$e['i']), max(1, count($e['plano'])), 'Conferindo o banco de dados');
            $banco = []; $bancoErro = '';
            try {
                $novoCfg = require __DIR__ . '/config.php';
                $banco = garanteEstrutura(pdo($novoCfg));
            } catch (Throwable $ex) { $bancoErro = $ex->getMessage(); }
            @unlink($zipPath); @unlink($arqPlano);
            /* Guarda as 3 últimas. Foi o número pedido, e faz sentido:
               a cópia inteira do que foi substituído ocupa espaço, e na
               prática só a mais recente costuma ser usada — as outras
               duas existem para o caso de o problema só aparecer dias
               depois. */
            limpaAntigos(DIR_BKP, 3);
            manutencaoDesligar();
            $resp = ['ok' => true, 'gravados' => $e['gravados'], 'protegidos' => $e['protegidos'],
                     'bloqueados' => $e['bloqueados'], 'banco' => $banco, 'banco_erro' => $bancoErro,
                     'backup' => str_replace(__DIR__ . '/', '', (string)$e['backup'])];
        }
    } catch (Throwable $ex) {
        manutencaoDesligar();
        $resp = ['ok' => false, 'erro' => $ex->getMessage()];
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$listaPrevia = function (array $itens, string $cor) {
    if (!$itens) return '<span class="nada">nenhum</span>';
    $h = '';
    foreach (array_slice($itens, 0, 40) as $i) $h .= '<code style="color:' . $cor . '">' . htmlspecialchars($i) . '</code>';
    if (count($itens) > 40) $h .= '<span class="nada">+' . (count($itens) - 40) . '</span>';
    return $h;
};
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Atualizar o portal</title>
<style>
body{margin:0;background:#1b1e24;color:#e8ecf2;font:15px/1.6 "Segoe UI",system-ui,Arial,sans-serif}
.marca{background:#3B4192;border-bottom:3px solid #D9A93F;padding:14px 22px;display:flex;
  align-items:center;gap:12px}
.marca b{font-size:16px;letter-spacing:.4px}.marca span{font-size:12px;color:#c6cbee}
.marca a{margin-left:auto;color:#eef0fb;text-decoration:none;border:1px solid #ffffff47;
  border-radius:999px;padding:6px 14px;font-size:13px}
.wrap{max-width:820px;margin:0 auto;padding:22px 18px 70px}
.card{background:#23272f;border:1px solid #333a45;border-radius:12px;padding:20px;margin-bottom:16px}
h2{font-size:16px;margin:0 0 6px}
p.sub{color:#9aa4b2;font-size:13px;margin:0 0 16px}
input[type=file]{width:100%;background:#1b1e24;border:1px solid #333a45;color:#e8ecf2;border-radius:9px;
  padding:12px;box-sizing:border-box}
.dropzone{position:relative;border:2px dashed #333a45;border-radius:12px;padding:34px 20px;text-align:center;
  cursor:pointer;transition:.15s;background:#1b1e24}
.dropzone:hover{border-color:#7C85E8}
.dropzone.arrastando{border-color:#3B4192;background:#3b419222}
.dropzone.tem-arquivo{border-color:#2fbf71;border-style:solid}
.dropzone-ic{font-size:32px;margin-bottom:8px}
.dropzone-tx{color:#9aa4b2;font-size:14px;pointer-events:none}
.dropzone.tem-arquivo .dropzone-tx{color:#2fbf71;font-weight:600}
.dropzone input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;
  border:0;padding:0}
.btn{background:#3B4192;color:#fff;border:0;border-radius:9px;padding:11px 20px;font:inherit;
  cursor:pointer;margin-top:16px}
.btn.gh{background:transparent;border:1px solid #333a45;color:#e8ecf2}
.btn:hover{filter:brightness(1.15)}
.linha{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #2c323d;font-size:13px}
.linha:last-child{border:0}
.linha b{flex:0 0 150px}
code{display:inline-block;background:#1b1e24;border-radius:5px;padding:2px 8px;margin:2px 4px 2px 0;
  font-size:12px}
.nada{color:#6b7280;font-size:12.5px}
.ok{border-left:3px solid #2fbf71;background:rgba(47,191,113,.12);padding:12px 14px;border-radius:8px;
  margin-bottom:14px}
.no{border-left:3px solid #f4623a;background:rgba(244,98,58,.12);padding:12px 14px;border-radius:8px;
  margin-bottom:14px}
.aviso{border-left:3px solid #D9A93F;background:rgba(217,169,63,.12);padding:12px 14px;border-radius:8px;
  font-size:13px}
label.ck{display:flex;gap:9px;align-items:flex-start;font-size:13px;color:#c8cfda;margin-top:14px}
</style></head><body>
<div class="marca"><div><b>Auto Viação Redentor</b><br><span>Atualizar o portal · <?= htmlspecialchars($eu['name']) ?></span></div>
  <a href="../index.html">Voltar ao portal</a></div>
<div class="wrap">

<?php if ($msg): ?><div class="ok"><b><?= htmlspecialchars($msg) ?></b></div><?php endif; ?>
<?php if ($erro): ?><div class="no"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if (false): ?>
  <div class="card"><h2>O que foi feito</h2>
    <div class="linha"><b>Arquivos gravados</b><div><?= $listaPrevia($relatorio['gravados'], '#2fbf71') ?></div></div>
    <div class="linha"><b>Preservados</b><div><?= (int)$relatorio['pulados'] ?> arquivo(s) protegido(s) — config, uploads e backups</div></div>
    <?php if ($relatorio['bloqueados']): ?>
    <div class="linha"><b>Bloqueados</b><div style="color:#f4623a"><?= (int)$relatorio['bloqueados'] ?> item(ns) com caminho ou extensão fora do permitido</div></div>
    <?php endif; ?>
    <div class="linha"><b>Banco de dados</b><div><?= isset($relatorio['banco_erro'])
      ? '<span style="color:#f4623a">' . htmlspecialchars($relatorio['banco_erro']) . '</span>'
      : ($relatorio['banco'] ? $listaPrevia($relatorio['banco'], '#D9A93F') : '<span class="nada">já estava em dia</span>') ?></div></div>
    <div class="linha"><b>Cópia de segurança</b><div><code><?= htmlspecialchars(str_replace(__DIR__ . '/', '', $relatorio['backup'])) ?></code></div></div>
  </div>
  <div class="aviso">Abra o portal e dê <b>Ctrl+Shift+R</b> para o navegador largar a versão antiga.
    Se algo tiver quebrado, os arquivos anteriores estão na pasta de backup acima.
    <br><br><a href="restaurar.php" style="color:#C08A28;font-weight:600">
    &#8630; Restaurar uma versão anterior</a></div>
<?php endif; ?>

<?php if ($previa && !$erro): ?>
  <div class="card"><h2>Confira antes de aplicar</h2>
    <p class="sub">Nada foi gravado ainda.</p>
    <div class="linha"><b>Arquivos novos</b><div><?= $listaPrevia($previa['novos'], '#2fbf71') ?></div></div>
    <div class="linha"><b>Serão substituídos</b><div><?= $listaPrevia($previa['muda'], '#D9A93F') ?></div></div>
    <div class="linha"><b>Sem mudança</b><div><span class="nada"><?= count($previa['igual']) ?> arquivo(s) idênticos — serão ignorados</span></div></div>
    <div class="linha"><b>Preservados</b><div><?= $listaPrevia($previa['pulado'], '#9aa4b2') ?>
      <div class="nada" style="margin-top:4px">Arquivos que o atualizador nunca troca: config,
        uploads e .htaccess. Se o pacote tiver versão nova deles, ela é ignorada de propósito.</div></div></div>
    <?php if ($previa['bloqueado']): ?>
    <div class="linha"><b>Bloqueados</b><div><?= $listaPrevia($previa['bloqueado'], '#f4623a') ?>
      <div class="nada" style="margin-top:4px">Caminho ou extensão fora do permitido &mdash;
        proteção contra pacote adulterado.</div></div></div>
    <?php endif; ?>
    <label class="ck"><input type="checkbox" id="ckConfig" <?= $incluirConfig ? 'checked' : '' ?>>
      <span>Substituir também o <b>auxilio/config.php</b> — só marque se souber que o pacote traz as
      credenciais certas. Senão, banco, e-mail e Drive param de funcionar.</span></label>
    <button class="btn" id="btAplicar" onclick="aplicar()">Aplicar atualização</button>
    <a class="btn gh" href="atualizar.php" style="text-decoration:none;display:inline-block">Cancelar</a>

    <div id="painelProg" style="display:none;margin-top:22px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
        <span id="progEtapa">Iniciando…</span><b id="progPct">0%</b>
      </div>
      <div style="height:12px;background:#1b1e24;border:1px solid #333a45;border-radius:99px;overflow:hidden">
        <div id="progBarra" style="height:100%;width:0;background:linear-gradient(90deg,#3B4192,#7C85E8);
          transition:width .25s"></div>
      </div>
      <div id="progArq" style="font-size:12px;color:#6b7280;margin-top:8px;min-height:18px"></div>
      <div class="aviso" style="margin-top:14px">Enquanto isso, quem estiver no portal vê o aviso de
        atualização. Não feche esta janela.</div>
    </div>
    <div id="painelFim" style="display:none;margin-top:20px"></div>
  </div>
<?php endif; ?>

<?php if (!$previa): ?>
  <div class="card"><h2>Enviar pacote</h2>
    <p class="sub">Suba o .zip da nova versão. Os arquivos vão para as pastas de origem, o que já estava
      igual é ignorado, e o banco é conferido no fim. Até 60 MB.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="enviar">
      <div class="dropzone" id="dropzone">
        <div class="dropzone-ic">📦</div>
        <div class="dropzone-tx" id="dropzoneTx">Arraste o .zip aqui, ou clique para escolher</div>
        <input type="file" name="pacote" id="campoArquivo" required>
      </div>
      <button class="btn" type="submit">Enviar e conferir</button>
    </form>
    <script>
    (function(){
      var zona = document.getElementById('dropzone');
      var campo = document.getElementById('campoArquivo');
      var texto = document.getElementById('dropzoneTx');
      function mostrarArquivo(){
        if(campo.files && campo.files.length){
          texto.textContent = '✓ ' + campo.files[0].name + ' — clique para trocar';
          zona.classList.add('tem-arquivo');
        }
      }
      // O campo de arquivo continua ali (clicável do jeito normal do navegador);
      // a caixa em volta só existe pra dar uma área maior de clique/arraste,
      // porque em alguns navegadores/telas o botão nativo "Escolher arquivo"
      // é pequeno demais ou não responde ao toque.
      campo.addEventListener('change', mostrarArquivo);
      ['dragenter','dragover'].forEach(function(ev){
        zona.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zona.classList.add('arrastando'); });
      });
      ['dragleave','drop'].forEach(function(ev){
        zona.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zona.classList.remove('arrastando'); });
      });
      zona.addEventListener('drop', function(e){
        var arquivos = e.dataTransfer.files;
        if(arquivos && arquivos.length){ campo.files = arquivos; mostrarArquivo(); }
      });
    })();
    </script>
    <div class="aviso" style="margin-top:18px">Nunca sobrescrevo <b>config.php</b>, <b>uploads_auxilio/</b>,
      <b>.htaccess</b> nem <b>db_config.php</b>. Cada arquivo substituído vai antes para
      <b>auxilio/backups/</b>, e as cinco atualizações mais recentes ficam guardadas.</div>
  </div>
<?php endif; ?>

</div>
<script>
const TOKEN='<?= htmlspecialchars($token ?? "") ?>';
async function chama(acao, extra){
  const fd=new FormData();
  fd.append('acao',acao); fd.append('token',TOKEN);
  if(ckConfig && ckConfig.checked) fd.append('config','1');
  const r=await fetch('atualizar.php',{method:'POST',body:fd});
  const j=await r.json();
  if(!j.ok) throw new Error(j.erro||'Falha na atualização.');
  return j;
}
function pinta(pct,etapa,arq){
  progBarra.style.width=pct+'%'; progPct.textContent=pct+'%';
  if(etapa) progEtapa.textContent=etapa;
  if(arq!==undefined) progArq.textContent=arq?('último: '+arq):'';
}
async function aplicar(){
  btAplicar.disabled=true; btAplicar.textContent='Atualizando…';
  painelProg.style.display='';
  try{
    const ini=await chama('iniciar');
    pinta(0,'Preparando '+ini.total+' arquivo(s)','');
    let pronto=false;
    while(!pronto){
      const p=await chama('passo');
      pinta(p.pct,'Gravando arquivos ('+p.feito+' de '+p.total+')',p.ultimo);
      pronto=p.pronto;
    }
    pinta(100,'Conferindo o banco de dados','');
    const f=await chama('finalizar');
    const l=(a,c)=>a&&a.length?a.slice(0,40).map(x=>'<code style="color:'+c+'">'+x+'</code>').join('')
      +(a.length>40?'<span class="nada">+'+(a.length-40)+'</span>':''):'<span class="nada">nenhum</span>';
    painelFim.style.display='';
    painelFim.innerHTML='<div class="ok"><b>Atualização concluída.</b></div>'
      +'<div class="linha"><b>Arquivos gravados</b><div>'+l(f.gravados,'#2fbf71')+'</div></div>'
      +'<div class="linha"><b>Preservados</b><div>'+f.protegidos+' arquivo(s) protegido(s)</div></div>'
      +(f.bloqueados?'<div class="linha"><b>Bloqueados</b><div style="color:#f4623a">'+f.bloqueados+'</div></div>':'')
      +'<div class="linha"><b>Banco de dados</b><div>'+(f.banco_erro
          ?'<span style="color:#f4623a">'+f.banco_erro+'</span>'
          :(f.banco&&f.banco.length?l(f.banco,'#D9A93F'):'<span class="nada">já estava em dia</span>'))+'</div></div>'
      +'<div class="linha"><b>Cópia de segurança</b><div><code>'+f.backup+'</code></div></div>'
      +'<div class="aviso" style="margin-top:14px">Abra o portal e dê <b>Ctrl+Shift+R</b>. '
      +'O aviso de atualização já saiu do ar para todo mundo.</div>'
      +'<a class="btn" href="../index.html" style="text-decoration:none;display:inline-block">Voltar ao portal</a>';
    painelProg.style.display='none';
  }catch(e){
    painelProg.style.display='none';
    painelFim.style.display='';
    painelFim.innerHTML='<div class="no"><b>Deu problema:</b> '+e.message
      +'<br>O aviso de manutenção foi desligado. Os arquivos já gravados estão valendo, '
      +'e os anteriores estão na pasta de backups.</div>';
    btAplicar.disabled=false; btAplicar.textContent='Tentar de novo';
  }
}
</script>
</body></html>
