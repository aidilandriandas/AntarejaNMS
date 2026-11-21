package poller

import (
	"testing"
	"time"
)

func TestHuman(t *testing.T) {
	cases := map[time.Duration]string{
		40 * time.Second:            "40 detik",
		5 * time.Minute:             "5 menit",
		2*time.Hour + 3*time.Minute: "2 jam 3 menit",
		3 * time.Hour:               "3 jam",
		50 * time.Hour:              "2 hari 2 jam",
		48 * time.Hour:              "2 hari",
	}
	for d, want := range cases {
		if got := Human(d); got != want {
			t.Errorf("Human(%s)=%q ingin %q", d, got, want)
		}
	}
}
