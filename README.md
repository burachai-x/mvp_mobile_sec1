# mvp_mobile_sec1

ระบบลงทะเบียน **คนขับรถ** และ **อุปกรณ์** พร้อม API Hardening สำหรับแอป Flutter (Android)
คนขับใช้แอปเพื่อรับงานส่งของ · เจ้าหน้าที่รับลงทะเบียนผ่าน Staff Portal

## เริ่มต้น

```bash
make init       # สร้าง .env + secret ของ dev (สุ่มให้อัตโนมัติ)
make install    # ติดตั้ง Laravel ลง apps/api (ครั้งแรกครั้งเดียว)
make up         # เปิดทุก service
make migrate
```

สามช่องทางแยกด้วย **ชื่อโฮสต์คงที่** บนพอร์ต 443 (แยกด้วย SNI) ส่วน `:80` redirect ไป https
ชื่อไม่มี IP ในตัว → ย้ายที่เดโม่แล้วไม่ต้องแก้ cert/pin/build แอป

| | URL |
|---|---|
| API (แอปคนขับ) | `https://api.driver.test` |
| Staff Portal | `https://staff.driver.test/staff` |
| manifest + APK | `https://dl.driver.test` |
| Mailpit (dev) | http://localhost:8025 |

**resolve ชื่อ:**
- แล็ปท็อป → `make dev-hosts` แล้วเอาบรรทัดไปใส่ `/etc/hosts` (ครั้งเดียว ชี้ `127.0.0.1`)
- มือถือ → ตั้ง DNS ใน Wi-Fi ให้ชี้ IP แล็ปท็อป · service `dnsmasq` ในสแตกตอบ `*.driver.test`
- ย้ายที่ → แก้ `HOST_LAN_IP` ใน `.env` ตัวเดียว แล้ว `docker compose up -d dnsmasq`

cert ของ dev สร้างด้วย `./scripts/dev-tls.sh` (SAN เป็นชื่อ ไม่ใช่ IP จึงใช้ได้ทุกที่)

> **manifest/APK ต้องคนละชื่อโฮสต์กับ API เสมอ** — เป็นช่องทางกู้คืน ห้าม pin certificate
> ถ้า pin พังพร้อมกันทั้งคู่ แอปทั้งฐานจะแก้ไม่ได้เลย (`docs/architecture.md` §11.5)

`make help` ดูคำสั่งทั้งหมด

## สิ่งที่ต้องรู้ก่อนแตะโค้ด

อ่าน **[CLAUDE.md](CLAUDE.md)** ก่อน — เป็นกฎบังคับของโปรเจกต์ ไม่ใช่คำแนะนำ

สามข้อที่พลาดบ่อยที่สุด

1. **artisan ที่เขียนลง `storage/` ต้องรันเป็น `www-data`**
   `docker exec` เข้าเป็น root แต่ php-fpm worker เป็น www-data → ไฟล์ที่ root สร้าง worker แตะไม่ได้ → `500 touch(): Utime failed`
   ใช้ `make` แทนการพิมพ์ `docker compose exec` เอง — ห่อ `-u www-data` ไว้ให้แล้วทุกตัว

2. **`api` กับ `portal` เป็นคนละ container โดยเจตนา**
   `api` เปิดสู่อินเทอร์เน็ต จึง **ไม่มี `DOCUMENT_KEK` และต่อ Garage ไม่ได้ในระดับเครือข่าย**
   ถ้าโค้ดฝั่ง API ต้องใช้ของพวกนี้ แปลว่าออกแบบผิด → ย้ายงานไป `worker`
   ตรวจด้วย `make verify-isolation`

3. **ใช้ Valkey ไม่ใช่ Redis**
   ext-redis และ driver `redis` ของ Laravel ใช้ได้ตรงๆ (โปรโตคอลเข้ากันได้) แต่ service ชื่อ `valkey`

4. **งานเจ้าหน้าที่ไม่ใช่ REST API**
   ทุกอย่างในหน้าเจ้าหน้าที่ทำผ่าน Filament (Livewire) ไม่มี endpoint `/admin/*`
   API ที่มีจริงมีแค่ 7 เส้นสำหรับแอปคนขับ — ดู `docs/api/openapi.yaml`

## เอกสาร

| ไฟล์ | เนื้อหา |
|---|---|
| [`PRD.md`](PRD.md) | ข้อกำหนดจากลูกค้า — **ห้ามแก้** |
| [`CLAUDE.md`](CLAUDE.md) | กฎการทำงาน: ภาษา / git / version / docker / security |
| [`docs/architecture.md`](docs/architecture.md) | เอกสารออกแบบฉบับเต็ม |
| [`docs/api/openapi.yaml`](docs/api/openapi.yaml) | **source of truth** ของ API contract |
| [`docs/security/threat-model.md`](docs/security/threat-model.md) | ภัยคุกคาม + มาตรการ + ความเสี่ยงที่เหลือ |
| [`docs/adr/`](docs/adr/) | บันทึกการตัดสินใจเชิงสถาปัตยกรรม (7 ฉบับ) |

## Stack

Laravel 13 · PHP 8.4 · PostgreSQL 18 · Valkey 8 · Garage (object storage) · Filament v5 · Flutter (Android)

**Flutter ไม่ build ใน Docker** — release signing ต้องใช้ `.jks` ซึ่งห้ามเข้า image layer (ADR 0002)
ติดตั้ง Flutter SDK บนเครื่องเอง และถือว่า **build จาก CI เท่านั้นที่เป็นของจริง**

## Container

| service | หน้าที่ | `DOCUMENT_KEK` | ต่อ Garage |
|---|---|---|---|
| `nginx` | reverse proxy, แยก 3 ช่องทาง | ❌ | ❌ |
| `api` | `/api/v1` ให้แอปคนขับ | **❌** | **❌** |
| `portal` | `/staff` ให้เจ้าหน้าที่ | ✅ | ✅ |
| `worker` | queue — งานเอกสาร/เข้ารหัส | ✅ | ✅ |
| `scheduler` | retention, manifest, แจ้งเตือน | ❌ | ✅ |
| `postgres` | ฐานข้อมูล | — | — |
| `valkey` | nonce, rate limit, cache, queue | — | — |
| `garage` | object storage (เอกสารเข้ารหัสแล้ว) | — | — |

`mailpit` เพิ่มเฉพาะ dev

## สถานะ

**MVP — ยังไม่มีโค้ดแอปพลิเคชัน** ตอนนี้มีเอกสารออกแบบครบและโครงสร้าง Docker ที่รันได้แล้ว

ขั้นถัดไป: `make install` → migration + model → Filament resources → API endpoints → Flutter app
