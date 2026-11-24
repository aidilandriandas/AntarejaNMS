-- Sesi BGP dan tetangga OSPF per router. Baris yang tidak dijawab lagi oleh router ditandai hilang (missing),
-- tidak dihapus, supaya tetangga yang lenyap tetap jadi problem sampai engineer menekan "Lupakan".
CREATE TABLE IF NOT EXISTS bgp_peers (
    device_id      BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    remote_addr    INET        NOT NULL,
    remote_as      BIGINT,
    local_as       BIGINT,
    state          SMALLINT    NOT NULL,
    admin_status   SMALLINT,
    since_s        BIGINT,
    missing        BOOLEAN     NOT NULL DEFAULT FALSE,
    state_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, remote_addr)
);
CREATE TABLE IF NOT EXISTS ospf_neighbors (
    device_id      BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    addr           INET        NOT NULL,
    router_id      VARCHAR(45),
    state          SMALLINT    NOT NULL,
    missing        BOOLEAN     NOT NULL DEFAULT FALSE,
    state_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, addr)
);
