package poller

import (
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

func TestClockSensor(t *testing.T) {
	for _, c := range []struct {
		skew float64
		want string
	}{{12, "ok"}, {-75, "warn"}, {301, "crit"}, {-900, "crit"}} {
		if s := ClockSensor(snmp.Clock{SkewSec: c.skew, HasTZ: true}); s.Status != c.want || s.Class != "clock_skew" {
			t.Errorf("%v: %s, harus %s", c.skew, s.Status, c.want)
		}
	}
}

func TestInventoryDue(t *testing.T) {
	p := &Poller{}
	now := time.Now()
	if !p.inventoryDue(1, now) || p.inventoryDue(1, now.Add(time.Hour)) || !p.inventoryDue(1, now.Add(25*time.Hour)) {
		t.Fatal("inventaris dibaca sekali per 24 jam")
	}
}
