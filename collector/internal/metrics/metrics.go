// Package metrics mengirim angka berkala ke VictoriaMetrics (format teks eksposisi Prometheus,
// endpoint /api/v1/import/prometheus).
package metrics

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

type Batch struct {
	mu  sync.Mutex
	buf bytes.Buffer
	n   int
}

// Add menambah satu sampel. labels diurutkan agar keluaran deterministik.
func (b *Batch) Add(name string, labels map[string]string, value float64, at time.Time) {
	keys := make([]string, 0, len(labels))
	for k := range labels {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	b.mu.Lock()
	defer b.mu.Unlock()
	b.buf.WriteString(name)
	if len(keys) > 0 {
		b.buf.WriteByte('{')
		for i, k := range keys {
			if i > 0 {
				b.buf.WriteByte(',')
			}
			b.buf.WriteString(k)
			b.buf.WriteString(`="`)
			b.buf.WriteString(escape(labels[k]))
			b.buf.WriteByte('"')
		}
		b.buf.WriteByte('}')
	}
	b.buf.WriteByte(' ')
	b.buf.WriteString(strconv.FormatFloat(value, 'f', -1, 64))
	b.buf.WriteByte(' ')
	b.buf.WriteString(strconv.FormatInt(at.UnixMilli(), 10))
	b.buf.WriteByte('\n')
	b.n++
}

func (b *Batch) Len() int { b.mu.Lock(); defer b.mu.Unlock(); return b.n }

func (b *Batch) String() string { b.mu.Lock(); defer b.mu.Unlock(); return b.buf.String() }

var labelEscaper = strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", `\n`)

func escape(s string) string { return labelEscaper.Replace(s) }

type Writer struct {
	URL    string // basis, mis. http://victoriametrics:8428
	Client *http.Client
}

// Write mengirim batch. URL kosong = tidak melakukan apa-apa (mode tanpa grafik).
func (w Writer) Write(ctx context.Context, b *Batch) error {
	if w.URL == "" || b.Len() == 0 {
		return nil
	}
	c := w.Client
	if c == nil {
		c = &http.Client{Timeout: 30 * time.Second}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(w.URL, "/")+"/api/v1/import/prometheus", strings.NewReader(b.String()))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "text/plain")
	resp, err := c.Do(req)
	if err != nil {
		return fmt.Errorf("kirim metrik gagal: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 300))
		return fmt.Errorf("VictoriaMetrics menolak metrik: %s %s", resp.Status, msg)
	}
	return nil
}
