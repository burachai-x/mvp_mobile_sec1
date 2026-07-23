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
- แอปคนขับ (`apps/mobile/`) — สแกน QR, สร้าง EC P-256 ใน Android Keystore พร้อม attestation
  challenge, enroll, ตั้ง PIN และเซ็นทุก request ด้วย key ที่ export ไม่ได้
- **Certificate pinning** ฝั่งแอป — SPKI pin + backup pin บังคับ, pin set มีวันหมดอายุที่
  fallback ไป system trust store, ตรวจก่อนเขียน request byte แรก
  ทดสอบบนเครื่องจริงแล้วทั้งเคส pin ถูก / pin ผิด / CA ไม่น่าเชื่อถือ
  (`docs/runbook/certificate-pinning.md`)
- `scripts/dev-tls.sh` + nginx `:8443` — TLS สำหรับ dev เพื่อทดสอบ pinning ได้จริง
- **Signed update manifest** (§11.3) — ตรวจลายเซ็น ECDSA P-256, `expires_at`, `sequence`,
  ห้าม downgrade และตรวจว่า manifest เป็นของ package ตัวเอง · รองรับ public key หลายตัวเพื่อหมุนกุญแจ
  · `scripts/sign-manifest.sh` สำหรับลงนามออฟไลน์ (คีย์ห้ามอยู่บนเซิร์ฟเวอร์)
- **หน้าล็อกแอป** — คนขับต้องใส่ PIN 6 หลักทุกครั้งที่เปิดแอป ตรวจกับ server (lockout อยู่ที่ server)
- **ปลดล็อกด้วยลายนิ้วมือ** — refresh token ถูกเข้ารหัสด้วย key ใน Keystore ที่ TEE ไม่ยอมรันจนกว่า
  ลายนิ้วมือจะผ่าน (`setUserAuthenticationRequired`) และ key จะถูกทำลายเมื่อมีการเพิ่ม/ลบลายนิ้วมือ
- `POST /auth/refresh` — มีใน `openapi.yaml` มาตั้งแต่ต้นแต่ยังไม่เคย implement
- **ตรวจ USB Debugging / Wireless Debugging / Developer Options** อ่านจาก `Settings.Global`
  และ **หน้าเตือนภาษาไทย** ก่อนหน้าล็อก บอกว่าเจออะไรพร้อมวิธีปิด เลือก *ตรวจอีกครั้ง*
  หรือ *ใช้งานต่อ* ได้ — เตือน ไม่ปิดแอป (ปิดข้อขัดแย้ง PRD §2.2 กับ CLAUDE.md §6)
- **Root / hook / emulator / debugger detection** ฝั่งแอป — ส่งขึ้น server ตอน enroll
  และแสดงบนหน้าจอ · server ใช้ขยับ risk score เท่านั้น ไม่ตัดสินใจแทน (§4.1)
- **Kill switch ของ certificate pinning** — ปิด pinning จากระยะไกลได้ผ่าน manifest ที่ลงนามแล้วเท่านั้น
  ปิด §11.5 ข้อ 4
- ปุ่ม **Show QR** ในหน้า Activation Codes — ออก token ใหม่ทุกครั้งที่กด จึงเป็นช่องทางกู้คืน
  ได้โดยยังเก็บแค่ hash ไว้เหมือนเดิม (§6.2)

### Security
- แยก `api` / `portal` ที่ระดับ container, secret และ network (ADR 0007)
- เอกสารเข้ารหัส AES-256-GCM แบบ envelope ก่อนขึ้น Garage (ADR 0005)
- Masking ข้อมูลส่วนบุคคลเป็นค่าเริ่มต้น + step-up PIN 10 นาที + audit ทุกครั้ง
- `.gitleaks.toml` พร้อม rule เฉพาะโปรเจกต์ (KEK, pepper, manifest key, เลขบัตร 13 หลัก)

### Changed
- **ปลดล็อกด้วย PIN สำเร็จ จะเพิกถอน session เดิมของเครื่องนั้นทั้งหมด** — เดิมสะสม refresh token
  ที่ยังใช้ได้ตัวละ 30 วันเพิ่มขึ้นทุกครั้งที่ปลดล็อก (ทดสอบวันเดียวค้าง 14 ตัว)
- **PIN ผิดตอบ `E_PIN_INVALID` พร้อม `attempts_remaining`** — เดิมตอบ `E_DEVICE_SIGNATURE_INVALID`
  เหมือนลายเซ็นผิด ซึ่งตาม §7 แปลว่าแอปต้องสั่ง enroll ใหม่ คนขับพิมพ์ผิดหลักเดียว
  จึงต้องกลับไปขอ activation code จากเจ้าหน้าที่ (เจอตอนทดสอบบนเครื่องจริง)
- รูปแบบ manifest: `payload` เป็น base64 ของไบต์ที่ถูกเซ็นจริง แทน "canonical JSON"
  เพื่อตัดปัญหาฝั่งเซ็นกับฝั่งตรวจ serialize ไม่ตรงกัน (`docs/architecture.md` §11.3)
- อายุ activation code ดีฟอลต์ 7 วัน → **15 นาที** ให้ตรงกับที่ `docs/architecture.md` §6.2 ระบุไว้
- การออก code ไม่ลงนาม token อีกแล้ว แถวที่สร้างใหม่ยัง redeem ไม่ได้จนกว่าจะกด Show QR ครั้งแรก

### Fixed
- **ปุ่ม Issue ไม่ทำงานและไม่ฟ้องอะไรเลย** — ซ่อนวินาทีในช่อง expiry แต่ `min` พาวินาทีมาด้วย
  เบราว์เซอร์จึง step ทีละ 60 วินาทีจากจุดที่มีเศษ ค่านาทีถ้วนทุกค่าตกกริดและไม่ผ่าน
  native validation → ไม่มี request ถูกส่ง ไม่มี error ไม่มีแถวถูกสร้าง
- **QR ไม่เคยถูกแสดง** — notification ถูกประกอบใหม่จาก array ระหว่างส่งไปเบราว์เซอร์
  แล้ว Filament ทิ้ง view ที่ไม่อยู่ใน safe list โดยไม่บอก เจ้าหน้าที่เห็นแค่ข้อความว่าออก code สำเร็จ
  · แก้ด้วยการย้าย QR ไปเป็น row action แทน transient notification
- **Staff Portal ไม่มี CSS/JS** — `$host` ของ nginx ตัด port ทิ้ง Laravel จึงสร้าง asset URL
  ไม่มี port และ Livewire ไม่ทำงาน · ส่ง `X-Forwarded-Port $server_port` (ไม่ใช่ port จาก
  Host header ที่ client ปลอมได้)
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
