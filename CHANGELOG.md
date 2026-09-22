# Changelog

รูปแบบตาม [Keep a Changelog](https://keepachangelog.com/th/1.1.0/)
เวอร์ชันตาม [SemVer](https://semver.org/lang/th/)

ทุก PR ที่มีผลกับผู้ใช้หรือผู้ integrate ต้องเพิ่มบรรทัดใน `[Unreleased]` (CLAUDE.md §4)

## [Unreleased]

### Added
- เอกสารออกแบบฉบับเต็ม (`docs/architecture.md`) — 15 หัวข้อ
- API contract (`docs/api/openapi.yaml`) — OpenAPI 3.1, 8 paths (เฉพาะ API ของแอปคนขับ)
- Threat model (`docs/security/threat-model.md`) — 18 สถานการณ์การโจมตี (S1–S19 ไม่มี S15)
- ADR 8 ฉบับ:
  - 0001 — device keypair ใน hardware keystore เป็นตัวผูกอุปกรณ์
  - 0002 — Docker ครอบเฉพาะ backend, Flutter build นอก Docker (ส่วน CI ถูกแทนที่โดย 0008)
  - 0003 — ไม่ทำ Play Integrity (ตัดถาวร) ใช้ Android Key Attestation แทน
  - 0004 — เก็บเอกสารใน Garage object storage
  - 0005 — envelope encryption + เลิกใช้ presigned URL
  - 0006 — sideload + signed update manifest
  - 0007 — แยก API กับ Staff Portal เป็นคนละ container
  - 0008 — ไม่มี CI pipeline · คุณภาพและการ build ขึ้นกับคนและ `make`
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
- `scripts/dev-tls.sh` + nginx TLS — cert ของ dev เพื่อทดสอบ pinning ได้จริง
- **ตัวดาวน์โหลดและติดตั้ง APK** (§11.3 ข้อ 5–6) — โหลดแล้วตรวจ `apk_sha256` + ขนาด และตรวจ
  signing cert ทั้งกับที่ manifest ระบุและกับกุญแจของแอปเอง ก่อนส่งให้ตัวติดตั้ง · พิสูจน์บนเครื่องจริง
  ทั้งเคสสำเร็จและเคส hash ผิด (โหลดครบ 170 MB แล้วปฏิเสธ)
- `POST /devices/{id}/pin/setup-token` — ให้เครื่องที่เจ้าหน้าที่รีเซ็ต PIN ขอ token ใหม่ได้เอง
- **Signed update manifest** (§11.3) — ตรวจลายเซ็น ECDSA P-256, `expires_at`, `sequence`,
  ห้าม downgrade และตรวจว่า manifest เป็นของ package ตัวเอง · รองรับ public key หลายตัวเพื่อหมุนกุญแจ
  · `scripts/sign-manifest.sh` สำหรับลงนามออฟไลน์ (คีย์ห้ามอยู่บนเซิร์ฟเวอร์)
- **หน้าล็อกแอป** — คนขับต้องใส่ PIN 6 หลักทุกครั้งที่เปิดแอป ตรวจกับ server (lockout อยู่ที่ server)
- **ปลดล็อกด้วยลายนิ้วมือ** — refresh token ถูกเข้ารหัสด้วย key ใน Keystore ที่ TEE ไม่ยอมรันจนกว่า
  ลายนิ้วมือจะผ่าน (`setUserAuthenticationRequired`) และ key จะถูกทำลายเมื่อมีการเพิ่ม/ลบลายนิ้วมือ
- `POST /auth/refresh` และ `POST /devices/me/integrity` — มีใน `openapi.yaml` มาตั้งแต่ต้น
  แต่ยังไม่เคย implement ทั้งคู่ · แอปรายงานผลตรวจสภาพเครื่องทุกครั้งที่ปลดล็อก
  เขียน `audit_logs` เฉพาะรอบที่ตรวจเจอของ ไม่ใช่ทุกครั้งที่เปิดแอป
- middleware `AuthenticateDevice` — ตรวจ bearer token ซ้อนบนลายเซ็นอุปกรณ์
  และเช็คว่า session ยังไม่ถูกเพิกถอนทุกครั้ง
- **ตรวจ USB Debugging / Wireless Debugging / Developer Options** อ่านจาก `Settings.Global`
  และ **หน้าเตือนภาษาไทย** ก่อนหน้าล็อก แยกเป็นสองระดับ (ปิดข้อขัดแย้ง PRD §2.2 กับ CLAUDE.md §6):
  root / hook / emulator / ระบบไม่ได้ลงนามโดยผู้ผลิต → **ปิดแอปอย่างเดียว**,
  debugging → เตือนพร้อมขั้นตอนปิด แล้ว *ตรวจอีกครั้ง* หรือ *ใช้งานต่อ* ได้
- **ปุ่ม Restore อุปกรณ์** ในหน้าเจ้าหน้าที่ — เฉพาะ admin ต้องกรอกเหตุผล และเขียน `audit_logs`
  · session เดิมไม่ถูกคืน คนขับต้องปลดล็อกด้วย PIN ใหม่ · ปฏิเสธถ้าคนขับมีเครื่องอื่นอยู่แล้ว (กฎ 1:1)
- **Root / hook / emulator / debugger detection** ฝั่งแอป — ส่งขึ้น server ตอน enroll
  และแสดงบนหน้าจอ · server ใช้ขยับ risk score เท่านั้น ไม่ตัดสินใจแทน (§4.1)
- **Kill switch ของ certificate pinning** — ปิด pinning จากระยะไกลได้ผ่าน manifest ที่ลงนามแล้วเท่านั้น
  ปิด §11.5 ข้อ 4
- ปุ่ม **Show QR** ในหน้า Activation Codes — ออก token ใหม่ทุกครั้งที่กด จึงเป็นช่องทางกู้คืน
  ได้โดยยังเก็บแค่ hash ไว้เหมือนเดิม (§6.2)

### Security
- **`make audit`** — ตรวจช่องโหว่ที่ประกาศแล้วใน dependency และเพิ่มเข้าชุดคำสั่งบังคับก่อนเปิด PR
  (CLAUDE.md §3) · ก่อนหน้านี้ `composer audit` ไม่ถูกเรียกจากที่ไหนเลย ช่องโหว่ 15 รายการ
  จึงอยู่มาได้โดยไม่มีอะไรฟ้อง
- **ปิดช่องโหว่ 15 รายการใน 4 package** — `composer audit` สะอาดแล้ว
  · `filament/filament` v5.7.2 → v5.8.4 (รหัส MFA ใช้ซ้ำได้หลังออกรหัสใหม่ · เปิดเผยว่ารหัสผ่าน
  ถูกต้องให้บัญชีที่ถูกปฏิเสธไม่ให้เข้า panel) · `livewire/livewire` v4.3.3 → v4.4.6 (DOM-based XSS)
  — สองตัวนี้กระทบ Staff Portal โดยตรง
  · `guzzlehttp/guzzle` 7.15.1 → 7.15.5 (host แบบ noncanonical ข้ามการตรวจ host ได้)
  · `league/commonmark` 2.8.3 → 2.10.3 (DoS 8 รายการ + XSS 2 รายการ)
- **`activity_log_days` และ `pii_access_log_days` จงใจไม่บังคับใช้** — `audit_logs` เป็น
  append-only มี database trigger ปฏิเสธ DELETE และ model โยน exception ก่อนถึง trigger
  การลบต้องผ่าน job แยกที่มีขั้นอนุมัติตาม architecture.md §5 ซึ่งยังไม่มี
  `retention:apply` พิมพ์บอกเหตุผลทุกครั้งที่รัน แทนที่จะปล่อยให้เป็นช่องตั้งค่าที่ดูเหมือนทำงาน
- **บันทึกว่าการเชื่อมต่อภายในไม่มี TLS และไม่มี mTLS เลย** (`architecture.md` §12.8 + threat model S19) —
  `postgres` ใช้ scram-sha-256 แต่ไม่เข้ารหัส · **`valkey` ไม่มีรหัสผ่านเลย** ทั้งที่เก็บ nonce กัน replay
  และ rate limit · `garage` ต่อผ่าน `http://` · สิ่งที่กันอยู่คือ network segmentation อย่างเดียว
  พร้อมระบุเงื่อนไขที่ทำให้ข้อสรุปนี้ใช้ไม่ได้ (ย้าย service ออกไปคนละเครื่อง) และกับดักของ `sslmode=prefer`
  ที่ถอยไป plaintext เงียบๆ โดยไม่มี error
- **แผนเก็บ `.jks` เมื่อถึงเวลาปล่อยของจริง: "เครื่อง release เฉพาะ"** แทน CI secret ที่ไม่มีแล้ว (ADR 0008)
  — โปรเจกต์นี้ **ยังไม่มี production keystore** กุญแจที่มีเป็นของ dev ที่ `make init` สุ่มต่อเครื่อง
- **🔴 สองข้อที่ต้องเตรียมก่อนสร้าง keystore ดอกแรก** (threat model S8) — keystore **หาย** เสียหายพอกับ
  **หลุด** (เซ็น APK ใหม่ไม่ได้ = ติดตั้งใหม่ทั้งฐาน) จึงต้องมี backup เข้ารหัส 2 ที่ที่ทดสอบกู้จริงแล้ว
  · และการถือ `.jks` กับ `MANIFEST_SIGNING_KEY` ไว้เครื่องเดียวกัน = ยึดเครื่องเดียวได้ทั้ง APK ปลอมและ manifest ปลอม
- แยก `api` / `portal` ที่ระดับ container, secret และ network (ADR 0007)
- เอกสารเข้ารหัส AES-256-GCM แบบ envelope ก่อนขึ้น Garage (ADR 0005)
- Masking ข้อมูลส่วนบุคคลเป็นค่าเริ่มต้น + step-up PIN 10 นาที + audit ทุกครั้ง
- `.gitleaks.toml` พร้อม rule เฉพาะโปรเจกต์ (KEK, pepper, manifest key, เลขบัตร 13 หลัก)
- **`retention:apply`** — คำสั่งบังคับใช้ retention ที่ผู้ดูแลตั้งไว้ (architecture.md §10.3)
  ลงตารางเวลาให้ `scheduler` เรียกทุกวัน 03:15 · ทำ crypto-shredding เอกสารและข้อมูลคนขับ
  ที่พ้นกำหนด (ลบ `dek_wrapped` · ล้าง `national_id_encrypted` / ชื่อ / เบอร์ / เลขใบขับขี่ ·
  ตั้ง `anonymized_at`) โดย **ไม่ลบแถวออกจากฐานข้อมูล** และเก็บ `national_id_hmac` ไว้กันสมัครซ้ำ
  · ลบ `integrity_reports` ที่หมดอายุจริงเพราะไม่มี `audit_logs` ชี้มาหา
  · `DocumentStore::destroy()` ที่เขียนไว้ตั้งแต่ต้นแต่ไม่เคยมีใครเรียก ถูกต่อสายเข้ากับงานนี้
- `LICENSE` — MIT เพื่อให้ใช้ repo นี้เป็นสื่อการสอนและคัดลอก pattern ไปใช้ได้
- **i18n ของแอปคนขับ** — `flutter_localizations` + ARB สองภาษา (`th` เป็นค่าเริ่มต้น, `en` ครบทุกคีย์)
  ย้ายข้อความที่ผู้ใช้เห็นทั้งหมด 58 คีย์ออกจากโค้ด ทั้งภาษาไทยและอังกฤษที่เคยปนกันอยู่ใน
  `main.dart` · เทสต์ widget เปลี่ยนไปยึดกับ key แทนตัวข้อความ การแก้คำจึงไม่ทำให้เทสต์พัง
  · ปิดช่องว่างของกฎ §2 ที่บังคับว่าข้อความผู้ใช้ปลายทางห้าม hardcode
- **`make analyse` ใช้งานได้จริงแล้ว** — เพิ่ม `larastan/larastan` (ลาก `phpstan/phpstan` มาด้วย)
  พร้อม `apps/api/phpstan.neon` ที่ level 5 ผ่านสะอาด ไม่มีไฟล์ baseline
  ข้อยกเว้นเหลือ 6 ข้อที่เขียนเหตุผลกำกับไว้ทีละข้อใน config
  · ก่อนหน้านี้ §3 บังคับให้รันคำสั่งนี้ก่อนเปิด PR ทั้งที่ยังไม่เคยติดตั้ง phpstan เลย
- **ไล่เก็บผลตรวจของเดิมครบทั้ง 41 รายการ** — แก้ของจริง 3 จุด: `ElevatedAction::isPermitted()`
  ไม่มี `default` ใน match ทำให้ scope ที่ไม่รู้จักโยน `UnhandledMatchError` ออกไปเป็น 500
  แทนที่จะปฏิเสธ · `Elevation::grant()` ไม่ได้ประกาศว่า impure ทำให้การตรวจสิทธิ์ซ้ำหลังใส่ PIN
  ถูกมองว่าเป็นโค้ดตาย · `KeyDescription` กับ `Masker` มีเงื่อนไขที่เป็นจริงไม่ได้

### Changed
- **ตัดกฎที่ผูกกับ CI ออกทั้งหมด** (ADR 0008) — repo ไม่มี CI จริง กฎที่ว่า PR ต้องผ่าน CI ก่อน merge
  และ tag ได้เฉพาะคอมมิตที่ CI ผ่าน จึงไม่เคยถูกบังคับเลย · แทนด้วย `make lint` / `make analyse` /
  `make test` / `make secrets-scan` ที่คนเปิด PR ต้องรันเองและระบุใน PR description
  · ลงนาม manifest ออฟไลน์อย่างเดียว · release build ทำบนเครื่องที่ถือ `.jks`
- **ย้ายมาใช้พอร์ตมาตรฐาน 443 และ 80 redirect** — แยก api / staff / dl ด้วย **ชื่อโฮสต์**
  บน 443 เดียว (เดิม 8080/8081/8082/8443) รูปแบบเดียวกับ prod
- **ชื่อโดเมนคงที่ `*.driver.test` + dnsmasq ในสแตก** — เดิม dev ใช้ nip.io ที่มี IP ฝังในชื่อ
  ย้ายที่เดโม่ทีต้องออก cert ใหม่ + build แอปใหม่ ตอนนี้ชื่อคงที่ ย้ายที่แก้ `HOST_LAN_IP`
  ตัวเดียว · มือถือ resolve ผ่าน dnsmasq (ตั้ง DNS ใน Wi-Fi) แล็ปท็อปใส่ `/etc/hosts` (`make dev-hosts`)
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
- **Reset PIN ของเจ้าหน้าที่ทำไม่จบ** — ตั้ง `pending_pin` แล้วล้าง `pin_hash` แต่ `pin_setup_token`
  ออกได้ที่เดียวคือตอน enroll เครื่องจึงค้างถาวร ตั้ง PIN ใหม่ไม่ได้ ต้องลบแล้ว enroll ใหม่
  ขัดกับ PRD §6.7 · เพิ่ม `POST /devices/{deviceId}/pin/setup-token` และ `E_PIN_RESET_REQUIRED`
- **token ที่ผิดเผา token ที่ถูกทิ้ง** — `Cache::pull` ลบค่าก่อนเทียบ เดาผิดครั้งเดียว
  ทำให้ token ที่ถูกต้องใช้ไม่ได้ · เทียบก่อนแล้วค่อยลบเมื่อตรง
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
- `apps/api` มีโค้ดอยู่ใน repo แล้ว — clone มาแล้วรัน `make composer c=install` ไม่ใช่ `make install`
  (`vendor/` ไม่ได้ commit ส่วน `make install` มีไว้ตอนยังไม่มี Laravel เท่านั้น)
- **PostgreSQL 18+ เปลี่ยน layout ของ data directory** ต้อง mount volume ที่ `/var/lib/postgresql`
  ไม่ใช่ `/var/lib/postgresql/data` (docker-library/postgres#1259)
