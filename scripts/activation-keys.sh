#!/usr/bin/env bash
#
# Generates the ES256 keypair that signs activation QR tokens (architecture.md 6.2).
#
# Asymmetric on purpose: an HS256 shared secret would have to ship inside the
# APK, and anyone extracting it could mint their own activation codes.
#
#   scripts/activation-keys.sh           print the two .env lines
#   scripts/activation-keys.sh --write   write them into .env (refuses to overwrite)
#
# Production keys are not generated here - see CLAUDE.md section 8.
set -euo pipefail

cd "$(dirname "$0")/.."

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

openssl ecparam -name prime256v1 -genkey -noout -out "$tmp/priv.pem" 2>/dev/null
openssl ec -in "$tmp/priv.pem" -pubout -out "$tmp/pub.pem" 2>/dev/null

# .env holds the PEM on one line, so newlines become the two characters \n
priv=$(awk '{printf "%s\\n", $0}' "$tmp/priv.pem")
pub=$(awk '{printf "%s\\n", $0}' "$tmp/pub.pem")

if [ "${1:-}" != "--write" ]; then
	echo "ACTIVATION_JWT_PRIVATE_KEY=\"$priv\""
	echo "ACTIVATION_JWT_PUBLIC_KEY=\"$pub\""
	exit 0
fi

[ -f .env ] || { echo "ไม่มีไฟล์ .env — รัน 'make init' ก่อน" >&2; exit 1; }

if grep -qE '^ACTIVATION_JWT_PRIVATE_KEY="?-----BEGIN' .env; then
	echo ".env มี ACTIVATION_JWT_PRIVATE_KEY อยู่แล้ว — ข้าม"
	echo "ถ้าตั้งใจจะหมุนกุญแจ ให้ลบบรรทัดเดิมออกก่อน (activation code ที่ออกไปแล้วจะใช้ไม่ได้ทันที)"
	exit 0
fi

# ค่าส่งผ่าน ENVIRON ไม่ใช่ -v เพราะ awk แปลง \n ใน -v เป็นขึ้นบรรทัดใหม่
# ซึ่งจะทำให้ .env แตกเป็นหลายบรรทัดจนอ่านไม่ออก (sed -i ก็พังแบบเดียวกัน)
export priv pub
awk '
	/^ACTIVATION_JWT_PRIVATE_KEY=/ { print "ACTIVATION_JWT_PRIVATE_KEY=\"" ENVIRON["priv"] "\""; found_priv = 1; next }
	/^ACTIVATION_JWT_PUBLIC_KEY=/  { print "ACTIVATION_JWT_PUBLIC_KEY=\"" ENVIRON["pub"] "\"";  found_pub = 1; next }
	{ print }
	END {
		if (!found_priv) print "ACTIVATION_JWT_PRIVATE_KEY=\"" ENVIRON["priv"] "\""
		if (!found_pub)  print "ACTIVATION_JWT_PUBLIC_KEY=\"" ENVIRON["pub"] "\""
	}
' .env > "$tmp/env.new"

cat "$tmp/env.new" > .env

echo "สร้าง ES256 keypair ของ activation token ลง .env แล้ว (ใช้กับ dev เท่านั้น)"
