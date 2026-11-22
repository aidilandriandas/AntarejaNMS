-- ANTAREJA NMS — tahap 5b: kualitas jaringan & ambang per perangkat

ALTER TABLE devices ADD COLUMN last_jitter_ms REAL;
-- Ambang khusus perangkat (menimpa bawaan). Contoh:
-- {"latency_ms": 250, "loss_pct": 30, "util_pct": 95, "signal": [-75, -82], "temperature": [65, 80]}
ALTER TABLE devices ADD COLUMN thresholds JSONB NOT NULL DEFAULT '{}'::jsonb;
