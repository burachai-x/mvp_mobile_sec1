# CLAUDE.md — mvp_mobile_sec1

กฎการทำงานของโปรเจกต์นี้ ใช้กับทั้ง Claude Code และคนในทีม
**ทุกข้อในเอกสารนี้บังคับ** ถ้าจะยกเว้นข้อไหน ต้องเขียนเหตุผลไว้ใน PR description

---

## 0. โปรเจกต์นี้คืออะไร

ระบบลงทะเบียน **คนขับรถ** และ **อุปกรณ์** พร้อม API Hardening สำหรับแอป Flutter ตาม `PRD.md`
คนขับใช้แอปเพื่อ**รับงานส่งของ**

**ไม่มี self-registration** — คนขับยื่นเอกสารกระดาษ (ใบสมัคร + สำเนาบัตรประชาชน + สำเนาใบขับขี่)
เจ้าหน้าที่ตรวจแล้วถ้าอนุมัติจึงเป็นคนกรอกข้อมูลเข้าระบบและอัปโหลดเอกสารเอง
เจ้าหน้าที่ยังเป็นผู้รับเรื่องเครื่องหาย/เปลี่ยนมือถือ และรีเซ็ต PIN ให้

**คนขับ 1 คน : อุปกรณ์ 1 เครื่อง** — บังคับด้วย partial unique index ที่ระดับฐานข้อมูล

**Staff Portal ใช้ Filament v5** — ตรวจแล้วว่า `^5.7` รองรับ Laravel 13 + PHP 8.4
**Filament เป็นตัวตั้ง** ถ้าอนาคตชนกัน ให้ถอย Laravel ลงมาให้เข้ากับ Filament ไม่ใช่ทิ้ง Filament

| เอกสาร | หน้าที่ |
|---|---|
| `PRD.md` | ข้อกำหนดจากลูกค้า — **ห้ามแก้** |
| `docs/architecture.md` | เอกสารออกแบบฉบับเต็ม |
| `docs/api/openapi.yaml` | **source of truth** ของ API contract |
| `docs/security/threat-model.md` | ภัยคุกคาม + มาตรการ + ความเสี่ยงที่เหลืออยู่ |
| `docs/adr/` | บันทึกการตัดสินใจเชิงสถาปัตยกรรม |

**Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 18 / **Valkey 8** / **Garage** (object storage) / Flutter
backend รันบน Docker ทั้งหมด

**`api` กับ `portal` เป็นคนละ container** โค้ดเบสเดียวกัน แยกด้วย `APP_ROLE` (ADR 0007)
`api` **ไม่มี `DOCUMENT_KEK` และต่อ Garage ไม่ได้** — เจตนา ไม่ใช่ความบังเอิญ

**MVP นี้ทำ Android เท่านั้น** ยังไม่ทำ iOS และยังไม่ทำโหมดออฟไลน์ (แอปต้องมีเน็ต)

**แอปไม่ขึ้น store** — แจกเป็น APK และอัปเดตตัวเองผ่าน signed manifest (§7)
ผลคือ **Play Integrity ใช้ไม่ได้ทางเทคนิค** ชั้นป้องกันจริงที่เหลือคือ
**device keypair** + **Android Key Attestation** (ตรวจ `verifiedBootState` ฝั่ง server)

**สถานะ repo:** เอกสารครบ · Docker stack รันได้ · migration + model ครบ 9 ตารางโดเมน ·
Filament 4 resource (Drivers · Devices · ActivationCodes · AuditLogs) + หน้าตั้งค่า retention ·
API 7 endpoint ตาม `openapi.yaml` (path ที่ 8 คือ `manifest.json` เสิร์ฟนอก Laravel ตาม §7) ·
แอป Flutter ทำงานครบตั้งแต่สแกน QR จนอัปเดตตัวเอง · เทสต์ 22 ไฟล์

**retention ต่อสายแล้ว** — `retention:apply` (`app/Console/Commands/ApplyRetention.php`)
ลงตารางเวลาไว้ใน `routes/console.php` ทุกวัน 03:15 ทำ crypto-shredding เอกสารและข้อมูลคนขับ
ที่พ้นกำหนด และลบ `integrity_reports` ที่หมดอายุ
`activity_log_days` กับ `pii_access_log_days` **จงใจไม่บังคับใช้** เพราะ `audit_logs` เป็น
append-only — การลบต้องผ่าน job แยกที่มีขั้นอนุมัติตาม `docs/architecture.md` §5 ซึ่งยังไม่มี
คำสั่งพิมพ์บอกเหตุผลทุกครั้งที่รัน แทนที่จะปล่อยให้เป็นช่องตั้งค่าที่ดูเหมือนทำงาน

**`make analyse` = larastan level 5** — `apps/api/phpstan.neon`
โค้ดใหม่ถูกตรวจเต็มระดับ ส่วนของเดิม 41 รายการอยู่ใน `apps/api/phpstan-baseline.neon`
ซึ่งเป็นหนี้ที่มองเห็นได้ **ไม่ใช่การลด threshold ตาม §8** — ส่วนใหญ่เกิดจาก `@property`
ที่ขาดใน model ทำให้ phpstan มองคอลัมน์ที่ cast เป็น datetime ว่าเป็น string
การไล่เก็บ baseline ให้หมดเป็นงานแยกต่างหาก

---

## 1. วิธีทำงาน

> แปลและปรับจาก <https://github.com/multica-ai/andrej-karpathy-skills/blob/main/CLAUDE.md>
> **ข้อแลกเปลี่ยนที่ยอมรับ:** กฎชุดนี้เอียงไปทาง "ระวังไว้ก่อน" มากกว่า "เร็วไว้ก่อน" งานเล็กๆ ให้ใช้วิจารณญาณ

### 1.1 คิดก่อนเขียนโค้ด
**อย่าเดา อย่าซ่อนความไม่เข้าใจ บอก tradeoff ออกมา**

ก่อนลงมือ:
- บอกสมมติฐานออกมาให้ชัด ถ้าไม่แน่ใจให้ถาม
- ถ้าตีความได้หลายทาง ให้เสนอทุกทาง **อย่าเลือกเองเงียบๆ**
- ถ้ามีวิธีที่ง่ายกว่า ให้พูด และโต้แย้งได้เมื่อมีเหตุผลรองรับ
- ถ้ามีอะไรไม่ชัด ให้หยุด ระบุว่าอะไรที่สับสน แล้วถาม

### 1.2 เรียบง่ายไว้ก่อน
**โค้ดน้อยที่สุดที่แก้ปัญหาได้ ไม่เผื่ออนาคตที่ยังไม่มีใครขอ**

- ไม่ทำฟีเจอร์เกินที่สั่ง
- ไม่สร้าง abstraction ให้โค้ดที่ใช้ที่เดียว
- ไม่ใส่ "ความยืดหยุ่น" หรือ "ตั้งค่าได้" ที่ไม่มีใครขอ
- ไม่ดักความผิดพลาดของกรณีที่เป็นไปไม่ได้
- ถ้าเขียนไป 200 บรรทัดแล้วมันเหลือ 50 ได้ **ให้เขียนใหม่**

ถามตัวเองว่า "senior engineer จะบอกว่านี่ซับซ้อนเกินไปไหม" ถ้าใช่ ให้ทำให้ง่ายลง

### 1.3 แก้เฉพาะจุด
**แตะเท่าที่จำเป็น เก็บกวาดเฉพาะที่ตัวเองทำรก**

เวลาแก้โค้ดเดิม:
- อย่า "ปรับปรุง" โค้ด คอมเมนต์ หรือการจัดรูปแบบที่อยู่ข้างๆ
- อย่า refactor สิ่งที่ไม่ได้พัง
- ทำตามสไตล์เดิมของไฟล์ แม้ตัวเองจะเขียนอีกแบบ
- เจอ dead code ที่ไม่เกี่ยวกับงาน ให้**บอก** อย่าลบเอง

เวลาการแก้ของเราทำให้เกิดของกำพร้า:
- ลบ import / ตัวแปร / ฟังก์ชัน ที่ **การแก้ของเราเอง** ทำให้ไม่ถูกใช้แล้ว
- อย่าลบ dead code ที่มีอยู่ก่อนหน้า เว้นแต่ถูกสั่ง

**บททดสอบ:** ทุกบรรทัดที่เปลี่ยน ต้องสาวกลับไปหาคำสั่งของผู้ใช้ได้โดยตรง

### 1.4 ทำงานโดยมีเป้าที่ตรวจสอบได้
**นิยามเกณฑ์สำเร็จก่อน แล้ววนจนกว่าจะผ่าน**

แปลงงานให้เป็นเป้าที่วัดได้:
- "เพิ่ม validation" → "เขียนเทสต์สำหรับ input ที่ผิด แล้วทำให้ผ่าน"
- "แก้บั๊ก" → "เขียนเทสต์ที่ reproduce บั๊กได้ แล้วทำให้ผ่าน"
- "refactor X" → "ทำให้เทสต์ผ่านทั้งก่อนและหลัง"

งานหลายขั้นตอน ให้บอกแผนสั้นๆ ก่อน
```
1. [ขั้นตอน] → verify: [ตรวจยังไง]
2. [ขั้นตอน] → verify: [ตรวจยังไง]
```
เกณฑ์ที่ชัดทำให้ทำงานวนเองได้ เกณฑ์ที่อ่อน ("ทำให้มันใช้ได้") จะต้องกลับมาถามตลอด

> **กฎชุดนี้ได้ผลเมื่อ:** diff มีของที่ไม่จำเป็นน้อยลง, ต้องรื้อเขียนใหม่เพราะทำซับซ้อนเกินน้อยลง,
> และคำถามเพื่อความชัดเจนเกิด**ก่อน**ลงมือ ไม่ใช่หลังจากพลาดไปแล้ว

---

## 2. ภาษา

### เป็นภาษาอังกฤษเสมอ
- identifier ทุกชนิด — class, method, variable, DB table/column, route name, config key, env key
- comment ในโค้ด
- commit message และ branch name
- API error code (`SCREAMING_SNAKE`) และ `message` ใน API response
- log message

เหตุผล: `grep` ได้จริง, ส่งต่อ dev ภายนอกได้, ไม่มีปัญหา encoding ใน log pipeline

### เป็นภาษาไทยได้
- บทสนทนากับผู้ใช้
- `docs/**` และ PR description — ผสมศัพท์เทคนิคอังกฤษได้ตามปกติ ไม่ต้องแปลคำเช่น middleware, token, migration

### กฎการเขียนภาษาไทย
- สะกดให้ถูก ครบวรรณยุกต์และสระ **ห้ามถอดเป็น ASCII** (ห้ามเขียน "ทาไม" แทน "ทำไม", "kho" แทน "ขอ")
- ห้ามปนภาษาไทยลงในไฟล์โค้ด แม้ในคอมเมนต์

### ข้อความที่ผู้ใช้ปลายทางเห็น
- **ห้าม hardcode** — ต้องอยู่ใน i18n resource มีอย่างน้อย `th` และ `en`
- API ส่ง `code` เป็นตัวตั้งต้นเสมอ ฝั่ง Flutter map `code` → ข้อความไทยเอง
  ห้ามให้แอปเอา `message` จาก API ไปแสดงตรงๆ (`message` มีไว้ให้ dev อ่าน ไม่ใช่ผู้ใช้)
- ห้ามใส่ emoji ในโค้ด / commit / log (ใน `docs/` ใส่ได้เท่าที่จำเป็น)

---

## 3. Git

### Branch
- default branch คือ `main` — **protected ห้าม commit ตรง** ทุกกรณี
- ตั้งชื่อ `<type>/<slug>` เช่น `feat/driver-enroll`, `sec/replay-guard`, `fix/pin-lockout-race`
- `type` ที่ใช้ได้: `feat` `fix` `sec` `perf` `refactor` `test` `docs` `chore`

### Commit message — Conventional Commits
```
<type>(<scope>): <subject>

<body ถ้าจำเป็น — อธิบาย "ทำไม" ไม่ใช่ "ทำอะไร">

<footer>
```
- `scope` บังคับสำหรับ code: `api` `admin` `mobile` `docker` `db` (ยกเว้น `docs:` และ `chore:` ที่ไม่มี scope ได้)
- `subject` อังกฤษ, imperative mood (`add` ไม่ใช่ `added`), ≤ 72 ตัวอักษร, ไม่ลงท้ายด้วยจุด
- breaking change → ใส่ `!` หลัง scope **และ** footer `BREAKING CHANGE: <คำอธิบาย>`

ตัวอย่างที่ถูก
```
feat(api): add staff-assisted driver enrollment endpoint
sec(api): reject replayed nonce within 5-minute window
fix(mobile): keep versionCode monotonic on release build
chore(deps): bump laravel/framework to 13.2.0
```

### กฎการ commit
- **1 commit = 1 การเปลี่ยนแปลงเชิงตรรกะ** ห้ามยำ reformat ปนกับ logic ในคอมมิตเดียว
- ดู `git status` และ `git diff --staged` ก่อน commit เสมอ — **ห้าม `git add -A` แบบไม่ดู**
- ห้าม `git add -f` ไฟล์ที่อยู่ใน `.gitignore` ไม่ว่ากรณีใด
- ห้าม commit เด็ดขาด: `.env`, `*.jks`, `*.keystore`, `*.p8`, `*.p12`, private `*.pem`, `vendor/`, `storage/`, `node_modules/`, `*.sqlite`
- ห้าม `push --force` บน branch ที่แชร์; ใช้ `--force-with-lease` ได้เฉพาะ branch ของตัวเองที่ยังไม่มีคนอื่นดึงไป
- **ห้าม rewrite history บน `main` ทุกกรณี**

### Pull Request
- ก่อนเปิด PR ต้องรันให้ผ่านครบ: `make lint` · `make analyse` · `make test` · `make secrets-scan`
  **ไม่มีอะไรตรวจให้อัตโนมัติ** — คนเปิด PR เป็นผู้รับผิดชอบ และต้องเขียนใน PR description ว่ารันแล้ว
- merge แบบ **squash** เท่านั้น — subject ของ squash commit ต้องเป็น Conventional Commit
- PR ที่แตะ auth / crypto / middleware / migration / **ข้อมูลส่วนบุคคลของคนขับ** ต้องมี reviewer อย่างน้อย 1 คนที่ไม่ใช่คนเขียน
- PR ที่เปลี่ยน API ต้องอัปเดต `docs/api/openapi.yaml` ในคอมมิตเดียวกัน

### Claude โดยเฉพาะ
- **ห้าม `commit` / `push` / `tag` / สร้าง PR เว้นแต่ผู้ใช้สั่งชัดเจน**
- ถ้าอยู่บน `main` ให้แตก branch ก่อนเสมอ

---

## 4. Version

### API version
- version อยู่ที่ path: `/api/v1/...`
- **non-breaking** (ทำใน `v1` ได้): เพิ่ม endpoint, เพิ่ม field ใน response, เพิ่ม optional field ใน request, เพิ่มค่า enum ที่ client เก่าไม่จำเป็นต้องรู้จัก
- **breaking** (ต้องขึ้น `/api/v2`): ลบ/เปลี่ยนชื่อ field, เปลี่ยนชนิดข้อมูล, เปลี่ยนความหมายของ field เดิม, เปลี่ยน HTTP status ของเคสเดิม, เพิ่ม required field ใน request
- **ห้ามแก้ความหมายของ field เดิมใน v1 เด็ดขาด** แม้จะดู "แค่นิดเดียว" — แอปที่ติดตั้งไปแล้วอัปเดตไม่พร้อมกัน
- deprecate: ประกาศล่วงหน้า ≥ 2 minor release และส่ง header `Deprecation` + `Sunset` (RFC 8594)

### Backend version
- SemVer `MAJOR.MINOR.PATCH`, git tag รูปแบบ `v1.2.3`
- tag ได้เฉพาะบน `main` และเฉพาะคอมมิตที่รัน `make test` + `make analyse` ผ่านแล้ว

### Flutter version
- `pubspec.yaml` → `version: MAJOR.MINOR.PATCH+BUILD`
- **`BUILD` (versionCode) เพิ่มขึ้นทางเดียว ห้ามลด ห้ามซ้ำ**
  แอปปฏิเสธ manifest ที่ `latest_version_code` ≤ ของที่ติดตั้งอยู่ (กัน rollback attack §7)
  → versionCode ที่ซ้ำหรือย้อนกลับ = **ปล่อยอัปเดตไม่ออกทั้งฐาน** แก้ได้ทางเดียวคือ bump ขึ้นไปอีก
- `MAJOR.MINOR.PATCH` ตาม SemVer ของฟีเจอร์ที่ผู้ใช้มองเห็น

### ความเข้ากันได้ระหว่างแอปกับ API
- ทุก request จากแอปแนบ header `X-App-Version`
- server มี config `MIN_SUPPORTED_APP_VERSION` — ถ้าต่ำกว่า ตอบ `426` + `E_APP_UPDATE_REQUIRED`
- ห้ามยกระดับ `MIN_SUPPORTED_APP_VERSION` ในวันเดียวกับที่ปล่อยแอปเวอร์ชันใหม่ — ต้องรอให้ผู้ใช้อัปเดตก่อน

### CHANGELOG
- ใช้ Keep a Changelog — หมวด `Added` / `Changed` / `Deprecated` / `Removed` / `Fixed` / `Security`
- ทุก PR ที่มีผลกับผู้ใช้หรือผู้ integrate ต้องเพิ่มบรรทัดใน `## [Unreleased]`

### Database migration
- **forward-only**
- **ห้ามแก้ไฟล์ migration ที่ merge เข้า `main` ไปแล้วเด็ดขาด** — ต้องเขียนไฟล์ใหม่ทับ (เครื่องอื่นรัน migration เดิมไปแล้ว การแก้ย้อนหลังทำให้ schema แตกต่างกันแบบเงียบๆ)
- ทุก migration ต้องมี `down()` ที่ใช้ได้จริง หรือใส่คอมเมนต์อธิบายว่าทำไมย้อนกลับไม่ได้
- การลบคอลัมน์ต้องแยกเป็น 2 release: release แรกหยุดเขียน/อ่าน, release ถัดไปค่อย `drop`
- migration ที่ backfill ข้อมูลจำนวนมากต้องทำเป็น queued job ห้ามใส่ใน migration ตรงๆ

### Docker image tag
- tag ด้วย git tag + short sha: `app:v1.2.3`, `app:sha-a1b2c3d`
  (`api` กับ `portal` ใช้ **image เดียวกัน** ต่างกันแค่ `APP_ROLE` — ห้าม build แยก 2 image)
- **ห้ามใช้ `latest`** ใน `compose.yaml` หรือบน production
- base image บน production ต้อง pin ด้วย digest (`@sha256:...`)

### 🔴 การแยก `api` / `portal` — ห้ามทำให้พัง
- **ห้าม mount `DOCUMENT_KEK`, `NATIONAL_ID_ENCRYPTION_KEY` หรือ credential ของ Garage ให้ container `api`**
  ถ้าโค้ดฝั่ง API ต้องใช้ของพวกนี้ แปลว่าออกแบบผิด — ให้ย้ายงานไป `worker` แทน
- **ห้ามใส่ `api` เข้า network `garage-net`**
- เปิด/ปิด route ต้องทำที่ **route registration ตาม `APP_ROLE`** ไม่ใช่ middleware
  route ของอีกฝั่งต้อง **ไม่ถูกลงทะเบียนเลย** ให้ได้ `404` ตามธรรมชาติ
- ต้องมี test ยืนยันว่า `APP_ROLE=api` แล้ว `/staff` ตอบ `404` และไม่มี KEK ใน environment
- **nginx ต้อง resolve upstream ใหม่ทุก request** (`resolver 127.0.0.11` + `set $upstream ...`)
  ถ้า `fastcgi_pass api:9000;` ตรงๆ nginx จะ cache IP ตอน start พอ recreate container
  IP สลับกัน แล้ว `api` จะเสิร์ฟ `/staff` โดยไม่มีอะไรฟ้อง — เจอจริงตอนพัฒนา
  รัน `make verify-isolation` หลัง recreate container ทุกครั้ง
- `scheduler` **ไม่ต้องมี KEK** — crypto-shredding แค่ *ลบ* `dek_wrapped` ไม่ได้ *ถอด* อะไร

### Valkey ไม่ใช่ Redis
- ใช้ **Valkey 8** (`valkey/valkey:8-alpine`) ไม่ใช่ Redis
- ext-redis และ driver `redis` ของ Laravel ใช้กับ Valkey ได้ตรงๆ โปรโตคอลเข้ากันได้ **ไม่ต้องแก้โค้ด**
- **แต่ห้ามเขียนใน `compose.yaml` / เอกสาร / ชื่อ service ว่า Redis** — service ชื่อ `valkey`

### Dependency
- commit lock file เสมอ (`composer.lock`, `pubspec.lock`)
- อัปเดต dependency แยก PR ของตัวเอง ใช้ `chore(deps):` ห้ามพ่วงมากับ PR ฟีเจอร์
- **ห้ามเพิ่ม dependency ใหม่โดยไม่บอก** — ต้องแจ้งชื่อ package และเหตุผลก่อน (ดู §1.2)

---

## 5. Docker

- คำสั่ง dev ทุกอย่างรันผ่าน `make` หรือ `docker compose exec`
  **ห้ามรัน `php` / `composer` / `artisan` / `psql` บน host โดยตรง**

### 🔴 กฎเหล็ก: artisan ที่เขียนลง `storage/` ต้องใช้ `-u www-data`

`docker exec` เข้าไปเป็น **root** โดยดีฟอลต์ แต่ php-fpm worker รันเป็น **www-data** และไม่มี `CAP_FOWNER`
→ ไฟล์ที่ root สร้างไว้ใน `storage/` worker จะ **`touch()` ไม่ได้** (`Permission denied`)
→ เว็บพังเป็น `500 touch(): Utime failed`

**อาการจะโผล่หรือไม่ ขึ้นกับ permission ของไดเรกทอรี** — ทดสอบบนโปรเจกต์นี้แล้วพบว่า
`storage/framework/views` ที่ Laravel ตั้งเป็น `0777` ทำให้ `view:cache` เป็น www-data
*ลบไฟล์ของ root แล้วสร้างใหม่ได้* จึงดูเหมือนไม่มีปัญหา
แต่บน production ที่ perm แคบกว่า (`0775`) หรือกับโค้ดที่เรียก `touch()` บนไฟล์เดิมตรงๆ **จะพัง**

→ **ownership ปนกันคือระเบิดเวลา** ไม่ใช่เรื่องที่ "ลองแล้วไม่เห็นพัง" แล้วจะข้ามได้

```bash
docker compose exec -u www-data php php artisan config:cache   # ✅
docker compose exec -u www-data php php artisan view:cache     # ✅
docker compose exec -u www-data php php artisan route:cache    # ✅
docker compose exec php php artisan config:cache               # ❌ พังแน่นอน
```

ใช้กับทุกคำสั่งที่เขียนลง `storage/` หรือ `bootstrap/cache` (`*:cache`, `optimize`, `queue:work`, `storage:link`)

### กฎอื่น
- **ห้ามใส่ secret ใน `Dockerfile`** — `ARG` และ `ENV` ติดอยู่ใน image history ถาวร ใครก็ `docker history` ดูได้
- production: secret ต้องผ่าน **Docker secrets (mount เป็นไฟล์)** ไม่ใช่ environment variable — env อ่านได้จาก `docker inspect` และติดไปกับ crash dump
- production: ไม่ bind-mount source (โค้ดอบใน image), `read_only: true`, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, non-root user, มี healthcheck และ resource limit ครบทุก service
- บน Linux ให้ map host `UID`/`GID` เข้า image ผ่าน build arg ไม่งั้นไฟล์ใน bind mount จะกลายเป็นของ root แล้วแก้จาก host ไม่ได้
- **ห้าม build Flutter ใน Docker** — Android SDK + NDK ทำให้ image โตเกิน 8 GB และ release signing ต้องใช้ `.jks` ซึ่งห้ามเข้า image layer
  → build บนเครื่องที่ติดตั้ง Flutter SDK เอง · debug build ทำที่ไหนก็ได้
  **ยังไม่มี production keystore** — เมื่อถึงเวลาปล่อยจริง release build ต้องทำบน "เครื่อง release เฉพาะ"
  เครื่องเดียว (ดิสก์เข้ารหัส · password เก็บแยก · backup ที่ทดสอบกู้แล้ว — ADR 0008)

---

## 6. Security

### ห้าม log เด็ดขาด
PIN, `pin_hash`, access token, refresh token, activation token, device UUID แบบดิบ, private key,
header `Authorization`, `X-Device-Signature`, **เลขบัตรประชาชนและข้อมูลส่วนบุคคลของคนขับ**

- ตั้ง redaction ใน logging config ไม่ใช่พึ่งวินัยคนเขียน
- ถ้าจำเป็นต้องอ้างอิงในการ debug ให้ log เป็น hash หรือ 4 ตัวท้ายเท่านั้น

### ข้อมูลส่วนบุคคลของคนขับ (PDPA)
- **เลขบัตรประชาชนต้องเข้ารหัสตอนเก็บ** (`national_id_encrypted`) และมี `national_id_hmac` แยกไว้สำหรับค้นหา/กันซ้ำ **ห้ามเก็บเป็น plaintext**
- ทุกครั้งที่เจ้าหน้าที่เปิดดูข้อมูลส่วนบุคคลของคนขับ ต้องเขียน `audit_logs`
- API ที่คืนข้อมูลคนขับต้อง mask เป็นค่าเริ่มต้น — แสดงเต็มเฉพาะเมื่อมีสิทธิ์และมีการบันทึกการเข้าถึง

### 🔴 เอกสารคนขับ (ใบสมัคร / สำเนาบัตรประชาชน / สำเนาใบขับขี่)

**นี่คือทรัพย์สินที่อ่อนไหวที่สุดในระบบ** — รั่วแล้วกู้คืนไม่ได้ เพราะคนขับเปลี่ยนเลขบัตรประชาชนไม่ได้

- ไฟล์อยู่ใน **Garage** เท่านั้น — **ห้ามเก็บใน DB, ห้ามเก็บบนดิสก์ของ container, ห้ามอยู่ใน `public/`**
- **bucket ต้อง private เสมอ** ห้ามเขียนโค้ดหรือ config ที่เปิด public read ไม่ว่ากรณีใด
- `object_key` **ต้องสุ่ม** ห้ามมีชื่อคน เลขบัตรประชาชน หรือเลขเรียงลำดับอยู่ใน key
- **ไฟล์ต้องเข้ารหัสก่อนอัปโหลดเสมอ** — envelope encryption AES-256-GCM, DEK ต่อไฟล์, ห่อด้วย KEK (ADR 0005)
  **ห้ามใช้ AES-CBC หรือโหมดที่ไม่มี authentication** และห้ามใช้ IV ซ้ำกับ key เดิม
- **ไม่ใช้ presigned URL** — ดาวน์โหลดผ่าน endpoint ที่ตรวจสิทธิ์ + step-up PIN แล้วถอดรหัสฝั่ง server
- **ถอดรหัสทั้งไฟล์ให้เสร็จก่อนส่ง ห้ามปล่อย chunk ออกไประหว่างถอด**
  AES-GCM ตรวจ auth tag ตอนจบเท่านั้น การ stream ทีละ chunk ต้องปล่อย plaintext
  ออกไปก่อนรู้ว่าไฟล์ถูกแก้หรือยัง = ส่งข้อมูลที่ยังไม่ยืนยันความถูกต้องให้ผู้ใช้
  ซึ่งแย่กว่าการใช้ memory · จำกัดไฟล์ที่ 10 MB คุมการใช้ memory อยู่แล้ว
  (ถ้าวันหนึ่งต้องรองรับไฟล์ใหญ่กว่านี้ ต้องเปลี่ยนไปเข้ารหัสเป็น chunk
  ที่แต่ละก้อนมี tag ของตัวเอง ไม่ใช่ stream GCM ก้อนเดียว)
- ทุกการดาวน์โหลดต้องเขียน `audit_logs` (`document.viewed`) แยกรายครั้ง
- ตรวจชนิดไฟล์จาก **magic bytes ของไฟล์จริง** ห้ามเชื่อ `Content-Type` header หรือนามสกุล
- รูปภาพต้อง **re-encode ใหม่** เพื่อลบ EXIF (อาจมีพิกัด GPS) และตัด payload ที่ฝังมา
- access key ของ Garage ที่แอปใช้ ต้องจำกัดสิทธิ์เฉพาะ bucket ที่จำเป็น **ห้ามใช้ admin key**
- **backup ต้องครอบ Garage ด้วย** — backup แค่ Postgres แล้วคิดว่าครบ คือความเข้าใจผิดที่จะรู้ตัวตอนกู้คืน

### กฎอื่น
- ห้ามมี endpoint / API resource / admin view ที่คืนค่า `pin_hash`, `token_hash`, `totp_secret`, `national_id_encrypted`, `object_key` ไม่ว่ากรณีใด
- ทุก endpoint ต้องประกาศ rate limit ชัดเจน — endpoint ที่ไม่มี rate limit ถือเป็น bug
- ทุกการเปลี่ยน `devices.status` และ `drivers.status` ต้องเขียน `audit_logs` ใน database transaction เดียวกัน
- **ห้ามเพิ่ม endpoint ที่ให้คนขับส่งข้อมูลส่วนตัวของตัวเองเข้าระบบ** — ตามการออกแบบ เจ้าหน้าที่เท่านั้นที่กรอกข้อมูลคนขับได้

### 🔴 ข้อมูลส่วนบุคคล: mask ก่อนเสมอ + Step-up PIN

- **API ต้อง mask เป็นค่าเริ่มต้น** — เลขบัตรประชาชน, เบอร์โทร, เลขใบขับขี่
- **การ mask ต้องทำที่ชั้น serialization ของ API ห้ามทำที่ frontend**
  ถ้า mask ที่ frontend แปลว่าข้อมูลเต็มถูกส่งออกไปแล้ว ใครเปิด devtools ก็เห็น = ไม่ได้ mask
- การดูข้อมูลเต็มหรือดาวน์โหลดเอกสาร ต้องมี **สิทธิ์** (`can_view_pii` / `can_download_documents`)
  **และ** elevated session จากการใส่ **PIN** (`/admin/session/elevate`) — มีอย่างใดอย่างหนึ่งไม่พอ
- PIN ของเจ้าหน้าที่เป็นคนละตัวกับรหัสผ่านล็อกอิน เก็บเป็น Argon2id มี lockout **ห้าม log**
- **บังคับกรอกเหตุผล** ทุกครั้งที่ยกระดับสิทธิ์ และเขียน `audit_logs` **แยกรายครั้งทุกการเข้าถึง** ไม่ใช่แค่ตอนใส่ PIN
- **`audit_logs` ของการเข้าถึง PII ห้ามถูกลบตาม retention ปกติ** ต้องเก็บนานกว่าตัวข้อมูลเสมอ

### 🔴 Retention ที่ผู้ดูแลตั้งค่าได้ = ฟีเจอร์ที่ลบข้อมูลถาวรได้

- **validate ช่วงตัวเลขในโค้ด** (7–3650 วัน) — พิมพ์ `3` แทน `30` แล้วข้อมูลหายเกือบหมดและกู้ไม่ได้
- role `admin` เท่านั้น + ต้องผ่าน step-up PIN + เขียน `audit_logs` พร้อมค่าเก่า/ค่าใหม่
- การลบใช้ **crypto-shredding** (ลบ DEK / ข้อมูลที่เข้ารหัส) **ห้าม `DELETE` แถวออกจาก DB** — จะทำให้ `audit_logs` กลายเป็นตัวชี้ลอย
- `audit_logs` เป็น **append-only** — ห้ามเขียนโค้ดที่ `update` หรือ `delete` ตารางนี้
- **"ลบอุปกรณ์" ในหน้าจอเจ้าหน้าที่ = `revoke` + soft delete ไม่ใช่ hard delete** — ต้องเก็บประวัติไว้ว่าเครื่องไหนเคยผูกกับคนขับคนไหน การลบจริงเป็นกระบวนการ PDPA แยกต่างหากที่ต้องมีการอนุมัติ
- เปรียบเทียบค่าลับต้องใช้ constant-time (`hash_equals`) **ห้ามใช้ `==` หรือ `===`**
- ห้าม disable TLS verification / ห้ามใช้ `--insecure` / `verify => false` ไม่ว่าใน environment ไหน
- `device_uuid` เก็บเป็น HMAC เท่านั้น — `DEVICE_UUID_PEPPER` **ห้ามเปลี่ยนหลังขึ้น production** เพราะ hash เดิมทั้งหมดจะใช้ไม่ได้
- ห้ามเขียน crypto primitive เอง ใช้ library มาตรฐานเท่านั้น
- input ทุกตัวจากแอปถือว่าเป็นของปลอมได้หมด — client-side check (root detection ฯลฯ) เป็นแค่ signal **ห้ามใช้ตัดสินใจแทน server**

### การตรวจสภาพเครื่อง — แยกให้ออกว่าอะไรเชื่อได้

| มาจากไหน | เชื่อได้ไหม | ใช้ยังไง |
|---|---|---|
| `IntegritySignals` (แอปตรวจ root/emulator เอง) | **ไม่ได้** — Magisk ซ่อนได้ Frida hook ได้ แก้ APK ตัดทิ้งได้ | เก็บสถิติ + คิด risk score เท่านั้น |
| `key_attestation` (TEE เซ็น chain ถึง root ของ Google) | **ได้** — แอปไม่มีกุญแจของ TEE จึงปลอมไม่ได้ | **ใช้ตัดสินใจ block/allow ได้** |

- **ห้ามเขียนโค้ดที่ block หรือ allow โดยดูจาก `IntegritySignals` อย่างเดียว**
- การตรวจ `key_attestation` ต้อง **verify certificate chain ถึง root ของ Google** ไม่ใช่แค่อ่านค่าจาก JSON ที่แอปส่งมา
  ถ้าอ่านค่าโดยไม่ตรวจ chain = เท่ากับเชื่อแอป ซึ่งไร้ความหมาย
- **MVP รัน attestation แบบ monitor mode** — บันทึกผล + แจ้งเตือน แต่ยังให้ enroll ผ่าน
  การสลับเป็นบังคับต้องทำผ่าน **feature flag ฝั่ง server** ห้าม hardcode เพราะการปล่อยแอปใหม่ต้องผ่าน sideload

---

## 7. การแจกจ่ายและอัปเดตแอป

แอป **ไม่ขึ้น store ใดๆ** แจกเป็น APK และอัปเดตตัวเองโดยเช็ค manifest ทุกครั้งที่เปิด
รายละเอียดเต็มใน `docs/architecture.md` §11 และ ADR 0006

### 🔴 ช่องอัปเดตคือเป้าหมายที่มีค่าที่สุดในระบบ

ใครควบคุม endpoint นี้ได้ = **ส่งมัลแวร์ลงโทรศัพท์คนขับทุกเครื่องพร้อมกัน**
เสียหายกว่าทุกภัยคุกคามอื่นในเอกสารรวมกัน

- **manifest ต้องถูกลงนามเสมอ — HTTPS อย่างเดียวไม่พอ**
  HTTPS ไม่ป้องกันเซิร์ฟเวอร์ถูกเจาะ / CDN หลุด / CA ออกใบรับรองผิดพลาด
- **release signing key ของ manifest ห้ามอยู่บนเซิร์ฟเวอร์** — ลงนามออฟไลน์ (`scripts/sign-manifest.sh`) แล้วอัปโหลดเฉพาะไฟล์ที่ลงนามแล้ว
- **ห้ามเขียนโค้ดที่เชื่อ manifest ก่อนตรวจลายเซ็น** ไม่ว่าจะเป็นการ debug ชั่วคราวหรืออะไรก็ตาม
- ทุกครั้งที่แตะโค้ดส่วนอัปเดต ต้องมีครบทั้ง 6 ข้อ: ตรวจลายเซ็น, `expires_at`, `sequence`, ห้าม downgrade, `apk_sha256`, signing cert
  **ขาดข้อใดข้อหนึ่ง = ช่องโหว่ ไม่ใช่ "ทำทีหลังได้"**
### Certificate pinning
- **pin เฉพาะ API ของระบบ (web app)** — ใช้ SPKI pin + **backup pin อย่างน้อย 1 ตัว**
- **ห้าม pin host ของ manifest/APK** — เป็นช่องทางกู้คืน ถ้า pin พังทั้งคู่ = แอปทั้งฐานแก้ไม่ได้
  ความปลอดภัยของช่องอัปเดตมาจากลายเซ็นบน manifest ไม่ใช่ TLS
- **ไม่ต้อง pin Garage** — แอปไม่เคยต่อ Garage โดยตรง เอกสารดึงผ่าน backend เสมอ
- pin set ต้องมีวันหมดอายุที่ fallback ไป system trust store ได้ และต้องมี runbook การต่ออายุ cert

### สิ่งที่ห้ามนับเป็นมาตรการความปลอดภัย

- **"ไม่มีใครรู้ว่ามีแอปนี้"** — APK อยู่ในมือคนขับหลายพันเครื่อง จะรั่วแน่นอน
  ออกแบบทุกอย่างโดยถือว่า APK อยู่บนอินเทอร์เน็ตสาธารณะแล้ว
- **Play Integrity ใช้ไม่ได้ทางเทคนิค** (ต้องมี Play Console) → **ห้ามเขียนหรือสื่อสารว่า "จะทำใน Phase 2"**
  ความเสี่ยงเรื่องแอปถูก hook ด้วย Frida เป็นความเสี่ยงถาวรที่ลูกค้ายอมรับแล้ว

## 8. สิ่งที่ Claude ห้ามทำ

ข้อเหล่านี้คือ "ห้าม" ไม่ใช่ "ถามก่อน"

- ห้าม `docker compose down -v` / `docker volume rm` — ลบ volume คือข้อมูลหายถาวร
- ห้าม `migrate:fresh` / `migrate:refresh` / `db:wipe` / `db:seed` บน environment ที่ไม่ใช่ local
- ห้าม `php artisan migrate --force` เอง — ให้เตรียมคำสั่งไว้ให้ผู้ใช้รัน
- ห้ามยิง request ที่เปลี่ยนสถานะ (`POST` `PUT` `PATCH` `DELETE`) ไปยัง environment ที่ไม่ใช่ local โดยไม่ได้รับอนุญาตชัดเจนเป็นครั้งๆ ไป — ตอนสำรวจให้ใช้ `GET` / `HEAD` เท่านั้น
- ห้ามสร้างหรือหมุน production key เอง (keystore, JWT signing key, pepper) — ให้เตรียมคำสั่งไว้ให้ผู้ใช้รันเอง
- ห้ามแก้ `PRD.md` — เป็นเอกสารฝั่งลูกค้า ข้อสังเกต/ข้อโต้แย้งให้เขียนไว้ใน `docs/`
- ห้ามปิด test, ใส่ `@skip`, ลด threshold ของ static analysis หรือแก้ config เพื่อให้คำสั่งตรวจผ่าน
- ห้ามใช้ข้อมูลส่วนบุคคลจริงของคนขับใน seeder, fixture หรือ test — ใช้ข้อมูลปลอมเท่านั้น
- **ห้ามดาวน์โหลด เปิดดู หรือคัดลอกเอกสารคนขับจาก Garage** เว้นแต่ผู้ใช้สั่งชัดเจนเป็นครั้งๆ ไป — เป็นสำเนาบัตรประชาชนของบุคคลจริง
- ห้ามสร้าง bucket หรือแก้ policy ของ Garage บน environment ที่ไม่ใช่ local
- **ห้ามเผยแพร่ manifest หรือ APK ขึ้น environment ที่ไม่ใช่ local** — เท่ากับสั่งติดตั้งลงเครื่องคนขับจริง
- ห้ามแก้ค่า retention หรือรัน job ลบข้อมูลบน environment ที่ไม่ใช่ local

---

## 9. เมื่อไม่แน่ใจ

- **API contract** → `docs/api/openapi.yaml` คือคำตอบ ถ้าโค้ดไม่ตรง spec ให้ถือว่าโค้ดผิด
- **เหตุผลของการตัดสินใจ** → อ่าน `docs/adr/`
- **ข้อกำหนดจากลูกค้า** → `PRD.md`
- ถ้า `PRD.md` ขัดกับ `docs/architecture.md` → **หยุดแล้วถาม** อย่าเดาข้างใดข้างหนึ่ง
  (`PRD.md` ยังไม่สมบูรณ์ — จบกลางประโยคที่ §3 ดู "ช่องว่างของ PRD" ใน `docs/architecture.md`)
- ตามกฎ §1.1: ไม่ชัด = หยุด + ระบุว่าอะไรที่สับสน + ถาม **ห้ามเดาแล้วเดินต่อเงียบๆ**
