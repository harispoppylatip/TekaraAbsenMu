# Ritual Vault "project haris" (Second Brain)

**Glob:** `**`

## Patokan wajib

Sebelum memulai pekerjaan apa pun di repo ini (fitur, debug, refactor, audit, menjawab pertanyaan tentang project), baca dulu vault second brain pengguna yang berada **di luar repo**:

```
C:\Users\Pongo\Documents\obsidian\project haris
```

Jangan mulai dari nol. Cek dengan Ctrl+F kata kunci terkait, jangan membaca seluruh file:

1. `bug-dan-solusi.md` (war room lintas project) apakah bug/masalah mirip sudah pernah diperbaiki
2. `pola-fitur.md` (playbook) pola arsitektur dan keputusan yang sudah terbukti
3. `ingatan-agent.md` catatan umum terbaru (entri terbaru ada di atas)
4. Folder project yang sedang dikerjakan, misalnya `absensidikjari/catatan-project.md`, `tekara/catatan-project.md`
5. `hermes/index.md` aturan agent serta solusi otomasi yang pernah ditemukan
6. `Rules.md` dan `user.md` larangan desain yang wajib dipatuhi di semua frontend

## Setelah selesai

Catat kembali kalau ada yang penting:

- Bug susah atau lama → `bug-dan-solusi.md`
- Pola fitur berulang → `pola-fitur.md`
- Solusi otomasi atau tooling → folder `hermes/`
- Kronologi project → `<project>/catatan-project.md` plus satu butir ringkas di `ingatan-agent.md` pada tanggal berjalan

Format entri: tanggal, gejala/masalah, penyebab, solusi, pelajaran. Hubungkan catatan dengan wikilink `[[...]]`.

## Aturan desain

Semua pekerjaan frontend wajib patuh `Rules.md` tanpa pengecualian: dilarang dot/bullet indicator, dilarang garis sejajar di samping teks, dilarang text gradient atau warna berbeda dalam satu baris, dilarang pill/badge pre-title. Ditambah larangan em-dash dari `user.md`.
