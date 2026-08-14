-- Permite mais de um curso por aluno.
-- O instalar.php faz isso sozinho pelo botão "Permitir vários cursos".
ALTER TABLE aux_alunos DROP INDEX uk_aux_usuario;
ALTER TABLE aux_alunos ADD INDEX ix_aux_usuario (usuario);
