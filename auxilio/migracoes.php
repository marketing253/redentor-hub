<?php
/**
 * Auxílio Graduação — estrutura do banco, aplicada sozinha.
 *
 * Toda vez que o módulo abre, confere se as tabelas, colunas e índices
 * estão como devem estar. Se faltar alguma coisa, cria na hora e grava
 * a versão em aux_meta — nas próximas vezes é só um SELECT e pronto.
 *
 * Ao mudar a estrutura no futuro: acrescente o comando na lista certa
 * e suba o número de AUX_SCHEMA_VERSAO. O resto acontece sozinho.
 */
declare(strict_types=1);

const AUX_SCHEMA_VERSAO = 5;

function garanteEstrutura(PDO $pdo): array {
    $feito = [];

    $pdo->exec("CREATE TABLE IF NOT EXISTS aux_meta (
        chave VARCHAR(40) PRIMARY KEY,
        valor VARCHAR(120) NOT NULL,
        atualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $atual = 0;
    try {
        $atual = (int)($pdo->query("SELECT valor FROM aux_meta WHERE chave='schema'")->fetchColumn() ?: 0);
    } catch (Throwable $e) { $atual = 0; }
    if ($atual >= AUX_SCHEMA_VERSAO) return [];
    $jaExistia = (bool)$pdo->query("SHOW TABLES LIKE 'aux_alunos'")->fetchColumn();

    /* ---- 1. tabelas (idempotente) ---- */
    $pdo->exec("CREATE TABLE IF NOT EXISTS aux_alunos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(60) NOT NULL,
        nome VARCHAR(120) NOT NULL,
        matricula VARCHAR(30) DEFAULT NULL,
        setor VARCHAR(60) DEFAULT NULL,
        instituicao VARCHAR(120) NOT NULL,
        curso VARCHAR(120) NOT NULL,
        valor_mensalidade DECIMAL(10,2) NOT NULL,
        percentual DECIMAL(5,2) NOT NULL DEFAULT 70.00,
        qtd_mensalidades SMALLINT NOT NULL,
        dia_vencimento TINYINT NOT NULL DEFAULT 10,
        inicio_competencia CHAR(7) NOT NULL,
        contrato_arquivo VARCHAR(255) DEFAULT NULL,
        contrato_enviado_em DATETIME DEFAULT NULL,
        status ENUM('ativo','suspenso','encerrado') NOT NULL DEFAULT 'ativo',
        observacao VARCHAR(500) DEFAULT NULL,
        criado_por VARCHAR(60) DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY ix_aux_usuario (usuario), KEY ix_aux_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aux_mensalidades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        aluno_id INT NOT NULL,
        parcela SMALLINT NOT NULL,
        competencia CHAR(7) NOT NULL,
        vencimento DATE NOT NULL,
        prazo_envio DATE NOT NULL,
        valor_boleto DECIMAL(10,2) DEFAULT NULL,
        valor_empresa DECIMAL(10,2) DEFAULT NULL,
        valor_aluno DECIMAL(10,2) DEFAULT NULL,
        boleto_arquivo VARCHAR(255) DEFAULT NULL,
        boleto_enviado_em DATETIME DEFAULT NULL,
        boleto_atrasado TINYINT(1) NOT NULL DEFAULT 0,
        comprovante_arquivo VARCHAR(255) DEFAULT NULL,
        comprovante_enviado_em DATETIME DEFAULT NULL,
        status ENUM('aguardando_boleto','em_analise','rejeitado','aprovado','pago','concluido')
               NOT NULL DEFAULT 'aguardando_boleto',
        observacao VARCHAR(500) DEFAULT NULL,
        analisado_por VARCHAR(60) DEFAULT NULL,
        analisado_em DATETIME DEFAULT NULL,
        pago_em DATE DEFAULT NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_aux_parcela (aluno_id, competencia),
        KEY ix_aux_status_comp (status, competencia),
        CONSTRAINT fk_aux_mens_aluno FOREIGN KEY (aluno_id)
          REFERENCES aux_alunos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aux_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        aluno_id INT DEFAULT NULL,
        mensalidade_id INT DEFAULT NULL,
        usuario VARCHAR(60) NOT NULL,
        acao VARCHAR(40) NOT NULL,
        detalhe VARCHAR(300) DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY ix_aux_log_aluno (aluno_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* ---- 2. colunas que chegaram depois ---- */
    $colunas = [
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
    foreach ($colunas as $col => $tipo) {
        $tem = $pdo->query("SHOW COLUMNS FROM aux_alunos LIKE " . $pdo->quote($col))->fetch();
        if (!$tem) { $pdo->exec("ALTER TABLE aux_alunos ADD COLUMN `$col` $tipo"); $feito[] = "coluna $col"; }
    }

    /* ---- 3. índice antigo que impedia mais de um curso por aluno ---- */
    $uk = $pdo->query("SHOW INDEX FROM aux_alunos WHERE Key_name='uk_aux_usuario'")->fetch();
    if ($uk) {
        $pdo->exec("ALTER TABLE aux_alunos DROP INDEX uk_aux_usuario");
        try { $pdo->exec("ALTER TABLE aux_alunos ADD INDEX ix_aux_usuario (usuario)"); } catch (Throwable $e) {}
        $feito[] = 'vários cursos por aluno liberados';
    }

    $st = $pdo->prepare("INSERT INTO aux_meta (chave, valor) VALUES ('schema', ?)
                         ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $st->execute([(string)AUX_SCHEMA_VERSAO]);
    if (!$jaExistia) array_unshift($feito, 'tabelas criadas');
    return $feito;
}
