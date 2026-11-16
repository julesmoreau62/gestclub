-- Dernière révision à laquelle chaque domaine a changé.
-- Une sauvegarde est refusée seulement si un domaine qu'elle écrit a changé depuis la lecture du client :
-- le registre, les finances et la vie du bureau ne se bloquent plus entre eux.
ALTER TABLE gestclub_state
    ADD COLUMN records_revision bigint NOT NULL DEFAULT 0,
    ADD COLUMN finance_revision bigint NOT NULL DEFAULT 0,
    ADD COLUMN office_revision bigint NOT NULL DEFAULT 0;

UPDATE gestclub_state SET records_revision = revision, finance_revision = revision, office_revision = revision;

ALTER TABLE gestclub_state
    ADD CONSTRAINT gestclub_state_domains_check
    CHECK (records_revision BETWEEN 0 AND revision AND finance_revision BETWEEN 0 AND revision
        AND office_revision BETWEEN 0 AND revision);
