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

### Notes
- ยังไม่มีโค้ดแอปพลิเคชัน — `make install` เพื่อติดตั้ง Laravel
- **PostgreSQL 18+ เปลี่ยน layout ของ data directory** ต้อง mount volume ที่ `/var/lib/postgresql`
  ไม่ใช่ `/var/lib/postgresql/data` (docker-library/postgres#1259)
