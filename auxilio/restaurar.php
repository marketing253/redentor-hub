<?php
/**
 * restaurar.php — volta arquivos para uma versão anterior.
 *
 * O atualizar.php já guardava cópia de tudo que substituía, em
 * auxilio/backups/AAAA-MM-DD_HHMMSS/. Só que voltar exigia entrar no
 * gerenciador de arquivos e copiar pasta por pasta — na hora em que algo
 * quebrou, que é justamente quando ninguém quer fazer isso.
 *
 * Esta tela lista as cópias guardadas, mostra o que tem em cada uma e
 * devolve os arquivos ao lugar com um clique.
 *
 * Acesso: só admin do Redentor Hub, igual ao atualizar.php.
 * Endereço: /auxilio/restaurar.php
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

$cfg = require __DIR__ . '/config.php';

const RAIZ    = __DIR__ . '/..';
const DIR_BKP = __DIR__ . '/backups';

/* Nunca devolvidos, mesmo estando na cópia: são arquivos de configuração
   e dados que mudaram DEPOIS da atualização. Restaurar um config.php
   antigo quebraria a conexão com o banco. */
const PROTEGIDOS = ['auxilio/config.php', 'db_config.php', 'config.php',
                    '.htaccess', '.user.ini', 'auxilio/.backup_estado.json'];

/* ---------- quem pode entrar ---------- */
function pdo(array $cfg): PDO {
    $c = $cfg['db'];
    return new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4",
        $c['usuario'], $c['senha'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

$eu = null;
try {
    if (!empty($_SESSION['uid'])) {
        $st = pdo($cfg)->prepare("SELECT id, username, name, role FROM portal_usuarios WHERE id=? LIMIT 1");
        $st->execute([(int)$_SESSION['uid']]);
        $eu = $st->fetch() ?: null;
    }
} catch (Throwable $e) { $eu = null; }

if (!$eu || ($eu['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><title>Sem acesso</title>'
       . '<body style="background:#0C0E1C;color:#F1EFE7;font:15px system-ui;padding:40px">'
       . '<h2>Sem acesso</h2><p>Só administradores do portal podem restaurar versões.</p>'
       . '<p><a href="../index.html" style="color:#C08A28">Voltar ao portal</a></p>');
}

/* ---------- lê as cópias guardadas ---------- */
function listarCopias(): array {
    if (!is_dir(DIR_BKP)) return [];
    $out = [];
    foreach (scandir(DIR_BKP) ?: [] as $d) {
        if ($d === '.' || $d === '..') continue;
        $cam = DIR_BKP . '/' . $d;
        if (!is_dir($cam)) continue;

        $arqs = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cam, FilesystemIterator::SKIP_DOTS));
        $bytes = 0;
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($cam) + 1));
            $arqs[] = $rel;
            $bytes += $f->getSize();
        }
        if (!$arqs) continue;

        sort($arqs);
        /* O nome da pasta é AAAA-MM-DD_HHMMSS: vira data legível. */
        $quando = preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})$/', $d, $m)
            ? "{$m[3]}/{$m[2]}/{$m[1]} às {$m[4]}h{$m[5]}"
            : $d;

        $out[] = ['id' => $d, 'quando' => $quando, 'arquivos' => $arqs,
                  'total' => count($arqs), 'bytes' => $bytes];
    }
    /* Mais recente primeiro: é quase sempre para onde se quer voltar. */
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

function tamanho(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

/* ---------- restaurar ---------- */
$relatorio = null;
$erro = null;

if (($_POST['acao'] ?? '') === 'restaurar') {
    try {
        $id = preg_replace('/[^0-9_\-]/', '', (string)($_POST['copia'] ?? ''));
        $dir = DIR_BKP . '/' . $id;
        if ($id === '' || !is_dir($dir)) throw new RuntimeException('Cópia não encontrada.');

        $escolhidos = $_POST['arq'] ?? null;   // null = tudo
        $devolvidos = $pulados = $falhas = [];

        /* Antes de sobrescrever, guarda o estado ATUAL numa cópia nova.
           Sem isso, restaurar seria uma via de mão única: se a versão
           antiga também estivesse quebrada, não haveria volta. */
        $carimboAgora = date('Y-m-d_His') . '_antes-de-restaurar';
        $dirAgora = DIR_BKP . '/' . $carimboAgora;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));

            if (is_array($escolhidos) && !in_array($rel, $escolhidos, true)) continue;
            if (in_array($rel, PROTEGIDOS, true)) { $pulados[] = $rel; continue; }

            $alvo = RAIZ . '/' . $rel;

            if (is_file($alvo)) {
                $guarda = $dirAgora . '/' . $rel;
                if (!is_dir(dirname($guarda))) mkdir(dirname($guarda), 0750, true);
                @copy($alvo, $guarda);
            }
            if (!is_dir(dirname($alvo))) mkdir(dirname($alvo), 0755, true);

            if (@copy($f->getPathname(), $alvo)) $devolvidos[] = $rel;
            else $falhas[] = $rel;
        }

        $relatorio = ['devolvidos' => $devolvidos, 'pulados' => $pulados,
                      'falhas' => $falhas, 'guardado' => $carimboAgora,
                      'de' => $id];

    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$copias = listarCopias();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Restaurar versão · Redentor Hub</title>
<style>
  :root{ --bg:#0C0E1C; --sup:#12152C; --reg:#232849; --txt:#F1EFE7;
         --txt2:#9EA2C0; --txt3:#6A6F98; --ouro:#C08A28; --ouro-cl:#D9A83F;
         --pos:#57C98B; --neg:#E0576E; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);padding:34px 26px;
    font:15px/1.6 "Segoe UI Variable Text","Segoe UI",system-ui,Arial,sans-serif}
  .caixa{max-width:920px;margin:0 auto}
  h1{font-family:"Iowan Old Style",Palatino,Georgia,serif;font-size:27px;font-weight:400;margin:0 0 6px}
  .sub{color:var(--txt2);font-size:13.5px;margin-bottom:26px}
  .voltar{color:var(--ouro);text-decoration:none;font-size:13px}

  .copia{border:1px solid var(--reg);border-radius:6px;background:var(--sup);
    padding:18px 20px;margin-bottom:14px}
  .copia h2{font-size:16px;margin:0 0 4px;font-weight:600}
  .copia .meta{color:var(--txt3);font-size:12.5px;margin-bottom:12px}
  .copia.recente{border-color:rgba(192,138,40,.5)}
  .selo{display:inline-block;background:var(--ouro);color:#231502;font-size:10px;
    font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    padding:2px 8px;border-radius:3px;margin-left:8px;vertical-align:2px}

  details{margin-bottom:12px}
  summary{cursor:pointer;color:var(--txt2);font-size:13px}
  .arqs{margin-top:10px;max-height:230px;overflow:auto;border:1px solid var(--reg);
    border-radius:4px;padding:10px 12px;background:rgba(0,0,0,.2)}
  .arq{display:flex;align-items:center;gap:9px;padding:3px 0;font-size:12.5px;
    font-family:ui-monospace,Menlo,Consolas,monospace;color:var(--txt2)}
  .arq input{accent-color:var(--ouro)}

  button{background:transparent;border:1px solid var(--reg);color:var(--txt2);
    font:inherit;font-size:13px;padding:8px 15px;border-radius:4px;cursor:pointer}
  button:hover{border-color:var(--ouro);color:var(--txt)}
  button.p{background:var(--ouro);border-color:var(--ouro);color:#231502;font-weight:700}
  button.perigo{background:var(--neg);border-color:var(--neg);color:#fff;font-weight:700}

  .aviso{border-left:2px solid var(--ouro);padding-left:12px;color:var(--ouro-cl);
    font-size:13px;margin:14px 0}
  .ok{border:1px solid rgba(87,201,139,.4);background:rgba(87,201,139,.07);
    border-radius:6px;padding:18px 20px;margin-bottom:20px}
  .ruim{border:1px solid rgba(224,87,110,.45);background:rgba(224,87,110,.08);
    border-radius:6px;padding:18px 20px;margin-bottom:20px}
  .vazio{color:var(--txt3);padding:30px;text-align:center;
    border:1px dashed var(--reg);border-radius:6px}
  code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:var(--ouro-cl)}
</style>
</head>
<body>
<div class="caixa">

  <a class="voltar" href="atualizar.php">&larr; Voltar ao atualizador</a>
  <h1>Restaurar versão anterior</h1>
  <p class="sub">Toda atualização guarda cópia dos arquivos que substituiu.
    Aqui você devolve qualquer uma delas ao lugar.</p>

  <?php if ($erro): ?>
    <div class="ruim"><b>Não deu certo</b><br><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($relatorio): ?>
    <div class="ok">
      <b><?= count($relatorio['devolvidos']) ?> arquivo(s) restaurado(s)</b>
      da cópia de <code><?= htmlspecialchars($relatorio['de']) ?></code>.
      <?php if ($relatorio['pulados']): ?>
        <br><span style="color:var(--txt2)"><?= count($relatorio['pulados']) ?>
        arquivo(s) de configuração foram preservados, de propósito.</span>
      <?php endif; ?>
      <?php if ($relatorio['falhas']): ?>
        <br><span style="color:var(--neg)"><?= count($relatorio['falhas']) ?>
        não puderam ser gravados — confira as permissões da pasta.</span>
      <?php endif; ?>
      <br><br><span style="color:var(--txt2)">O estado anterior foi guardado em
      <code><?= htmlspecialchars($relatorio['guardado']) ?></code>, então dá para voltar atrás.</span>
    </div>
  <?php endif; ?>

  <?php if (!$copias): ?>
    <p class="vazio">Nenhuma cópia guardada ainda.<br>
      As cópias aparecem aqui depois da primeira atualização feita pelo
      <a href="atualizar.php" style="color:var(--ouro)">atualizar.php</a>.</p>
  <?php else: ?>

    <div class="aviso">
      Restaurar devolve os arquivos ao estado daquela data. O que veio depois
      é substituído. Arquivos de configuração e o banco de dados não são tocados.
    </div>

    <?php foreach ($copias as $k => $c): ?>
      <form method="post" class="copia<?= $k === 0 ? ' recente' : '' ?>">
        <input type="hidden" name="acao" value="restaurar">
        <input type="hidden" name="copia" value="<?= htmlspecialchars($c['id']) ?>">

        <h2><?= htmlspecialchars($c['quando']) ?>
          <?php if ($k === 0): ?><span class="selo">mais recente</span><?php endif; ?>
        </h2>
        <p class="meta"><?= $c['total'] ?> arquivo(s) · <?= tamanho($c['bytes']) ?>
          · pasta <code><?= htmlspecialchars($c['id']) ?></code></p>

        <details>
          <summary>Ver e escolher os arquivos</summary>
          <div class="arqs">
            <?php foreach ($c['arquivos'] as $a): ?>
              <label class="arq">
                <input type="checkbox" name="arq[]" value="<?= htmlspecialchars($a) ?>" checked>
                <?= htmlspecialchars($a) ?>
                <?php if (in_array($a, PROTEGIDOS, true)): ?>
                  <span style="color:var(--ouro-cl)">· protegido, não será restaurado</span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
        </details>

        <button type="submit" class="p"
          onclick="return confirm('Restaurar os arquivos de <?= htmlspecialchars($c['quando']) ?>?\n\nO que está no ar agora será guardado antes, então dá para voltar atrás.')">
          Restaurar esta versão
        </button>
      </form>
    <?php endforeach; ?>

  <?php endif; ?>

  <p class="sub" style="margin-top:26px">
    As cópias ficam em <code>auxilio/backups/</code>. O atualizador mantém as
    últimas e apaga as mais antigas sozinho.
  </p>

</div>
</body>
</html>
