<?php
/**
 * Auxílio Graduação — API do módulo (Redentor Hub)
 * Endpoint único: api/auxilio.php?a=<acao>
 * Respostas em JSON. Uploads salvos fora do webroot lógico, servidos por ?a=arquivo.
 *
 * ========= AJUSTE ESTES 3 BLOCOS À REALIDADE DO HUB =========
 *  1) conexão PDO      2) usuário logado      3) quem é da contabilidade
 * ============================================================
 */

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../email.php';
require_once __DIR__ . '/../migracoes.php';

/* Servidor sem a extensão mbstring: as funções mb_* somem e o sistema quebra
   com "Call to undefined function". Estes atalhos mantêm tudo funcionando. */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $i, $l = null) { return $l === null ? substr((string)$s, $i) : substr((string)$s, $i, $l); }
    function mb_strtolower($s) { return strtolower((string)$s); }
    function mb_strtoupper($s) { return strtoupper((string)$s); }
    function mb_strlen($s) { return strlen((string)$s); }
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const PERC_PADRAO   = 70.00;
const DIA_PRAZO     = 3;                 // boleto deve chegar até o dia 3 do mês
const MAX_BYTES     = 5 * 1024 * 1024;   // 5 MB por arquivo
const EXT_OK        = ['pdf', 'jpg', 'jpeg', 'png'];

/* ---------- 1) CONEXÃO (credenciais em config.php) -------------------- */
function cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/../config.php';
    return $c;
}
function dirUpload(): string {
    $d = (string)(cfg()['dir_uploads'] ?? '');
    return $d !== '' ? rtrim($d, '/') : __DIR__ . '/../uploads_auxilio';
}
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg()['db'];
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4",
        $c['usuario'], $c['senha'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    // arruma o banco sozinho quando a estrutura muda
    try { garanteEstrutura($pdo); } catch (Throwable $e) { error_log('auxilio migracao: ' . $e->getMessage()); }
    return $pdo;
}

/* ---------- 2) e 3) SESSÃO E PERFIL ----------------------------------- */
/** Usuário do Redentor Hub, lido de $_SESSION['uid'] → portal_usuarios. */
function usuarioDoHub(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $tab = (string)((cfg()['hub']['tabela_usuarios']) ?? 'portal_usuarios');
        try {
            $st = db()->prepare("SELECT username, name, role, perms_json FROM `$tab` WHERE id = ? LIMIT 1");
            $st->execute([(int)$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
        } catch (Throwable $e) { $u = null; }
    }
    return $u;
}

function usuarioLogado(): ?string {
    if ($h = usuarioDoHub()) return (string)$h['username'];        // dentro do Hub
    foreach (cfg()['chaves_sessao'] as $k) {                        // entrar.php próprio
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) return $_SESSION[$k];
    }
    return null;
}

function ehContabilidade(): bool {
    if ($h = usuarioDoHub()) {
        $chave = (string)((cfg()['hub']['perm_contabilidade']) ?? 'auxilio-contab');
        $perms = json_decode((string)($h['perms_json'] ?? ''), true);
        // decisão explícita na tela de permissões vale acima do perfil,
        // inclusive para admin: desmarcou, não entra
        if (is_array($perms) && array_key_exists($chave, $perms)) {
            return $perms[$chave] === true;
        }
        return strtolower((string)$h['role']) === 'admin';
    }
    if (!empty($_SESSION['aux_contabilidade'])) return true;         // login próprio de teste
    $papel = strtolower((string)($_SESSION['perfil'] ?? $_SESSION['nivel'] ?? ''));
    if ($papel !== '' && in_array($papel, cfg()['papeis_contabilidade'], true)) return true;
    $eu = strtolower((string)usuarioLogado());
    return $eu !== '' && in_array($eu, array_map('strtolower', cfg()['admins']), true);
}

/* ---------- utilitários ---------------------------------------------- */
function responde(array $d, int $http = 200): void {
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function erro(string $msg, int $http = 400): void { responde(['ok' => false, 'erro' => $msg], $http); }
function post(string $k, $def = null) { return $_POST[$k] ?? $def; }
function inteiro($v): int { return (int)$v; }
function dinheiro($v): float { return round((float)str_replace(',', '.', (string)$v), 2); }

function registra(?int $alunoId, ?int $mensId, string $acao, string $detalhe = ''): void {
    $st = db()->prepare('INSERT INTO aux_log (aluno_id, mensalidade_id, usuario, acao, detalhe)
                         VALUES (?,?,?,?,?)');
    $st->execute([$alunoId, $mensId, usuarioLogado(), $acao, mb_substr($detalhe, 0, 300)]);
}

/** Valida a chave Pix conforme o tipo. Devolve a chave normalizada. */
function validaPix(string $tipo, string $chave): string {
    $chave = trim($chave);
    switch ($tipo) {
        case 'cpf':
            $so = preg_replace('/\D/', '', $chave);
            if (strlen((string)$so) !== 11) erro('CPF do Pix deve ter 11 dígitos.');
            return (string)$so;
        case 'email':
            if (!filter_var($chave, FILTER_VALIDATE_EMAIL)) erro('E-mail do Pix inválido.');
            return mb_strtolower($chave);
        case 'telefone':
            $so = preg_replace('/\D/', '', $chave);
            if (strlen((string)$so) < 10 || strlen((string)$so) > 11) {
                erro('Telefone do Pix deve ter DDD + número (10 ou 11 dígitos).');
            }
            return '+55' . $so;
        case 'aleatoria':
            if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', $chave)) erro('Chave aleatória inválida.');
            return mb_strtolower($chave);
    }
    erro('Tipo de chave Pix inválido.');
    return '';
}

/** Prazo do mês: dia fixo (config DIA_PRAZO), para o pagamento ser processado a tempo. */
function prazoDoMes(string $comp): string {
    return $comp . '-' . str_pad((string)DIA_PRAZO, 2, '0', STR_PAD_LEFT);
}

/** Senha do convite: padrão, com troca obrigatória no primeiro acesso. */
function senhaTemporaria(): string {
    return (string)(cfg()['senha_padrao'] ?? '1234');
}

/**
 * Garante a conta do aluno no Redentor Hub, liberada só para o card do aluno.
 * @return array{0:string,1:bool}  ['criada'|'existia'|'desligado', criou_agora]
 */
function garanteUsuarioHub(array $aluno, string $senha): array {
    $h = cfg()['hub'] ?? [];
    if (empty($h['criar_usuario'])) return ['desligado', false];
    $tab = (string)($h['tabela_usuarios'] ?? 'portal_usuarios');
    try {
        $st = db()->prepare("SELECT id FROM `$tab` WHERE username = ? LIMIT 1");
        $st->execute([$aluno['usuario']]);
        if ($st->fetch()) return ['existia', false];

        // libera só o que estiver em cards_aluno; todo o resto entra desligado
        $perms = [];
        foreach ((array)($h['cards'] ?? []) as $c) $perms[$c] = false;
        foreach ((array)($h['cards_aluno'] ?? []) as $c) $perms[$c] = true;

        db()->prepare("INSERT INTO `$tab`
            (username, name, senha_hash, role, perm_fuel, perm_drive, perm_biart, perm_dash, perms_json)
            VALUES (?,?,?,'user',0,0,0,0,?)")
            ->execute([$aluno['usuario'], $aluno['nome'],
                       password_hash($senha, PASSWORD_DEFAULT),
                       json_encode($perms, JSON_UNESCAPED_UNICODE)]);
        return ['criada', true];
    } catch (Throwable $e) {
        error_log('auxilio: falha ao criar usuario no Hub - ' . $e->getMessage());
        return ['erro:' . $e->getMessage(), false];
    }
}

/** Cria (ou renova) o acesso do aluno e manda o convite por e-mail. */
function mandaAcesso(array $aluno, bool $renovando = false): array {
    if (empty($aluno['email'])) return [false, 'aluno sem e-mail cadastrado', 'sem_email'];
    $senha = senhaTemporaria();

    [$situacao, $criou] = garanteUsuarioHub($aluno, $senha);

    // senha própria do módulo (porta alternativa, para quem não usa o Hub)
    db()->prepare('UPDATE aux_alunos SET senha_hash=?, precisa_trocar=1, acesso_enviado_em=NOW()
                   WHERE usuario=?')
        ->execute([password_hash($senha, PASSWORD_DEFAULT), $aluno['usuario']]);

    $url     = rtrim((string)(cfg()['url_sistema'] ?? ''), '/') . '/';
    $jaTinha = ($situacao === 'existia');
    $tit = $jaTinha ? 'Seu auxílio graduação já está liberado'
                    : ($renovando ? 'Sua senha foi redefinida' : 'Seu acesso ao Auxílio Graduação');

    $abre = '<p>Olá, ' . htmlspecialchars($aluno['nome']) . '.</p>'
        . '<p>A contabilidade cadastrou o seu auxílio para o curso <b>'
        . htmlspecialchars($aluno['curso']) . '</b> — a empresa custeia '
        . (int)$aluno['percentual'] . '% da mensalidade.</p>';

    if ($jaTinha) {
        $miolo = $abre . '<p>Entre no Redentor Hub com o <b>seu login de sempre</b> e abra o card '
               . '<b>🎓 Auxílio Graduação</b>.</p>';
        $texto = "Olá, {$aluno['nome']}.\n\nSeu auxílio foi cadastrado. Entre no Redentor Hub com o "
               . "seu login de sempre e abra o card Auxílio Graduação.\n$url\n";
    } else {
        $miolo = $abre
            . '<table style="margin:18px 0;border-collapse:collapse">'
            . '<tr><td style="padding:6px 14px 6px 0;color:#5b6775">Login</td>'
            . '<td style="padding:6px 0"><b>' . htmlspecialchars($aluno['usuario']) . '</b></td></tr>'
            . '<tr><td style="padding:6px 14px 6px 0;color:#5b6775">Senha</td>'
            . '<td style="padding:6px 0"><b style="font-size:17px;letter-spacing:1px">'
            . htmlspecialchars($senha) . '</b></td></tr></table>'
            . '<p><b>No primeiro acesso:</b></p>'
            . '<ol style="padding-left:18px;margin:6px 0 0">'
            . '<li>Entre no Redentor Hub com o login e a senha acima.</li>'
            . '<li>Troque a senha — ela é padrão e todo mundo a conhece.</li>'
            . '<li>O portal vai mostrar um <b>QR Code</b>. Leia com o app Google Authenticator '
            . '(ou Microsoft Authenticator) no celular e guarde os códigos de recuperação.</li>'
            . '<li>Dentro do portal, abra o card <b>🎓 Auxílio Graduação</b>.</li>'
            . '<li>Cadastre a sua <b>chave Pix</b> e anexe o <b>contrato da faculdade</b>. '
            . 'Todo mês, envie o boleto até o dia ' . DIA_PRAZO . '.</li>'
            . '</ol>'
            . '<p style="color:#5b6775;font-size:13px">Seu acesso é só ao Auxílio Graduação; '
            . 'os demais sistemas do portal não aparecem para você.</p>';
        $texto = "Olá, {$aluno['nome']}.\n\nLogin: {$aluno['usuario']}\nSenha: $senha\n\n"
               . "1) Entre no Redentor Hub com esses dados e troque a senha.\n"
               . "2) Leia o QR Code com o Google Authenticator e guarde os códigos de recuperação.\n"
               . "3) Abra o card Auxílio Graduação e cadastre a chave Pix e o contrato.\n"
               . "O boleto de cada mês deve ser enviado até o dia " . DIA_PRAZO . ".\n\n$url\n";
    }

    $miolo .= '<p style="background:#fff6e0;border-left:3px solid #D9A93F;padding:10px 14px;'
            . 'border-radius:6px;margin-top:18px">O boleto de cada mês deve ser enviado até o '
            . '<b>dia ' . DIA_PRAZO . '</b>, para dar tempo de processar o pagamento. Depois disso a '
            . 'parcela fica registrada como <b>em atraso</b>.</p>';

    [$ok, $erro] = enviaEmail(cfg(), (string)$aluno['email'], "Auxílio Graduação — $tit",
                              $texto, moldeEmail($tit, $miolo, $url));
    registra((int)$aluno['id'], null, 'acesso',
             ($ok ? 'convite enviado' : "falha ao enviar: $erro") . " (conta no Hub: $situacao)");
    return [$ok, $erro, $situacao];
}

/** Todos os contratos (cursos) da pessoa logada. */
function contratosDoUsuario(): array {
    $st = db()->prepare('SELECT * FROM aux_alunos WHERE usuario = ? ORDER BY status, curso');
    $st->execute([usuarioLogado()]);
    return $st->fetchAll();
}

/** Um contrato específico da pessoa logada — ou o primeiro, se não vier id. */
function alunoDoUsuario(?int $id = null): ?array {
    $lista = contratosDoUsuario();
    if (!$lista) return null;
    if ($id) {
        foreach ($lista as $a) if ((int)$a['id'] === $id) return $a;
        erro('Este curso não pertence ao seu cadastro.', 403);
    }
    return $lista[0];
}

/** Gera as parcelas ainda inexistentes do aluno. */
function geraParcelas(array $aluno): int {
    [$ano, $mes] = array_map('intval', explode('-', $aluno['inicio_competencia']));
    $dia   = max(1, min(28, (int)$aluno['dia_vencimento']));
    $criadas = 0;
    $st = db()->prepare('INSERT IGNORE INTO aux_mensalidades
        (aluno_id, parcela, competencia, vencimento, prazo_envio) VALUES (?,?,?,?,?)');
    for ($i = 0; $i < (int)$aluno['qtd_mensalidades']; $i++) {
        $t    = mktime(0, 0, 0, $mes + $i, 1, $ano);
        $comp = date('Y-m', $t);
        $st->execute([
            $aluno['id'], $i + 1, $comp,
            date('Y-m-', $t) . str_pad((string)$dia, 2, '0', STR_PAD_LEFT),
            prazoDoMes($comp),
        ]);
        $criadas += $st->rowCount();
    }
    return $criadas;
}

function salvaArquivo(string $campo, int $alunoId, string $prefixo): string {
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
        erro('Nenhum arquivo recebido. Selecione um PDF ou imagem e tente de novo.');
    }
    $f = $_FILES[$campo];
    if ($f['size'] > MAX_BYTES) erro('Arquivo acima de 5 MB. Reduza e envie novamente.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXT_OK, true)) erro('Formato não aceito. Use PDF, JPG ou PNG.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        erro('O conteúdo do arquivo não confere com a extensão.');
    }
    $dir = dirUpload() . '/' . $alunoId;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $nome = $prefixo . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nome")) erro('Falha ao gravar o arquivo no servidor.', 500);
    return "$alunoId/$nome";
}

/* ---------- roteador -------------------------------------------------- */
if (!usuarioLogado()) erro('Sessão expirada. Faça login novamente.', 401);
$acao  = $_GET['a'] ?? '';
$admin = ehContabilidade();

try {
    switch ($acao) {

    /* ---------- comum ---------- */
    case 'sessao':
        responde(['ok' => true, 'usuario' => usuarioLogado(), 'contabilidade' => $admin,
                  'hoje' => date('Y-m-d'), 'competencia' => date('Y-m'),
                  'prazo' => prazoDoMes(date('Y-m')), 'dia_prazo' => DIA_PRAZO,
                  'teste' => !empty($_SESSION['aux_usuario']),
                  'no_hub' => (bool)usuarioDoHub(),
                  'trocar_senha' => !empty($_SESSION['aux_trocar_senha'])]);

    /* ---------- painel do aluno ---------- */
    case 'meu_painel': {
        $lista = contratosDoUsuario();
        if (!$lista) responde(['ok' => true, 'contratos' => []]);
        $st = db()->prepare('SELECT * FROM aux_mensalidades WHERE aluno_id = ? ORDER BY competencia');
        $out = [];
        foreach ($lista as $a) {
            unset($a['observacao'], $a['criado_por']);
            $st->execute([$a['id']]);
            $a['parcelas'] = $st->fetchAll();
            $out[] = $a;
        }
        responde(['ok' => true, 'contratos' => $out]);
    }

    /* Quantas horas a senha do convite ainda vale, para a tela avisar. */
    case 'estado_senha': {
        $aluno = alunoDoUsuario();
        if (!$aluno) responde(['ok' => true, 'pendente' => false]);
        $horas = (int)(cfg()['horas_senha_padrao'] ?? 48);
        $venc = (!empty($aluno['acesso_enviado_em']) && $horas > 0)
              ? strtotime((string)$aluno['acesso_enviado_em']) + $horas * 3600 : 0;
        responde(['ok' => true,
                  'pendente' => !empty($aluno['precisa_trocar']),
                  'vence_em' => $venc ? max(0, (int)round(($venc - time()) / 3600)) : null]);
    }

    case 'trocar_senha': {
        $aluno = alunoDoUsuario();
        if (!$aluno) erro('Cadastro não encontrado.', 404);
        $nova = (string)post('nova');
        if (strlen($nova) < 8) erro('A senha precisa ter pelo menos 8 caracteres.');
        if (!preg_match('/[A-Za-z]/', $nova) || !preg_match('/\d/', $nova)) {
            erro('Use letras e números na senha.');
        }
        db()->prepare('UPDATE aux_alunos SET senha_hash=?, precisa_trocar=0 WHERE usuario=?')
            ->execute([password_hash($nova, PASSWORD_DEFAULT), $aluno['usuario']]);
        registra((int)$aluno['id'], null, 'senha', 'senha alterada pelo aluno');
        responde(['ok' => true]);
    }

    case 'salvar_pix': {
        $aluno = $admin && post('aluno_id')
               ? db()->query('SELECT * FROM aux_alunos WHERE id=' . inteiro(post('aluno_id')))->fetch()
               : alunoDoUsuario();
        if (!$aluno) erro('Cadastro de auxílio não encontrado.', 404);
        $tipo  = (string)post('pix_tipo');
        $chave = validaPix($tipo, (string)post('pix_chave'));
        // monta o SET só com o que veio preenchido — sem comparar literais na SQL,
        // que é o que provoca conflito de collation entre conexão e tabela
        $campos = ['pix_tipo=?', 'pix_chave=?', 'pix_atualizado_em=NOW()'];
        $vals   = [$tipo, $chave];
        $mail   = trim((string)post('email', ''));
        $fone   = trim((string)post('telefone', ''));
        if ($mail !== '') { $campos[] = 'email=?';    $vals[] = $mail; }
        if ($fone !== '') { $campos[] = 'telefone=?'; $vals[] = $fone; }
        // a chave é da pessoa, então vale para todos os cursos dela
        $vals[] = $aluno['usuario'];
        db()->prepare('UPDATE aux_alunos SET ' . implode(', ', $campos) . ' WHERE usuario=?')
            ->execute($vals);
        registra((int)$aluno['id'], null, 'pix', "chave Pix ($tipo) cadastrada/alterada");
        responde(['ok' => true, 'pix_chave' => $chave, 'pix_tipo' => $tipo]);
    }

    case 'meu_contrato': {
        $aluno = alunoDoUsuario(inteiro(post('aluno_id')) ?: null);
        if (!$aluno) erro('Cadastro de auxílio não encontrado.', 404);
        $arq = salvaArquivo('arquivo', (int)$aluno['id'], 'contrato');
        db()->prepare('UPDATE aux_alunos SET contrato_arquivo=?, contrato_enviado_em=NOW() WHERE id=?')
            ->execute([$arq, $aluno['id']]);
        registra((int)$aluno['id'], null, 'contrato', 'contrato enviado pelo próprio aluno');
        responde(['ok' => true]);
    }

    case 'enviar_boleto':
    case 'enviar_comprovante': {
        $aluno = $admin && post('aluno_id')
               ? db()->query('SELECT * FROM aux_alunos WHERE id=' . inteiro(post('aluno_id')))->fetch()
               : alunoDoUsuario(inteiro(post('aluno_id')) ?: null);
        if (!$aluno) erro('Cadastro de auxílio não encontrado.', 404);
        $mid = inteiro(post('mensalidade_id'));
        $st  = db()->prepare('SELECT * FROM aux_mensalidades WHERE id = ? AND aluno_id = ?');
        $st->execute([$mid, $aluno['id']]);
        $m = $st->fetch();
        if (!$m) erro('Mensalidade não encontrada.', 404);

        if ($acao === 'enviar_boleto') {
            if (empty($aluno['pix_chave'])) {
                erro('Cadastre sua chave Pix antes de enviar o primeiro boleto.');
            }
            if (in_array($m['status'], ['aprovado', 'pago', 'concluido'], true)) {
                erro('Esta mensalidade já foi aprovada. Fale com a contabilidade para substituir o boleto.');
            }
            // Meses futuros ficam abertos: quem já tem o carnê da faculdade
            // pode adiantar o envio em vez de esperar virar o mês.
            $arq      = salvaArquivo('arquivo', (int)$aluno['id'], 'boleto');
            $atrasado = (date('Y-m-d') > $m['prazo_envio']) ? 1 : 0;

            // Sem etapa de conferência: o valor informado pelo aluno já define
            // a parcela. A empresa paga sobre a mensalidade contratada — multa
            // e juros por atraso dele não entram na base.
            $valor = post('valor') !== null && post('valor') !== ''
                   ? dinheiro(post('valor')) : (float)$aluno['valor_mensalidade'];
            if ($valor <= 0) $valor = (float)$aluno['valor_mensalidade'];
            $ref   = (float)$aluno['valor_mensalidade'];
            $base  = ($ref > 0 && $valor > $ref) ? $ref : $valor;
            $emp   = round($base * (float)$aluno['percentual'] / 100, 2);
            $obs   = null;
            if ($base < $valor) {
                $obs = 'Boleto de ' . number_format($valor, 2, ',', '.')
                     . ' acima da mensalidade contratada (' . number_format($ref, 2, ',', '.')
                     . '); os ' . (int)$aluno['percentual'] . '% saíram sobre a mensalidade.';
            }
            db()->prepare('UPDATE aux_mensalidades SET boleto_arquivo=?, boleto_enviado_em=NOW(),
                           boleto_atrasado=?, valor_boleto=?, valor_empresa=?, valor_aluno=?,
                           status="aprovado", observacao=?, analisado_por=NULL, analisado_em=NOW()
                           WHERE id=?')
                ->execute([$arq, $atrasado, $valor, $emp, round($valor - $emp, 2), $obs, $mid]);
            registra((int)$aluno['id'], $mid, 'boleto',
                     ($atrasado ? 'enviado com atraso' : 'enviado no prazo') . '; aguardando repasse');
            responde(['ok' => true, 'atrasado' => (bool)$atrasado, 'valor_empresa' => $emp]);
        }

        if (!in_array($m['status'], ['pago', 'concluido'], true)) {
            erro('O comprovante só pode ser anexado depois que a contabilidade registrar o repasse.');
        }
        $arq = salvaArquivo('arquivo', (int)$aluno['id'], 'comprovante');
        db()->prepare('UPDATE aux_mensalidades SET comprovante_arquivo=?, comprovante_enviado_em=NOW(),
                       status="concluido" WHERE id=?')->execute([$arq, $mid]);
        registra((int)$aluno['id'], $mid, 'comprovante', 'comprovante de pagamento anexado');
        responde(['ok' => true]);
    }

    /* ---------- arquivos ---------- */
    case 'arquivo': {
        $tipo = $_GET['tipo'] ?? '';
        $rel  = null; $donoId = null;
        if ($tipo === 'contrato') {
            $st = db()->prepare('SELECT id, usuario, contrato_arquivo FROM aux_alunos WHERE id=?');
            $st->execute([inteiro($_GET['id'] ?? 0)]);
            if ($r = $st->fetch()) { $rel = $r['contrato_arquivo']; $donoId = $r['usuario']; }
        } else {
            $col = $tipo === 'comprovante' ? 'comprovante_arquivo' : 'boleto_arquivo';
            $st  = db()->prepare("SELECT m.$col AS arq, a.usuario FROM aux_mensalidades m
                                  JOIN aux_alunos a ON a.id = m.aluno_id WHERE m.id = ?");
            $st->execute([inteiro($_GET['id'] ?? 0)]);
            if ($r = $st->fetch()) { $rel = $r['arq']; $donoId = $r['usuario']; }
        }
        if (!$rel) erro('Arquivo não encontrado.', 404);
        if (!$admin && $donoId !== usuarioLogado()) erro('Sem permissão para este arquivo.', 403);
        $caminho = dirUpload() . '/' . $rel;
        if (!is_file($caminho)) erro('Arquivo não encontrado no servidor.', 404);
        header_remove('Content-Type');
        header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($caminho));
        header('Content-Disposition: inline; filename="' . basename($caminho) . '"');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    }
    }

    /* ---------- daqui para baixo, só contabilidade ---------- */
    if (!$admin) erro('Área restrita à contabilidade.', 403);

    switch ($acao) {

    case 'usuarios_hub': {
        // As duas tabelas podem ter collations diferentes, e comparar
        // usuario = username dentro da SQL derruba a consulta. Junta em PHP.
        $tab = (string)((cfg()['hub']['tabela_usuarios']) ?? 'portal_usuarios');
        try {
            // SELECT * para não quebrar se a tabela do portal não tiver alguma coluna
            $users = db()->query("SELECT * FROM `$tab` ORDER BY name")->fetchAll();
            $users = array_map(fn($u) => [
                'username' => $u['username'] ?? '',
                'name'     => $u['name'] ?? ($u['username'] ?? ''),
                'telefone' => $u['telefone'] ?? '',
                'role'     => $u['role'] ?? 'user',
            ], $users);
        } catch (Throwable $e) {
            responde(['ok' => true, 'usuarios' => [], 'aviso' => 'Sem lista do Hub: ' . $e->getMessage()]);
        }
        $porUsuario = [];
        foreach (db()->query('SELECT usuario, COUNT(*) n, MAX(email) email
                              FROM aux_alunos GROUP BY usuario')->fetchAll() as $r) {
            $porUsuario[mb_strtolower((string)$r['usuario'])] = $r;
        }
        foreach ($users as &$u) {
            $k = mb_strtolower((string)$u['username']);
            $u['cursos'] = isset($porUsuario[$k]) ? (int)$porUsuario[$k]['n'] : 0;
            $u['email']  = $porUsuario[$k]['email'] ?? null;
        }
        unset($u);
        responde(['ok' => true, 'usuarios' => $users]);
    }

    case 'alunos': {
        $sql = 'SELECT a.*,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id) AS total,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id AND m.status="concluido") AS quitadas,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id AND m.status="em_analise") AS pendentes,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id AND m.boleto_enviado_em IS NOT NULL) AS enviados,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id AND m.boleto_atrasado=1) AS atrasos,
                  (SELECT COUNT(*) FROM aux_mensalidades m WHERE m.aluno_id=a.id
                     AND m.status="aguardando_boleto" AND m.prazo_envio < CURDATE()) AS vencidas
                FROM aux_alunos a ORDER BY a.nome';
        responde(['ok' => true, 'alunos' => db()->query($sql)->fetchAll()]);
    }

    case 'salvar_aluno': {
        $id = inteiro(post('id'));
        $d  = [
            trim((string)post('usuario')), trim((string)post('nome')), post('matricula'), post('setor'),
            post('email'), post('telefone'),
            trim((string)post('instituicao')), trim((string)post('curso')),
            dinheiro(post('valor_mensalidade')), dinheiro(post('percentual', PERC_PADRAO)),
            inteiro(post('qtd_mensalidades')), inteiro(post('dia_vencimento', 10)),
            (string)post('inicio_competencia'), (string)post('status', 'ativo'), post('observacao'),
        ];
        if ($d[0] === '' || $d[1] === '' || $d[10] < 1 || !preg_match('/^\d{4}-\d{2}$/', $d[12])) {
            erro('Preencha login, nome, quantidade de mensalidades e a competência inicial (AAAA-MM).');
        }
        if ($id) {
            db()->prepare('UPDATE aux_alunos SET usuario=?,nome=?,matricula=?,setor=?,email=?,telefone=?,instituicao=?,curso=?,
                valor_mensalidade=?,percentual=?,qtd_mensalidades=?,dia_vencimento=?,inicio_competencia=?,
                status=?,observacao=? WHERE id=?')->execute([...$d, $id]);
        } else {
            $st = db()->prepare('INSERT INTO aux_alunos (usuario,nome,matricula,setor,email,telefone,
                instituicao,curso,valor_mensalidade,percentual,qtd_mensalidades,dia_vencimento,
                inicio_competencia,status,observacao,criado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([...$d, usuarioLogado()]);
            $id = (int)db()->lastInsertId();
        }
        $al = db()->query("SELECT * FROM aux_alunos WHERE id=$id")->fetch();
        $n  = geraParcelas($al);
        registra($id, null, 'cadastro', "aluno salvo; $n parcela(s) geradas");

        // primeiro cadastro da pessoa (ainda sem senha) → manda login e senha
        $aviso = '';
        if (empty($al['senha_hash'])) {
            if (empty($al['email'])) {
                $aviso = 'Aluno sem e-mail: o acesso não foi enviado. Preencha o e-mail e use "Reenviar acesso".';
            } else {
                [$ok, $erro, $sit] = mandaAcesso($al);
                if (!$ok) {
                    $aviso = "Cadastro salvo, mas o e-mail falhou: $erro";
                } elseif ($sit === 'criada') {
                    $aviso = "Conta criada no Hub e convite enviado para {$al['email']}. "
                           . "O aluno vê apenas o card do Auxílio Graduação.";
                } elseif ($sit === 'existia') {
                    $aviso = "{$al['nome']} já tinha conta no Hub — avisamos por e-mail. "
                           . "Confira se o card do Auxílio está liberado para ele em Configurações.";
                } else {
                    $aviso = "Convite enviado para {$al['email']}.";
                }
            }
        }
        responde(['ok' => true, 'id' => $id, 'parcelas_geradas' => $n, 'aviso' => $aviso]);
    }

    case 'enviar_contrato': {
        $id  = inteiro(post('aluno_id'));
        if (!db()->query("SELECT 1 FROM aux_alunos WHERE id=$id")->fetchColumn()) erro('Aluno não encontrado.', 404);
        $arq = salvaArquivo('arquivo', $id, 'contrato');
        db()->prepare('UPDATE aux_alunos SET contrato_arquivo=?, contrato_enviado_em=NOW() WHERE id=?')
            ->execute([$arq, $id]);
        registra($id, null, 'contrato', 'contrato da faculdade anexado');
        responde(['ok' => true]);
    }

    case 'mensalidades': {
        $w = []; $p = [];
        if (!empty($_GET['aluno_id']))    { $w[] = 'm.aluno_id = ?';    $p[] = inteiro($_GET['aluno_id']); }
        if (!empty($_GET['competencia'])) { $w[] = 'm.competencia = ?'; $p[] = $_GET['competencia']; }
        if (!empty($_GET['status']))      { $w[] = 'm.status = ?';      $p[] = $_GET['status']; }
        $sql = 'SELECT m.*, a.nome, a.usuario, a.curso, a.instituicao, a.percentual, a.valor_mensalidade,
                       a.pix_tipo, a.pix_chave, a.pix_atualizado_em, a.email
                FROM aux_mensalidades m JOIN aux_alunos a ON a.id = m.aluno_id'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY m.competencia DESC, a.nome LIMIT ' . (inteiro($_GET['limite'] ?? 50) + 1)
             . ' OFFSET ' . inteiro($_GET['offset'] ?? 0);
        $st = db()->prepare($sql); $st->execute($p);
        $linhas = $st->fetchAll();
        $lim    = inteiro($_GET['limite'] ?? 50);
        $mais   = count($linhas) > $lim;
        if ($mais) array_pop($linhas);
        responde(['ok' => true, 'mensalidades' => $linhas, 'tem_mais' => $mais]);
    }

    case 'avaliar': {
        $mid = inteiro(post('mensalidade_id'));
        $dec = (string)post('decisao');            // aprovar | rejeitar | pagar
        $st  = db()->prepare('SELECT m.*, a.percentual, a.valor_mensalidade, a.nome
                              FROM aux_mensalidades m
                              JOIN aux_alunos a ON a.id=m.aluno_id WHERE m.id=?');
        $st->execute([$mid]);
        $m = $st->fetch();
        if (!$m) erro('Mensalidade não encontrada.', 404);
        $obs = mb_substr(trim((string)post('observacao', '')), 0, 500) ?: null;

        if ($dec === 'aprovar') {
            $valor = post('valor_boleto') !== null && post('valor_boleto') !== ''
                   ? dinheiro(post('valor_boleto')) : (float)$m['valor_boleto'];
            if ($valor <= 0) erro('Informe o valor do boleto para aprovar.');
            // a empresa paga sobre a mensalidade contratada — multa e juros por
            // atraso do aluno não entram na base
            $ref  = (float)$m['valor_mensalidade'];
            $base = ($ref > 0 && $valor > $ref) ? $ref : $valor;
            if ($base < $valor) {
                $extra = 'Boleto de ' . number_format($valor, 2, ',', '.')
                       . ' acima da mensalidade contratada (' . number_format($ref, 2, ',', '.')
                       . '); os 70% foram calculados sobre a mensalidade.';
                $obs = $obs ? $obs . ' | ' . $extra : $extra;
            }
            $emp = round($base * (float)$m['percentual'] / 100, 2);
            db()->prepare('UPDATE aux_mensalidades SET status="aprovado", valor_boleto=?, valor_empresa=?,
                           valor_aluno=?, observacao=?, analisado_por=?, analisado_em=NOW() WHERE id=?')
                ->execute([$valor, $emp, round($valor - $emp, 2), $obs, usuarioLogado(), $mid]);
        } elseif ($dec === 'rejeitar') {
            if (!$obs) erro('Escreva o motivo da recusa — o aluno precisa saber o que corrigir.');
            db()->prepare('UPDATE aux_mensalidades SET status="rejeitado", observacao=?,
                           analisado_por=?, analisado_em=NOW() WHERE id=?')
                ->execute([$obs, usuarioLogado(), $mid]);
        } elseif ($dec === 'pagar') {
            if (!in_array($m['status'], ['aprovado', 'em_analise'], true)) {
                erro('Só é possível registrar o repasse de parcela com boleto enviado.');
            }
            // parcela antiga que ficou em análise: calcula agora, na hora do repasse
            if ($m['status'] === 'em_analise' && (float)$m['valor_empresa'] <= 0) {
                $v    = (float)$m['valor_boleto'] > 0 ? (float)$m['valor_boleto'] : (float)$m['valor_mensalidade'];
                $ref  = (float)$m['valor_mensalidade'];
                $base = ($ref > 0 && $v > $ref) ? $ref : $v;
                $emp  = round($base * (float)$m['percentual'] / 100, 2);
                db()->prepare('UPDATE aux_mensalidades SET valor_boleto=?, valor_empresa=?, valor_aluno=?
                               WHERE id=?')->execute([$v, $emp, round($v - $emp, 2), $mid]);
            }
            db()->prepare('UPDATE aux_mensalidades SET status="pago", pago_em=?, observacao=COALESCE(?,observacao)
                           WHERE id=?')->execute([post('pago_em') ?: date('Y-m-d'), $obs, $mid]);
        } else {
            erro('Ação inválida.');
        }
        registra((int)$m['aluno_id'], $mid, $dec, $obs ?? '');
        responde(['ok' => true]);
    }

    case 'recalcular_prazos': {
        $n = 0;
        $st = db()->query('SELECT id, competencia, prazo_envio FROM aux_mensalidades');
        $up = db()->prepare('UPDATE aux_mensalidades SET prazo_envio=? WHERE id=?');
        foreach ($st->fetchAll() as $m) {
            $novo = prazoDoMes($m['competencia']);
            if ($novo !== $m['prazo_envio']) { $up->execute([$novo, $m['id']]); $n++; }
        }
        responde(['ok' => true, 'ajustadas' => $n]);
    }

    case 'excluir_aluno': {
        $id = inteiro(post('aluno_id'));
        if ((string)post('confirmacao') !== 'EXCLUIR') erro('Confirmação não conferiu.');
        $al = db()->query("SELECT * FROM aux_alunos WHERE id=$id")->fetch();
        if (!$al) erro('Aluno não encontrado.', 404);

        // apaga só o curso; mensalidades saem junto pela chave estrangeira
        db()->prepare('DELETE FROM aux_alunos WHERE id=?')->execute([$id]);
        registra(null, null, 'exclusao',
                 "curso {$al['curso']} de {$al['usuario']} excluído por " . usuarioLogado());

        // sobrou algum curso? se não, tira só o acesso ao card do aluno.
        // A conta no Redentor Hub continua existindo, com os demais sistemas.
        $st = db()->prepare('SELECT COUNT(*) FROM aux_alunos WHERE usuario = ?');
        $st->execute([$al['usuario']]);
        $sobrou  = (int)$st->fetchColumn();
        $tirouEm = '';
        if ($sobrou === 0) {
            $h   = cfg()['hub'] ?? [];
            $tab = (string)($h['tabela_usuarios'] ?? 'portal_usuarios');
            try {
                $u = db()->prepare("SELECT id, perms_json FROM `$tab` WHERE username = ? LIMIT 1");
                $u->execute([$al['usuario']]);
                if ($linha = $u->fetch()) {
                    $perms = json_decode((string)$linha['perms_json'], true);
                    if (!is_array($perms)) $perms = [];
                    foreach ((array)($h['cards_aluno'] ?? ['auxilio']) as $c) $perms[$c] = false;
                    db()->prepare("UPDATE `$tab` SET perms_json = ? WHERE id = ?")
                        ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), (int)$linha['id']]);
                    $tirouEm = 'acesso ao card do aluno removido; conta do Hub mantida';
                }
            } catch (Throwable $e) { $tirouEm = 'não consegui ajustar a permissão: ' . $e->getMessage(); }
        }
        responde(['ok' => true, 'restantes' => $sobrou, 'aviso' => $tirouEm]);
    }

    case 'reenviar_acesso': {
        $id = inteiro(post('aluno_id'));
        $al = db()->query("SELECT * FROM aux_alunos WHERE id=$id")->fetch();
        if (!$al) erro('Aluno não encontrado.', 404);
        [$ok, $erro] = mandaAcesso($al, true);
        if (!$ok) erro('Não foi possível enviar: ' . $erro);
        responde(['ok' => true, 'email' => $al['email']]);
    }

    case 'exportar': {
        $comp = (string)($_GET['competencia'] ?? date('Y-m'));
        $st = db()->prepare('SELECT a.nome, a.matricula, a.setor, a.email, a.telefone, a.curso,
                a.instituicao, a.pix_tipo, a.pix_chave, m.competencia, m.vencimento, m.prazo_envio,
                m.boleto_enviado_em, m.boleto_atrasado, m.valor_boleto, m.valor_empresa, m.valor_aluno,
                m.status, m.pago_em, m.observacao
                FROM aux_mensalidades m JOIN aux_alunos a ON a.id=m.aluno_id
                WHERE m.competencia=? ORDER BY a.nome');
        $st->execute([$comp]);
        $linhas = $st->fetchAll();
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="auxilio-' . $comp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                       // BOM: Excel abre com acento certo
        fputcsv($out, ['Nome','Matricula','Setor','E-mail','Telefone','Curso','Instituicao',
            'Tipo Pix','Chave Pix','Competencia','Vencimento','Prazo (5o dia util)','Boleto enviado em',
            'Atrasado','Valor boleto','Valor empresa','Valor aluno','Situacao','Pago em','Observacao'], ';');
        foreach ($linhas as $l) {
            $l['boleto_atrasado'] = $l['boleto_atrasado'] ? 'SIM' : 'nao';
            foreach (['valor_boleto','valor_empresa','valor_aluno'] as $c) {
                $l[$c] = $l[$c] === null ? '' : number_format((float)$l[$c], 2, ',', '');
            }
            fputcsv($out, array_values($l), ';');
        }
        fclose($out);
        exit;
    }

    case 'resumo': {
        $comp = $_GET['competencia'] ?? date('Y-m');
        $st = db()->prepare('SELECT status, COUNT(*) q, COALESCE(SUM(valor_empresa),0) emp,
                             COALESCE(SUM(boleto_atrasado),0) atr
                             FROM aux_mensalidades WHERE competencia=? GROUP BY status');
        $st->execute([$comp]);
        $porStatus = $st->fetchAll();
        $ativos = (int)db()->query('SELECT COUNT(*) FROM aux_alunos WHERE status="ativo"')->fetchColumn();
        $semPix = (int)db()->query('SELECT COUNT(*) FROM aux_alunos WHERE status="ativo"
                                    AND (pix_chave IS NULL OR CHAR_LENGTH(pix_chave)=0)')->fetchColumn();
        $st2 = db()->prepare('SELECT competencia, COALESCE(SUM(valor_empresa),0) total
                              FROM aux_mensalidades WHERE valor_empresa IS NOT NULL
                              GROUP BY competencia ORDER BY competencia DESC LIMIT 12');
        $st2->execute();
        responde(['ok' => true, 'competencia' => $comp, 'alunos_ativos' => $ativos, 'sem_pix' => $semPix,
                  'por_status' => $porStatus, 'serie' => array_reverse($st2->fetchAll())]);
    }

    default:
        erro('Ação desconhecida.', 404);
    }
} catch (Throwable $e) {
    erro('Erro no servidor: ' . $e->getMessage(), 500);
}
