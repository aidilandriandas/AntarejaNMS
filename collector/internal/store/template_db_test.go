package store

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestAmbangTemplatDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	tpl := dbtest.ID(t, db, `INSERT INTO device_templates (name, thresholds) VALUES ('QA tpl', '{"cpu":[70,90],"latency_ms":50}') RETURNING id`)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, template_id, thresholds) VALUES ('srv-t', '10.0.7.1', $1, '{"cpu":[60,80]}') RETURNING id`, tpl)
	d, err := Store{DB: db}.Device(ctx, dev)
	if err != nil {
		t.Fatal(err)
	}
	l := d.Limits()
	if l.LatencyMS != 50 || l.Classes["cpu"] != [2]float64{60, 80} {
		t.Fatalf("%+v", l)
	}
}
