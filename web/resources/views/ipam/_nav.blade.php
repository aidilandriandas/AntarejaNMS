<div class="page-head">
    <div><h1>IPAM</h1><p>Subnet dari IP interface perangkat (otomatis) dan tambahan manual. Pemakaian dari tabel ARP router/L3 (Core, Distribution, Firewall, MikroTik), diperbarui tiap putaran SNMP.</p></div>
    <form action="{{ route('ipam.search') }}" class="actions" role="search">
        <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Cari IP atau MAC" style="width:220px" aria-label="Cari IP atau MAC">
        <button class="btn"><x-icon name="search"/>Cari</button>
    </form>
</div>
