// Terapkan tema tersimpan sebelum halaman digambar (tanpa skrip inline, sesuai CSP), dan tandai bahwa JavaScript aktif
// (menu HP hanya disembunyikan bila skrip yang membukanya berjalan).
document.documentElement.classList.add('js');
try { var t = localStorage.getItem('ant-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) { /* penyimpanan tidak tersedia */ }
