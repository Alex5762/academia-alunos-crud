-- FitControl — Bloco 03
-- Migração 006: cria o controle inicial de revisão para os alunos já existentes.
-- Pré-requisito: migration 005.
-- A carga não cria matrículas, não escolhe decisões humanas e não inventa evidências.
-- Reexecução segura: alunos já presentes em revisoes_legado não são sobrescritos.

USE academia_alunos;

START TRANSACTION;

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
LEFT JOIN revisoes_legado
    ON revisoes_legado.aluno_id_origem = alunos.id
WHERE revisoes_legado.id IS NULL
ORDER BY alunos.id;

COMMIT;

-- Cada aluno existente no momento da primeira execução passa a exigir revisão.
-- Alunos cadastrados depois continuam no fluxo normal e não recebem revisão
-- automática por reexecução desta carga.
