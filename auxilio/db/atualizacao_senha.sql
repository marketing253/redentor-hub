-- Login próprio do aluno. O instalar.php aplica sozinho pelo botão "Atualizar tabelas".
ALTER TABLE aux_alunos
  ADD COLUMN senha_hash        VARCHAR(255) DEFAULT NULL AFTER pix_atualizado_em,
  ADD COLUMN precisa_trocar    TINYINT(1)   NOT NULL DEFAULT 1 AFTER senha_hash,
  ADD COLUMN acesso_enviado_em DATETIME     DEFAULT NULL AFTER precisa_trocar,
  ADD COLUMN ultimo_acesso     DATETIME     DEFAULT NULL AFTER acesso_enviado_em;
