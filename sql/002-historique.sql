-- Comptes : rôles connus uniquement, dates de création et de dernière connexion.
ALTER TABLE gestclub_users
    ADD CONSTRAINT gestclub_users_roles_known CHECK (roles <@ '["director", "treasurer", "secretary"]'::jsonb),
    ADD COLUMN created_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN last_login_at timestamptz;

ALTER TABLE gestclub_login_limits
    ADD CONSTRAINT gestclub_login_limits_attempts_check CHECK (attempts >= 0);

-- État du club : auteur de la dernière sauvegarde, et refus d'un état sans saison.
ALTER TABLE gestclub_state
    ADD COLUMN updated_by bigint REFERENCES gestclub_users (id) ON DELETE SET NULL,
    ADD CONSTRAINT gestclub_state_seasons_check CHECK (records <> '{}'::jsonb AND bureau <> '{}'::jsonb);

-- Historique : chaque état remplacé est conservé avec sa date et son auteur.
CREATE TABLE gestclub_state_history (
    revision bigint PRIMARY KEY,
    records jsonb NOT NULL,
    bureau jsonb NOT NULL,
    saved_at timestamptz NOT NULL,
    saved_by bigint REFERENCES gestclub_users (id) ON DELETE SET NULL
);

CREATE FUNCTION gestclub_keep_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO gestclub_state_history (revision, records, bureau, saved_at, saved_by)
    VALUES (OLD.revision, OLD.records, OLD.bureau, OLD.updated_at, OLD.updated_by)
    ON CONFLICT (revision) DO NOTHING;
    RETURN NULL;
END
$$;

CREATE TRIGGER gestclub_state_history
    AFTER UPDATE ON gestclub_state
    FOR EACH ROW
    WHEN (OLD.records IS DISTINCT FROM NEW.records OR OLD.bureau IS DISTINCT FROM NEW.bureau)
    EXECUTE FUNCTION gestclub_keep_history();

-- Nettoyage, à appeler dans une transaction qui verrouille gestclub_state.
-- Historique : les 50 dernières révisions sont gardées, puis la dernière de chaque jour pendant un an.
-- Justificatifs : une pièce est supprimée quand ni l'état courant ni l'historique ne la citent.
CREATE FUNCTION gestclub_prune() RETURNS void LANGUAGE sql AS $$
    DELETE FROM gestclub_state_history h
    WHERE h.revision <= (SELECT revision FROM gestclub_state) - 50
      AND (h.saved_at < now() - interval '1 year'
        OR EXISTS (
            SELECT 1 FROM gestclub_state_history n
            WHERE n.revision > h.revision
              AND (n.saved_at AT TIME ZONE 'Europe/Paris')::date = (h.saved_at AT TIME ZONE 'Europe/Paris')::date
        ));

    DELETE FROM gestclub_attachments
    WHERE id NOT IN (
        SELECT jsonb_path_query(bureau, '$.*.*[*].receipt.id ? (@.type() == "string")') #>> '{}' FROM gestclub_state
        UNION
        SELECT jsonb_path_query(bureau, '$.*.*[*].receipt.id ? (@.type() == "string")') #>> '{}' FROM gestclub_state_history
    );
$$;
