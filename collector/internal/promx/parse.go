// Package promx membaca endpoint /metrics exporter Prometheus (node_exporter di Linux, windows_exporter di Windows)
// lalu mengubah beberapa metrik penting (CPU, memori, disk, layanan) menjadi sensor perangkat. Parser ditulis sendiri
// dari spesifikasi format teks Prometheus; hanya metrik yang diminta yang disimpan di memori.
package promx

import (
	"bufio"
	"errors"
	"io"
	"math"
	"strconv"
	"strings"
)

// Sample satu baris metrik.
type Sample struct {
	Name   string
	Labels map[string]string
	Value  float64
}

// Batas supaya exporter yang rusak atau sangat besar tidak menghabiskan memori collector.
const (
	maxLine    = 64 << 10
	maxSamples = 200000
)

var errTooMany = errors.New("terlalu banyak baris metrik yang cocok")

// Parse membaca format teks (versi 0.0.4). keep memilih nama metrik yang disimpan.
func Parse(r io.Reader, keep func(name string) bool) ([]Sample, error) {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 0, 4096), maxLine)
	var out []Sample
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || line[0] == '#' {
			continue
		}
		name, rest := splitName(line)
		if name == "" || !keep(name) {
			continue
		}
		s, ok := parseRest(name, rest)
		if !ok {
			continue
		}
		if len(out) >= maxSamples {
			return out, errTooMany
		}
		out = append(out, s)
	}
	return out, sc.Err()
}

func splitName(line string) (string, string) {
	for i := 0; i < len(line); i++ {
		c := line[i]
		if c == '{' || c == ' ' || c == '\t' {
			return line[:i], line[i:]
		}
		if !(c == '_' || c == ':' || c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || i > 0 && c >= '0' && c <= '9') {
			return "", ""
		}
	}
	return "", ""
}

func parseRest(name, rest string) (Sample, bool) {
	s := Sample{Name: name}
	if strings.HasPrefix(rest, "{") {
		labels, n, ok := parseLabels(rest)
		if !ok {
			return s, false
		}
		s.Labels, rest = labels, rest[n:]
	}
	f := strings.Fields(rest)
	if len(f) < 1 || len(f) > 2 {
		return s, false
	}
	v, err := parseFloat(f[0])
	if err != nil {
		return s, false
	}
	s.Value = v
	return s, true
}

func parseFloat(t string) (float64, error) {
	switch t {
	case "+Inf":
		return math.Inf(1), nil
	case "-Inf":
		return math.Inf(-1), nil
	case "NaN":
		return math.NaN(), nil
	}
	return strconv.ParseFloat(t, 64)
}

// parseLabels membaca {a="x",b="y"}; nilai mengenal escape \\, \" dan \n. n = panjang yang dibaca.
func parseLabels(s string) (map[string]string, int, bool) {
	out := map[string]string{}
	i := 1
	for {
		for i < len(s) && (s[i] == ' ' || s[i] == ',') {
			i++
		}
		if i >= len(s) {
			return nil, 0, false
		}
		if s[i] == '}' {
			return out, i + 1, true
		}
		j := i
		for j < len(s) && s[j] != '=' {
			j++
		}
		if j+1 >= len(s) || s[j+1] != '"' {
			return nil, 0, false
		}
		key := strings.TrimSpace(s[i:j])
		var b strings.Builder
		k := j + 2
		for ; k < len(s) && s[k] != '"'; k++ {
			if s[k] == '\\' && k+1 < len(s) {
				k++
				switch s[k] {
				case 'n':
					b.WriteByte('\n')
				default:
					b.WriteByte(s[k])
				}
				continue
			}
			b.WriteByte(s[k])
		}
		if k >= len(s) {
			return nil, 0, false
		}
		out[key] = b.String()
		i = k + 1
	}
}
