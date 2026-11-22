package configbak

import (
	"fmt"
	"strings"
)

// Diff menghasilkan diff gaya unified antara dua daftar baris (algoritma Myers, ditulis untuk ANTAREJA)
// beserta jumlah baris ditambah/dihapus. Konteks 3 baris di sekitar perubahan.
func Diff(a, b []string) (text string, added, removed int) {
	ops := myers(a, b)
	for _, o := range ops {
		switch o.kind {
		case '+':
			added++
		case '-':
			removed++
		}
	}
	if added == 0 && removed == 0 {
		return "", 0, 0
	}
	// Kelompokkan dalam hunk dengan konteks 3 baris
	const ctx = 3
	var sb strings.Builder
	n := len(ops)
	for i := 0; i < n; {
		if ops[i].kind == ' ' {
			i++
			continue
		}
		start := max(0, i-ctx)
		end := i
		for end < n {
			if ops[end].kind != ' ' {
				end++
				continue
			}
			// lanjut bila perubahan berikutnya dalam jarak 2*ctx
			j := end
			for j < n && ops[j].kind == ' ' && j-end < 2*ctx {
				j++
			}
			if j < n && ops[j].kind != ' ' {
				end = j
				continue
			}
			break
		}
		stop := min(n, end+ctx)
		fmt.Fprintf(&sb, "@@ baris %d @@\n", ops[start].aLine+1)
		for _, o := range ops[start:stop] {
			sb.WriteByte(o.kind)
			sb.WriteString(o.text)
			sb.WriteByte('\n')
		}
		i = stop
	}
	return sb.String(), added, removed
}

type op struct {
	kind  byte // ' ', '+', '-'
	text  string
	aLine int
}

// myers: jalur edit terpendek O((N+M)·D).
func myers(a, b []string) []op {
	n, m := len(a), len(b)
	maxD := n + m
	v := make([]int, 2*maxD+2)
	var trace [][]int
	off := maxD + 1
	found := false
	for d := 0; d <= maxD && !found; d++ {
		trace = append(trace, append([]int(nil), v...))
		for k := -d; k <= d; k += 2 {
			var x int
			if k == -d || (k != d && v[off+k-1] < v[off+k+1]) {
				x = v[off+k+1]
			} else {
				x = v[off+k-1] + 1
			}
			y := x - k
			for x < n && y < m && a[x] == b[y] {
				x++
				y++
			}
			v[off+k] = x
			if x >= n && y >= m {
				found = true
				trace = append(trace, append([]int(nil), v...))
				break
			}
		}
	}
	// Telusuri balik
	var rev []op
	x, y := n, m
	for d := len(trace) - 2; d >= 0 && (x > 0 || y > 0); d-- {
		vv := trace[d]
		k := x - y
		var prevK int
		if k == -d || (k != d && vv[off+k-1] < vv[off+k+1]) {
			prevK = k + 1
		} else {
			prevK = k - 1
		}
		prevX := vv[off+prevK]
		prevY := prevX - prevK
		for x > prevX && y > prevY {
			x--
			y--
			rev = append(rev, op{' ', a[x], x})
		}
		if d > 0 {
			if x == prevX {
				y--
				rev = append(rev, op{'+', b[y], x})
			} else {
				x--
				rev = append(rev, op{'-', a[x], x})
			}
		}
	}
	for x > 0 && y > 0 { // sisa awal yang sama
		x--
		y--
		rev = append(rev, op{' ', a[x], x})
	}
	out := make([]op, len(rev))
	for i := range rev {
		out[i] = rev[len(rev)-1-i]
	}
	return out
}
