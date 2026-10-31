-- =============================================================================
-- WARM — rollback manual do multi-instância (MTZ-134)
-- =============================================================================
-- Reverte o schema das migrações:
--   2026_10_16_120000_create_instances_table
--   2026_10_16_120001_add_instance_id_to_conversations_and_messages
-- Equivale ao `php artisan migrate:rollback --step=2`, mas de forma revisável
-- e tolerante a conversas duplicadas entre instâncias (o `down` aborta no
-- índice único quando o mesmo contato/grupo existe em 2+ instâncias).
--
-- ATENÇÃO — DESTRUTIVO.
--   * Reverte o schema: remove `conversations.instance_id`,
--     `messages.instance_id` e a tabela `instances`.
--   * Deduplica conversas: por contato/grupo, mantém a conversa da instância
--     default (empate -> menor id) e MOVE as mensagens das demais para ela
--     (sem perder mensagens/mídia), apagando as conversas esvaziadas.
--   * Apaga os 2 registros correspondentes na tabela `migrations`.
--
-- Rode em janela de manutenção, com a ingestão parada, e com a imagem ATUAL
-- (multi-instância) ainda no ar — a imagem antiga não conhece este schema.
-- Uso:
--   pg_dump ... > backup.sql          # 1) backup sempre
--   psql "$DATABASE_URL" -f deployment/rollback-multi-instance.sql
--   php artisan migrate:status        # 2) confirme que as 2 migrações sumiram
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN;

-- Guarda: exatamente uma instância default (a dedup depende dela).
DO $$
DECLARE
    defaults integer;
BEGIN
    SELECT count(*) INTO defaults FROM instances WHERE is_default;
    IF defaults <> 1 THEN
        RAISE EXCEPTION 'esperava exatamente 1 instancia default, encontrei %', defaults;
    END IF;
END $$;

-- 1. Deduplicação de conversas ----------------------------------------------
-- victim_id será apagada; kept_id é a conversa da instância default do
-- contato/grupo (empate -> menor id).
CREATE TEMP TABLE rollback_victims ON COMMIT DROP AS (
WITH ranked_contact AS (
    SELECT c.id,
           first_value(c.id) OVER (
               PARTITION BY c.contact_id
               ORDER BY i.is_default DESC, c.id
           ) AS kept_id,
           row_number() OVER (
               PARTITION BY c.contact_id
               ORDER BY i.is_default DESC, c.id
           ) AS rn
    FROM conversations c
    JOIN instances i ON i.instance_id = c.instance_id
    WHERE c.contact_id IS NOT NULL
),
ranked_group AS (
    SELECT c.id,
           first_value(c.id) OVER (
               PARTITION BY c.group_id
               ORDER BY i.is_default DESC, c.id
           ) AS kept_id,
           row_number() OVER (
               PARTITION BY c.group_id
               ORDER BY i.is_default DESC, c.id
           ) AS rn
    FROM conversations c
    JOIN instances i ON i.instance_id = c.instance_id
    WHERE c.group_id IS NOT NULL
)
SELECT id AS victim_id, kept_id FROM ranked_contact WHERE rn > 1
UNION
SELECT id AS victim_id, kept_id FROM ranked_group   WHERE rn > 1
);

-- Move as mensagens (mídia acompanha via message_id) para a conversa mantida.
UPDATE messages m
   SET conversation_id = v.kept_id
  FROM rollback_victims v
 WHERE m.conversation_id = v.victim_id;

-- Remove as conversas esvaziadas.
DELETE FROM conversations c
 USING rollback_victims v
 WHERE c.id = v.victim_id;

-- 2. Reversão do schema ------------------------------------------------------
-- DROP COLUMN leva junto FK, índice e unicidade que dependem de instance_id.
ALTER TABLE conversations DROP COLUMN instance_id;
ALTER TABLE messages      DROP COLUMN instance_id;

-- Recria a unicidade pré-multi-instância (nomes padrão do Laravel).
ALTER TABLE conversations
    ADD CONSTRAINT conversations_contact_id_unique UNIQUE (contact_id);
ALTER TABLE conversations
    ADD CONSTRAINT conversations_group_id_unique UNIQUE (group_id);

DROP TABLE instances;

-- 3. Sincroniza o histórico de migrações ------------------------------------
DELETE FROM migrations
 WHERE migration IN (
     '2026_10_16_120000_create_instances_table',
     '2026_10_16_120001_add_instance_id_to_conversations_and_messages'
 );

COMMIT;
