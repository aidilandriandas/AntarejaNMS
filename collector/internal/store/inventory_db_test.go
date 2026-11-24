package store

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestSaveInventoryDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('rt', '10.0.0.9') RETURNING id`)
	if _, ch, err := s.SaveInventory(ctx, dev, "7.15.3", "RB4011iGS+", "HE30812ABC"); err != nil || ch {
		t.Fatal("pertama kali bukan perubahan", err, ch)
	}
	if _, ch, err := s.SaveInventory(ctx, dev, "7.15.3", "", ""); err != nil || ch {
		t.Fatal("versi sama", err, ch)
	}
	old, ch, err := s.SaveInventory(ctx, dev, "7.16", "", "")
	if err != nil || !ch || old != "7.15.3" {
		t.Fatal("versi naik harus tercatat", old, ch, err)
	}
	var model, serial string
	_ = db.QueryRow(ctx, `SELECT hw_model, serial_no FROM devices WHERE id = $1`, dev).Scan(&model, &serial)
	if model != "RB4011iGS+" || serial != "HE30812ABC" {
		t.Fatal("nilai kosong tidak boleh menimpa", model, serial)
	}
}
