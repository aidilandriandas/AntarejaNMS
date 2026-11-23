package notify

import (
	"context"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

// OncallPerson adalah engineer yang sedang piket beserta kontaknya.
type OncallPerson struct {
	Name     string
	Email    string
	Telegram string
}

// CurrentOncall: pengguna aktif yang slot piketnya mencakup saat ini, urut dari slot yang mulai paling awal.
func CurrentOncall(ctx context.Context, db *pgxpool.Pool) ([]OncallPerson, error) {
	rows, err := db.Query(ctx, `SELECT u.name, COALESCE(u.email, ''), COALESCE(u.telegram_chat_id, '')
		FROM users u JOIN LATERAL (SELECT min(starts_at) AS s FROM oncall_slots o
			WHERE o.user_id = u.id AND o.starts_at <= $1 AND o.ends_at > $1) x ON x.s IS NOT NULL
		WHERE u.active ORDER BY x.s, u.id`, time.Now())
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []OncallPerson
	for rows.Next() {
		var p OncallPerson
		if err := rows.Scan(&p.Name, &p.Email, &p.Telegram); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// ApplyOncall mengganti tujuan kanal dengan kontak orang yang piket.
// Email: semua orang piket yang punya alamat. Telegram: satu chat per kanal, jadi orang piket pertama
// yang punya chat ID. Mengembalikan jumlah penerima; 0 berarti tujuan tetap kanal tidak diubah.
func ApplyOncall(c *Channel, people []OncallPerson) int {
	c.Oncall = nil
	switch c.Type {
	case "email":
		var to []string
		for _, p := range people {
			if e := strings.TrimSpace(p.Email); e != "" {
				to = append(to, e)
				c.Oncall = append(c.Oncall, p.Name)
			}
		}
		if len(to) == 0 {
			return 0
		}
		c.Config = cloneCfg(c.Config)
		c.Config["to"] = strings.Join(to, ",")
		return len(to)
	case "telegram":
		for _, p := range people {
			if t := strings.TrimSpace(p.Telegram); t != "" {
				c.Config = cloneCfg(c.Config)
				c.Config["chat_id"] = t
				c.Oncall = []string{p.Name}
				return 1
			}
		}
	}
	return 0
}

func cloneCfg(m map[string]string) map[string]string {
	out := make(map[string]string, len(m)+1)
	for k, v := range m {
		out[k] = v
	}
	return out
}
