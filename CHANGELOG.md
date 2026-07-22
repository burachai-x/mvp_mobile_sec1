# Changelog

รูปแบบตาม [Keep a Changelog](https://keepachangelog.com/th/1.1.0/)
เวอร์ชันตาม [SemVer](https://semver.org/lang/th/)

ทุก PR ที่มีผลกับผู้ใช้หรือผู้ integrate ต้องเพิ่มบรรทัดใน `[Unreleased]` (CLAUDE.md §4)

## [Unreleased]

### Added
- เอกสารออกแบบฉบับเต็ม (`docs/architecture.md`) — 15 หัวข้อ
- API contract (`docs/api/openapi.yaml`) — OpenAPI 3.1, 15 paths
- Threat model (`docs/security/threat-model.md`) — 17 สถานการณ์การโจมตี
- ADR 7 ฉบับ:
  - 0001 — device keypair ใน hardware keystore เป็นตัวผูกอุปกรณ์
  - 0002 — Docker ครอบเฉพาะ backend, Flutter build ใน CI
  - 0003 — ไม่ทำ Play Integrity (ตัดถาวร) ใช้ Android Key Attestation แทน
  - 0004 — เก็บเอกสารใน Garage object storage
  - 0005 — envelope encryption + เลิกใช้ presigned URL
  - 0006 — sideload + signed update manifest
  - 0007 — แยก API กับ Staff Portal เป็นคนละ container
- `CLAUDE.md` — กฎ ภาษา / git / version / docker / security
- โครง Docker: `compose.yaml`, `compose.prod.yaml`, `docker/php`, `docker/nginx`, `docker/garage`
- `Makefile` — ห่อ `-u www-data` ไว้ทุก target ที่เขียนลง `storage/`
- `make verify-isolation` — ยืนยันว่า `api` ไม่มี KEK และต่อ Garage ไม่ได้

### Security
- แยก `api` / `portal` ที่ระดับ container, secret และ network (ADR 0007)
- เอกสารเข้ารหัส AES-256-GCM แบบ envelope ก่อนขึ้น Garage (ADR 0005)
- Masking ข้อมูลส่วนบุคคลเป็นค่าเริ่มต้น + step-up PIN 10 นาที + audit ทุกครั้ง
- `.gitleaks.toml` พร้อม rule เฉพาะโปรเจกต์ (KEK, pepper, manifest key, เลขบัตร 13 หลัก)

### Fixed
- **rate limit เป็น bucket เดียวทั้งระบบ** — nginx ไม่ส่ง X-Forwarded-For และไม่มี TrustProxies
  ทำให้ทุก request มาจาก IP ของ proxy · แก้ + ใช้ named limiter แยกตาม endpoint และผูกกับอุปกรณ์
- **client ปลอม X-Forwarded-For เลี่ยง rate limit ได้** — `$proxy_add_x_forwarded_for` ต่อท้ายค่าที่
  client ส่งมา แก้เป็น `$remote_addr` เขียนทับเสมอ
- 429 ไม่ใช้ error contract — แก้ให้คืน `E_RATE_LIMITED` ตาม §7
- `make test` ลบฐานข้อมูล dev ทิ้งทุกครั้ง และ nonce/rate limit ค้างใน Valkey ข้ามการรัน
  — phpunit `force="true"` ไม่ทับ `$_SERVER` ที่ Docker ตั้งไว้ แก้ด้วย `tests/bootstrap.php`
- `MIN_SUPPORTED_APP_VERSION` อ่านผ่าน `env()` ตอน runtime ซึ่งจะเงียบๆ กลับไปใช้ค่า default
  เมื่อรัน `config:cache` — ย้ายไปเป็น config key
- PIN ที่อ่อนเกินไปเผา pin_setup_token ทิ้ง ทำให้พิมพ์ผิดครั้งเดียวต้องกลับไปหาเจ้าหน้าที่
- nginx cache IP ของ upstream ตอน start ทำให้ `api` กับ `portal` สลับกันหลัง recreate
  container — แก้ด้วย `resolver` + ตัวแปรใน `fastcgi_pass`
- คอลัมน์ envelope encryption เปลี่ยนจาก `bytea` เป็น `text` + base64
  เพราะ Eloquent bind เป็น string แล้ว PostgreSQL ปฏิเสธ AES output ที่ไม่ใช่ UTF-8

### Notes
- ยังไม่มีโค้ดแอปพลิเคชัน — `make install` เพื่อติดตั้ง Laravel
- **PostgreSQL 18+ เปลี่ยน layout ของ data directory** ต้อง mount volume ที่ `/var/lib/postgresql`
  ไม่ใช่ `/var/lib/postgresql/data` (docker-library/postgres#1259)
