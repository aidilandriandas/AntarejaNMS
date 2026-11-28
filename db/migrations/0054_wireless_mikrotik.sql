-- Link wireless MikroTik (registration table MIKROTIK-MIB mtxrWlRtabTable): jenis link 'mikrotik'.
ALTER TABLE wireless_links DROP CONSTRAINT IF EXISTS wireless_links_kind_check;
ALTER TABLE wireless_links ADD CONSTRAINT wireless_links_kind_check CHECK (kind IN ('airmax', 'airfiber', 'ltu', 'mikrotik'));
