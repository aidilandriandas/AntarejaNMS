// Terapkan tema tersimpan sebelum halaman digambar (tanpa skrip inline, sesuai CSP).
try { var t = localStorage.getItem('ant-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) { /* penyimpanan tidak tersedia */ }
