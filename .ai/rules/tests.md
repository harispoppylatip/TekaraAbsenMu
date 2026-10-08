---
paths:
  - 'tests/**'
---

# Tests

## Pakai assertSeeText untuk teks halaman
`assertSee()` mencocokkan HTML mentah, termasuk baris baru dan indentasi, jadi mudah patah hanya karena blade memotong baris (mis. sel `{{ $a }} dari\n{{ $b }}` tidak lagi cocok dengan `'1 dari 2'`). Untuk teks yang dibaca pengguna pakai `assertSeeText()`, yang menormalkan strip_tags dan spasi putih; simpan `assertSee()` untuk markup atau string yang memang satu baris.
