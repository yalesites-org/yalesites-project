-- Runs on first Postgres data directory initialization (Docker entrypoint).
-- If you rebuild or reuse an existing volume, run: lando pgvector-init
CREATE EXTENSION IF NOT EXISTS vector;
