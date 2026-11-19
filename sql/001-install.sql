CREATE TABLE IF NOT EXISTS gestclub_users (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email text NOT NULL UNIQUE CHECK (email = lower(email)),
    password_hash text NOT NULL,
    roles jsonb NOT NULL CHECK (jsonb_typeof(roles) = 'array' AND jsonb_array_length(roles) > 0),
    session_version integer NOT NULL DEFAULT 1,
    active boolean NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS gestclub_state (
    id boolean PRIMARY KEY DEFAULT true CHECK (id),
    revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
    records jsonb NOT NULL CHECK (jsonb_typeof(records) = 'object'),
    bureau jsonb NOT NULL CHECK (jsonb_typeof(bureau) = 'object'),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS gestclub_login_limits (
    key text PRIMARY KEY,
    attempts integer NOT NULL,
    expires_at timestamptz NOT NULL
);

CREATE INDEX IF NOT EXISTS gestclub_login_expiry ON gestclub_login_limits (expires_at);

CREATE TABLE IF NOT EXISTS gestclub_attachments (
    id text PRIMARY KEY CHECK (id ~ '^[0-9a-f]{32}$'),
    name text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    mime text NOT NULL CHECK (mime IN ('application/pdf', 'image/jpeg', 'image/png', 'image/webp')),
    content bytea NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 1048576),
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Révision des seules modifications du bureau. Les liens publics (inscriptions, bénévoles)
-- font avancer revision sans toucher à staff_revision.
ALTER TABLE gestclub_state ADD COLUMN IF NOT EXISTS staff_revision bigint NOT NULL DEFAULT 0;
-- Mise à jour d’une installation existante : part de la révision actuelle.
UPDATE gestclub_state SET staff_revision = revision WHERE staff_revision = 0;
