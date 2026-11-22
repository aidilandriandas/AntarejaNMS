package snmp

import (
	"math/big"
	"testing"
)

func TestSetPktPrefers64(t *testing.T) {
	var it Interface
	it.setPkt("InUcast", big.NewInt(5), false)   // 32-bit dulu
	it.setPkt("InUcast", big.NewInt(9e12), true) // lalu 64-bit
	it.setPkt("InUcast", big.NewInt(7), false)   // 32-bit tidak menimpa 64-bit
	if it.InUcast.Int64() != 9e12 {
		t.Fatalf("InUcast = %v", it.InUcast)
	}
	it.setPkt("OutBcast", big.NewInt(3), false)
	if it.OutBcast.Int64() != 3 || it.InMcast != nil {
		t.Fatalf("OutBcast=%v InMcast=%v", it.OutBcast, it.InMcast)
	}
	for col, pc := range pktCols {
		if _, ok := ifColumns[col]; !ok {
			t.Errorf("kolom %s tanpa OID", col)
		}
		_ = pc
	}
}
