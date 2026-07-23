#!/usr/bin/env bash
# ลงนาม update manifest ด้วย release signing key
#
# 🔴 คีย์ที่ใช้กับสคริปต์นี้ **ห้ามอยู่บนเซิร์ฟเวอร์** (CLAUDE.md §7)
#    รันบนเครื่องออฟไลน์หรือใน CI ที่ถือ secret แล้วอัปโหลดเฉพาะไฟล์ผลลัพธ์
#    ใครที่รันสคริปต์นี้ได้ = สั่งติดตั้งอะไรก็ได้ลงมือถือคนขับทุกเครื่อง
#
#   ./scripts/sign-manifest.sh payload.json manifest-signing.key > manifest.json
#
# payload.json คือฟิลด์ทั้งหมดตาม docs/architecture.md §11.3
set -euo pipefail

PAYLOAD=${1:?ต้องระบุไฟล์ payload.json}
KEY=${2:?ต้องระบุ private key (PEM)}

for field in package latest_version latest_version_code min_supported_version_code \
             apk_url apk_sha256 apk_size signing_cert_sha256 mandatory sequence expires_at; do
  python3 - "$PAYLOAD" "$field" <<'PY'
import json, sys
payload = json.load(open(sys.argv[1]))
if sys.argv[2] not in payload:
    sys.exit(f"payload.json ขาดฟิลด์ {sys.argv[2]}")
PY
done

# เซ็นบน "ไบต์ของไฟล์ตามที่เป็น" แล้วส่งไปแบบ base64
#
# ไม่ทำ canonical JSON เพราะฝั่งเซ็นกับฝั่งตรวจต้องเห็นไบต์ชุดเดียวกันเป๊ะ
# ถ้าให้แต่ละฝั่ง serialize เอง ลำดับ key / รูปแบบตัวเลข / การ escape unicode
# ต่างกันเมื่อไหร่ ลายเซ็นก็ไม่ตรง และอาการที่เห็นคือ "release ที่ถูกต้องถูกปฏิเสธ"
# ซึ่งไล่หาสาเหตุยากมาก
PAYLOAD_B64=$(base64 -w0 "$PAYLOAD")

SIG_B64=$(openssl dgst -sha256 -sign "$KEY" "$PAYLOAD" | base64 -w0)

python3 - "$PAYLOAD_B64" "$SIG_B64" <<'PY'
import json, sys
print(json.dumps({"payload": sys.argv[1], "signature": sys.argv[2]}, indent=2))
PY
