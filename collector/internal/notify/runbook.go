package notify

import (
	"context"
	"strings"

	"github.com/jackc/pgx/v5/pgxpool"
)

// Runbook langkah penanganan yang paling spesifik untuk problem ini (aturan sama dengan web).
type Runbook struct {
	ID    int64
	Title string
	Steps []string // maks. 5 baris pertama yang berisi teks
}

// RunbookFor: runbook aktif yang cocok dengan perangkat dan jenis problem; perangkat > site > kategori > jenis.
func RunbookFor(ctx context.Context, db *pgxpool.Pool, deviceID int64, kind string) *Runbook {
	var r Runbook
	var body string
	err := db.QueryRow(ctx, `SELECT r.id, r.title, r.body FROM runbooks r JOIN devices d ON d.id = $1
		WHERE r.enabled AND (r.device_id IS NULL OR r.device_id = d.id) AND (r.site_id IS NULL OR r.site_id = d.site_id)
		  AND (r.category IS NULL OR r.category = d.category) AND (r.problem_kind IS NULL OR r.problem_kind = $2)
		ORDER BY (CASE WHEN r.device_id IS NOT NULL THEN 8 ELSE 0 END + CASE WHEN r.site_id IS NOT NULL THEN 4 ELSE 0 END
		        + CASE WHEN r.category IS NOT NULL THEN 2 ELSE 0 END + CASE WHEN r.problem_kind IS NOT NULL THEN 1 ELSE 0 END) DESC, r.id
		LIMIT 1`, deviceID, kind).Scan(&r.ID, &r.Title, &body)
	if err != nil {
		return nil
	}
	for _, line := range strings.Split(body, "\n") {
		if line = strings.TrimSpace(line); line != "" {
			r.Steps = append(r.Steps, truncate(line, 200))
			if len(r.Steps) == 5 {
				break
			}
		}
	}
	return &r
}
