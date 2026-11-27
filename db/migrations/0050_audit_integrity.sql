-- Log audit tahan ubah (ISO/IEC 27001:2022 A.8.15): tiap baris membawa hash SHA-256 dari isinya dan hash baris
-- sebelumnya (rantai). Mengubah atau menghapus satu baris memutus rantai dan terdeteksi di halaman Log audit.
-- UPDATE ditolak, DELETE hanya untuk baris yang lebih tua dari batas simpan (pengaturan audit.keep_days, minimal 90 hari).

ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS prev_hash CHAR(64);
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS hash CHAR(64);

-- user_id asli disimpan terpisah agar hash tetap bisa diverifikasi walau akunnya dihapus
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS user_ref BIGINT;

-- Isi yang di-hash. Waktu dalam mikrodetik epoch supaya tidak bergantung zona waktu sesi.
CREATE OR REPLACE FUNCTION audit_row_hash(a audit_logs) RETURNS CHAR(64) LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(sha256(convert_to(concat_ws('|',
        coalesce(a.prev_hash, ''), a.id::text, coalesce(coalesce(a.user_ref, a.user_id)::text, ''), a.action, coalesce(a.subject_type, ''),
        coalesce(a.subject_id::text, ''), a.description, coalesce(a.changes::text, ''), coalesce(a.ip_address, ''),
        (extract(epoch FROM a.at) * 1000000)::bigint::text), 'UTF8')), 'hex')
$$;

CREATE OR REPLACE FUNCTION audit_logs_chain() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    last CHAR(64);
BEGIN
    -- Satu penulis pada satu waktu; id diambil SETELAH kunci supaya urutan id sama dengan urutan rantai.
    PERFORM pg_advisory_xact_lock(727101);
    NEW.id := nextval(pg_get_serial_sequence('audit_logs', 'id'));
    SELECT hash INTO last FROM audit_logs ORDER BY id DESC LIMIT 1;
    NEW.prev_hash := last;
    NEW.user_ref := NULL;
    NEW.hash := audit_row_hash(NEW);
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION audit_logs_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    keep INTEGER;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        -- Satu-satunya perubahan yang boleh: user dihapus, user_id jadi NULL (ON DELETE SET NULL). Hash tetap
        -- dihitung dari user_id asli, jadi verifikasi memakai kolom yang disimpan di bawah.
        IF NEW.user_id IS NULL AND OLD.user_id IS NOT NULL
           AND (NEW.id, NEW.action, NEW.description, NEW.at, NEW.hash, NEW.prev_hash) IS NOT DISTINCT FROM
               (OLD.id, OLD.action, OLD.description, OLD.at, OLD.hash, OLD.prev_hash) THEN
            NEW.user_ref := OLD.user_id;
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Log audit tidak boleh diubah';
    END IF;
    -- Batas simpan dari pengaturan (diubah lewat web, tercatat di log audit); 0 = simpan selamanya, minimal 90 hari
    SELECT CASE WHEN jsonb_typeof(value) = 'number' THEN value::text::integer ELSE 0 END INTO keep FROM settings WHERE key = 'audit.keep_days';
    keep := coalesce(keep, 0);
    IF keep >= 90 AND OLD.at < now() - make_interval(days => keep) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'Log audit tidak boleh dihapus (hanya pemangkasan retensi untuk baris lebih tua dari batas simpan)';
END $$;

-- Isi rantai untuk baris lama (sekali, urut id)
DO $$
DECLARE
    r audit_logs;
    last CHAR(64) := NULL;
BEGIN
    FOR r IN SELECT * FROM audit_logs ORDER BY id LOOP
        r.prev_hash := last;
        last := audit_row_hash(r);
        UPDATE audit_logs SET prev_hash = r.prev_hash, hash = last WHERE id = r.id;
    END LOOP;
END $$;

DROP TRIGGER IF EXISTS audit_logs_chain ON audit_logs;
CREATE TRIGGER audit_logs_chain BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_chain();
DROP TRIGGER IF EXISTS audit_logs_guard ON audit_logs;
CREATE TRIGGER audit_logs_guard BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_guard();

CREATE INDEX IF NOT EXISTS audit_logs_user_idx ON audit_logs (user_id, at DESC);
CREATE INDEX IF NOT EXISTS audit_logs_action_idx ON audit_logs (action, at DESC);
