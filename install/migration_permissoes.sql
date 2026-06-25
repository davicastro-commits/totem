-- Migration: Permissões granulares por usuário
-- Adiciona coluna JSONB na tabela totem_admin
-- Admins têm acesso total independente do valor desta coluna

ALTER TABLE totem_admin
  ADD COLUMN IF NOT EXISTS permissoes JSONB DEFAULT '{}';

COMMENT ON COLUMN totem_admin.permissoes IS
  'Permissões granulares por página/ação. Formato: {"painel":{"dashboard":true,...},"op":{...},"tela":{...},"acao":{...}}. Admins têm acesso total independente deste campo.';
