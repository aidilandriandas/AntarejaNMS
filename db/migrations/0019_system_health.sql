-- ANTAREJA NMS — tahap 6m: pemantauan kesehatan ANTAREJA sendiri

-- Detak & status tiap layanan (collector: putaran ping/SNMP, syslog; backup: ruang disk folder backup)
CREATE TABLE system_status (
    key         VARCHAR(64)  PRIMARY KEY,
    value       JSONB        NOT NULL,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Peringatan sistem (backup gagal/terlambat, disk hampir penuh, notifikasi gagal, ...): satu baris per jenis
CREATE TABLE system_alerts (
    key           VARCHAR(64)  PRIMARY KEY,
    active        BOOLEAN      NOT NULL DEFAULT FALSE,
    severity      VARCHAR(8)   NOT NULL DEFAULT 'warning' CHECK (severity IN ('warning', 'high')),
    message       VARCHAR(500) NOT NULL DEFAULT '',
    since         TIMESTAMPTZ,
    last_sent_at  TIMESTAMPTZ,
    resolved_at   TIMESTAMPTZ
);
