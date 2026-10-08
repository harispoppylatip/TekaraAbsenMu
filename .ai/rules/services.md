---
paths:
  - 'app/Services/**'
---

# Services

## Jangan memoize data database yang bisa diubah admin
Service di `app/Services` dipakai bersama jalur web dan proses hidup lama (`fingerprint:mqtt-listen`, queue worker, scheduler). Jangan menyimpan hasil query di properti instance (`private ?Collection $x = null` + `??=`), karena proses panjang akan memakai hasil pembacaan pertama sampai dijalankan ulang sehingga perubahan admin tidak terbaca. Query ulang tiap pemanggilan; kalau perlu cache, sediakan invalidasi eksplisit. Contoh nyata: jam presensi gerbang masih `07:30 - 12:00` di MQTT walau database sudah `07:30 - 13:00`.
