-- FitControl — Bloco 03
-- Migração 006: cria o controle inicial de revisão para os alunos existentes.
-- Pré-requisito: migration 005.
-- A carga não cria matrículas, não escolhe decisões humanas e não inventa evidências.
-- O limite de IDs é gravado uma única vez para que reexecuções posteriores não
-- incluam alunos cadastrados depois da importação inicial.

USE academia_alunos;

START TRANSACTION;

INSERT INTO controle_importacao_legado (id, ultimo_aluno_id)
SELECT 1, COALESCE(MAX(id), 0)
FROM alunos
ON DUPLICATE KEY UPDATE id = VALUES(id);

INSERT INTO revisoes_legado (
    aluno_id_origem,
    plano_texto_original,
    resultado_atual,
    fonte_referencia
)
SELECT
    alunos.id,
    alunos.plano,
    'PENDENTE_REVISAO',
    NULL
FROM alunos
INNER JOIN controle_importacao_legado AS controle
    ON controle.id = 1
LEFT JOIN revisoes_legado
    ON revisoes_legado.aluno_id_origem = alunos.id
WHERE alunos.id <= controle.ultimo_aluno_id
  AND revisoes_legado.id IS NULL
ORDER BY alunos.id;

COMMIT;

-- O registro de controle preserva o corte da primeira execução. Reexecutar a
-- carga completa apenas eventuais lacunas daquele conjunto original; não cria
-- revisões automáticas para alunos cadastrados depois.
