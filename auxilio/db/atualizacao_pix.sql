-- Rode SÓ se você já tinha criado as tabelas antes da versão com Pix.
-- Em instalação nova, o auxilio_schema.sql já traz estas colunas.
ALTER TABLE aux_alunos
  ADD COLUMN email             VARCHAR(120) DEFAULT NULL AFTER setor,
  ADD COLUMN telefone          VARCHAR(20)  DEFAULT NULL AFTER email,
  ADD COLUMN pix_tipo          ENUM('cpf','email','telefone','aleatoria') DEFAULT NULL AFTER telefone,
  ADD COLUMN pix_chave         VARCHAR(140) DEFAULT NULL AFTER pix_tipo,
  ADD COLUMN pix_atualizado_em DATETIME     DEFAULT NULL AFTER pix_chave;
