<?php
/**
 * espelho_dados.php — salas e agenda em tabelas de verdade.
 *
 * Por que existe: esses dois sistemas gravavam a lista inteira como um único
 * texto JSON. Quem salvasse por último apagava o que o outro tinha acabado de
 * criar, sem deixar rastro — e não havia como consultar nada por SQL.
 *
 * O que este arquivo faz, sem tocar nas telas:
 *   - ao GRAVAR, explode a lista em linhas (uma por reserva/compromisso);
 *   - devolve a lista já mesclada, preservando o que outra pessoa criou
 *     enquanto a tela de quem está salvando estava aberta;
 *   - ao LER, remonta a lista a partir das tabelas.
 *
 * O JSON continua sendo gravado em portal_dados como cópia de segurança,
 * então nada se perde se algo aqui falhar.
 */
declare(strict_types=1);

/** Janela em que a criação de outra pessoa é protegida contra sobrescrita. */
const ESPELHO_JANELA_MIN = 15;

function espelhoTabelas(mysqli $db): void {
    static $feito = false;
    if ($feito) return;
    $db->query("CREATE TABLE IF NOT EXISTS portal_salas_reservas (
        id CHAR(32) PRIMARY KEY,
        sala VARCHAR(40) NOT NULL DEFAULT '',
        data DATE NULL,
        inicio VARCHAR(5) NOT NULL DEFAULT '',
        fim VARCHAR(5) NOT NULL DEFAULT '',
        responsavel VARCHAR(120) NOT NULL DEFAULT '',
        evento VARCHAR(200) NOT NULL DEFAULT '',
        extra MEDIUMTEXT NULL,
        criado_por VARCHAR(60) NULL,
        atualizado_por VARCHAR(60) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY ix_sala_data (sala, data),
        KEY ix_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS portal_agenda (
        id CHAR(32) PRIMARY KEY,
        usuario VARCHAR(60) NOT NULL DEFAULT '',
        titulo VARCHAR(200) NOT NULL DEFAULT '',
        data DATE NULL,
        inicio VARCHAR(5) NOT NULL DEFAULT '',
        fim VARCHAR(5) NOT NULL DEFAULT '',
        local VARCHAR(160) NOT NULL DEFAULT '',
        obs TEXT NULL,
        extra MEDIUMTEXT NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY ix_ag_usuario (usuario, data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $feito = true;
}

/** Identidade da linha: usa o _id que o servidor devolveu antes; senão, deriva dos campos. */
function espelhoId(array $it, string $tipo, string $dono = ''): string {
    if (!empty($it['_id']) && preg_match('/^[a-f0-9]{32}$/', (string)$it['_id'])) return (string)$it['_id'];
    $base = $tipo === 'salas'
        ? ($it['salaId'] ?? '') . '|' . ($it['data'] ?? '') . '|' . ($it['inicio'] ?? '') . '|' . ($it['evento'] ?? '')
        : $dono . '|' . ($it['data'] ?? '') . '|' . ($it['inicio'] ?? '') . '|' . ($it['titulo'] ?? '');
    return md5($tipo . '|' . $base);
}

/** Campos que ganham coluna própria; o resto vai para "extra", sem perder nada. */
function espelhoExtra(array $it, array $colunas): ?string {
    $resto = array_diff_key($it, array_flip(array_merge($colunas, ['_id'])));
    return $resto ? json_encode($resto, JSON_UNESCAPED_UNICODE) : null;
}
function espelhoMonta(array $linha, array $mapa): array {
    $it = [];
    foreach ($mapa as $col => $campo) {
        $v = $linha[$col];
        if ($v === null) $v = '';
        $it[$campo] = $v;
    }
    if (!empty($linha['extra'])) {
        $x = json_decode((string)$linha['extra'], true);
        if (is_array($x)) $it = array_merge($it, $x);
    }
    $it['_id'] = $linha['id'];
    return $it;
}

/* ================= SALAS ================= */

function espelhoSalasLer(mysqli $db): array {
    espelhoTabelas($db);
    $out = [];
    $r = $db->query("SELECT * FROM portal_salas_reservas ORDER BY data, inicio");
    while ($l = $r->fetch_assoc()) {
        $out[] = espelhoMonta($l, ['sala' => 'salaId', 'data' => 'data', 'inicio' => 'inicio',
                                   'fim' => 'fim', 'responsavel' => 'responsavel', 'evento' => 'evento']);
    }
    return $out;
}

/**
 * Grava a lista recebida e devolve a lista final (mesclada).
 * @return array{0:array,1:array} [lista final, resumo do que aconteceu]
 */
function espelhoSalasGravar(mysqli $db, array $lista, string $quem): array {
    espelhoTabelas($db);
    $cols = ['salaId', 'data', 'inicio', 'fim', 'responsavel', 'evento'];
    $recebidos = [];

    $up = $db->prepare("INSERT INTO portal_salas_reservas
        (id, sala, data, inicio, fim, responsavel, evento, extra, criado_por, atualizado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          atualizado_por = IF(sala <=> VALUES(sala) AND data <=> VALUES(data)
                              AND inicio <=> VALUES(inicio) AND fim <=> VALUES(fim)
                              AND responsavel <=> VALUES(responsavel) AND evento <=> VALUES(evento)
                              AND extra <=> VALUES(extra),
                              atualizado_por, VALUES(atualizado_por)),
          sala=VALUES(sala), data=VALUES(data), inicio=VALUES(inicio),
          fim=VALUES(fim), responsavel=VALUES(responsavel), evento=VALUES(evento),
          extra=VALUES(extra)");

    foreach ($lista as $it) {
        if (!is_array($it)) continue;
        $id = espelhoId($it, 'salas');
        $recebidos[$id] = true;
        $sala  = (string)($it['salaId'] ?? '');
        $data  = ($it['data'] ?? '') !== '' ? (string)$it['data'] : null;
        $ini   = (string)($it['inicio'] ?? '');
        $fim   = (string)($it['fim'] ?? '');
        $resp  = (string)($it['responsavel'] ?? '');
        $ev    = (string)($it['evento'] ?? '');
        $extra = espelhoExtra($it, $cols);
        $up->bind_param('ssssssssss', $id, $sala, $data, $ini, $fim, $resp, $ev, $extra, $quem, $quem);
        $up->execute();
    }

    /* O que está no banco e não veio na lista: em regra foi apagado por quem
       salvou. Mas se outra pessoa criou nos últimos minutos, a tela de quem
       está salvando não sabia dessa reserva — então ela é preservada. */
    $preservadas = [];
    $r = $db->query("SELECT * FROM portal_salas_reservas");
    $apagar = [];
    while ($l = $r->fetch_assoc()) {
        if (isset($recebidos[$l['id']])) continue;
        $recente = (strtotime((string)$l['atualizado_em']) > time() - ESPELHO_JANELA_MIN * 60);
        $deOutro = ((string)$l['atualizado_por'] !== $quem);
        if ($recente && $deOutro) {
            $preservadas[] = espelhoMonta($l, ['sala' => 'salaId', 'data' => 'data', 'inicio' => 'inicio',
                                               'fim' => 'fim', 'responsavel' => 'responsavel', 'evento' => 'evento']);
        } else {
            $apagar[] = $l['id'];
        }
    }
    if ($apagar) {
        $ids = "'" . implode("','", array_map([$db, 'real_escape_string'], $apagar)) . "'";
        $db->query("DELETE FROM portal_salas_reservas WHERE id IN ($ids)");
    }

    return [espelhoSalasLer($db),
            ['gravadas' => count($recebidos), 'preservadas' => count($preservadas),
             'removidas' => count($apagar)]];
}

/* ================= AGENDA ================= */

function espelhoAgendaLer(mysqli $db, string $usuario): array {
    espelhoTabelas($db);
    $out = [];
    $st = $db->prepare("SELECT * FROM portal_agenda WHERE usuario=? ORDER BY data, inicio");
    $st->bind_param('s', $usuario);
    $st->execute();
    $r = $st->get_result();
    while ($l = $r->fetch_assoc()) {
        $out[] = espelhoMonta($l, ['titulo' => 'titulo', 'data' => 'data', 'inicio' => 'inicio',
                                   'fim' => 'fim', 'local' => 'local', 'obs' => 'obs']);
    }
    return $out;
}

function espelhoAgendaGravar(mysqli $db, array $lista, string $usuario): array {
    espelhoTabelas($db);
    $cols = ['titulo', 'data', 'inicio', 'fim', 'local', 'obs'];
    $recebidos = [];
    $up = $db->prepare("INSERT INTO portal_agenda
        (id, usuario, titulo, data, inicio, fim, local, obs, extra)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), data=VALUES(data), inicio=VALUES(inicio),
          fim=VALUES(fim), local=VALUES(local), obs=VALUES(obs), extra=VALUES(extra)");

    foreach ($lista as $it) {
        if (!is_array($it)) continue;
        $id = espelhoId($it, 'agenda', $usuario);
        $recebidos[$id] = true;
        $tit  = (string)($it['titulo'] ?? '');
        $data = ($it['data'] ?? '') !== '' ? (string)$it['data'] : null;
        $ini  = (string)($it['inicio'] ?? '');
        $fim  = (string)($it['fim'] ?? '');
        $loc  = (string)($it['local'] ?? '');
        $obs  = (string)($it['obs'] ?? '');
        $ext  = espelhoExtra($it, $cols);
        $up->bind_param('sssssssss', $id, $usuario, $tit, $data, $ini, $fim, $loc, $obs, $ext);
        $up->execute();
    }

    /* Agenda é pessoal: quem salva é o dono, então o que sumiu foi apagado
       por ele mesmo. Só remove os compromissos daquele usuário. */
    $st = $db->prepare("SELECT id FROM portal_agenda WHERE usuario=?");
    $st->bind_param('s', $usuario); $st->execute();
    $r = $st->get_result();
    $apagar = [];
    while ($l = $r->fetch_assoc()) if (!isset($recebidos[$l['id']])) $apagar[] = $l['id'];
    if ($apagar) {
        $ids = "'" . implode("','", array_map([$db, 'real_escape_string'], $apagar)) . "'";
        $db->query("DELETE FROM portal_agenda WHERE id IN ($ids)");
    }
    return [espelhoAgendaLer($db, $usuario), ['gravados' => count($recebidos), 'removidos' => count($apagar)]];
}

/** Primeira execução: leva para as tabelas o que já existe no JSON. */
function espelhoImportarSeVazio(mysqli $db, string $chave, string $json): void {
    espelhoTabelas($db);
    $lista = json_decode($json, true);
    if (!is_array($lista) || !$lista) return;
    if ($chave === 'salas_agendamentos_v1') {
        $n = (int)$db->query("SELECT COUNT(*) c FROM portal_salas_reservas")->fetch_assoc()['c'];
        if ($n === 0) espelhoSalasGravar($db, $lista, 'importacao');
    } elseif (strpos($chave, 'agenda_') === 0) {
        $u = substr($chave, 7);
        $st = $db->prepare("SELECT COUNT(*) c FROM portal_agenda WHERE usuario=?");
        $st->bind_param('s', $u); $st->execute();
        $n = (int)$st->get_result()->fetch_assoc()['c'];
        if ($n === 0) espelhoAgendaGravar($db, $lista, $u);
    }
}
