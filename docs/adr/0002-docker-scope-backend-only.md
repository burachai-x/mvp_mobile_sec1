# ADR 0002 — Docker ครอบเฉพาะ backend, Flutter build ใน CI

| | |
|---|---|
| สถานะ | เสนอ |
| วันที่ | 2026-07-22 |
| เกี่ยวข้องกับ | `PRD.md` §2.1 · `docs/security/threat-model.md` S8 |

## บริบท

โจทย์กำหนดให้ใช้ **Docker เป็นหลัก** คำถามคือ "หลัก" ครอบถึงการ build แอป Flutter ด้วยไหม

`PRD.md` §2.1 ระบุว่า release keystore (`.jks`) ต้องเก็บใน Secure Environment (CI/CD Secrets หรือ Vault)
และห้ามใช้ `debug.keystore` build release เด็ดขาด

## การตัดสินใจ

**Docker ครอบเฉพาะ backend + infra** (nginx, php-fpm, PostgreSQL, Valkey, Garage, queue, scheduler)
**Flutter build ผ่าน CI เท่านั้น ไม่ผ่าน Docker**

เหตุผล
1. **ความปลอดภัยของ keystore** — release signing ต้องใช้ `.jks` ถ้า build ใน Docker ไฟล์นี้ต้องเข้าไปอยู่ใน build context หรือใน layer ซึ่งกู้ออกมาได้ด้วย `docker history` / `docker save` ตลอดไป **ขัดกับ PRD §2.1 โดยตรง**
2. **ขนาด image** — Android SDK + NDK + Gradle cache ทำให้ image โตเกิน 8 GB build ครั้งแรกช้ามาก
3. **ไม่ได้ประโยชน์ที่ต้องการ** — เหตุผลที่ใช้ Docker คือให้ dev ทุกคนได้สภาพแวดล้อมเหมือนกัน แต่ Flutter dev ต้องใช้ emulator/เครื่องจริง + hot reload ซึ่งทำผ่าน container บน Linux ลำบากกว่าที่ได้คืน

**วิธี build release แทน:** ใน CI decode keystore จาก secret (base64) ลง path บน `tmpfs` → build → **ลบทิ้งใน step เดียวกัน**
ห้าม cache path นั้น ห้าม upload เป็น artifact

## ผลที่ตามมา

**ผลดี**
- `.jks` ไม่มีวันเข้าไปอยู่ใน image layer หรือใน repo
- image ของ backend เล็ก build เร็ว
- `docker compose up` ให้สภาพแวดล้อม backend ครบสำหรับทั้ง backend dev และ mobile dev ที่ต้องยิง API

**ผลเสีย**
- Flutter dev ต้องติดตั้ง Flutter SDK บนเครื่องตัวเอง → ต้องระบุเวอร์ชันที่ใช้ให้ชัดใน `README.md` และ pin ใน CI ให้ตรงกัน
- ความต่างของเครื่อง dev อาจทำให้ build ไม่เหมือนกัน → ให้ถือว่า **build จาก CI เท่านั้นที่เป็นของจริง** build บนเครื่อง dev ใช้เพื่อพัฒนาเท่านั้น

## หมายเหตุการ implement

กฎ permission ที่ต้องระวังในฝั่ง backend (เคยทำให้เว็บล่มมาแล้วในโปรเจกต์อื่น):

`docker exec` เข้าไปเป็น **root** โดยดีฟอลต์ แต่ php-fpm worker รันเป็น **www-data** และไม่มี `CAP_FOWNER`
ไฟล์ที่ root สร้างไว้ใน `storage/` worker จะ `touch()` ไม่ได้ → `500 touch(): Utime failed`

```bash
docker compose exec -u www-data php php artisan config:cache   # ✅
docker compose exec php php artisan config:cache               # ❌
```

ทุกคำสั่ง artisan ที่เขียนลง `storage/` หรือ `bootstrap/cache` ต้องมี `-u www-data`
→ ห่อไว้ใน `Makefile` ทุกตัว เพื่อไม่ให้ใครต้องจำเอง
