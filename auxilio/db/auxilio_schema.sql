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
  senha_hash         VARCHAR(255) DEFAULT NULL,
  precisa_trocar     TINYINT(1)   NOT NULL DEFAULT 1,
  acesso_enviado_em  DATETIME     DEFAULT NULL,
  ultimo_acesso      DATETIME     DEFAULT NULL,
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
  KEY ix_aux_usuario (usuario),
  KEY ix_aux_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aux_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id       INT DEFAULT NULL,
  mensalidade_id INT DEFAULT NULL,
  usuario        VARCHAR(60) NOT NULL,
  acao           VARCHAR(40) NOT NULL,
  detalhe        VARCHAR(300) DEFAULT NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_aux_log_aluno (aluno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
