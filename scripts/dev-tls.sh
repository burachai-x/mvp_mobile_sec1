#!/usr/bin/env bash
# สร้าง CA และ cert สำหรับ **dev เท่านั้น** เพื่อทดสอบ certificate pinning ของแอป
#
# 🔴 ห้ามเอาผลลัพธ์จากสคริปต์นี้ไปใช้บน environment ที่ไม่ใช่ local
#    คีย์ production ต้องสร้างโดยผู้ดูแลระบบเอง (CLAUDE.md §8)
#
# private key ทั้งหมดถูก gitignore ไว้ ส่วน CA cert (ซึ่งเป็นค่าสาธารณะ) ถูก commit
# เพราะ debug build ของแอปต้องเชื่อ CA ตัวนี้ถึงจะต่อ https ของ dev ได้
# รันสคริปต์ใหม่ = CA เปลี่ยน = ไฟล์ที่ commit ไว้จะขึ้น diff ให้เห็น ไม่ใช่เปลี่ยนเงียบๆ
set -euo pipefail

cd "$(dirname "$0")/.."

TLS_DIR=docker/nginx/dev-tls
RAW_DIR=apps/mobile/android/app/src/debug/res/raw

mkdir -p "$TLS_DIR" "$RAW_DIR"

cat > "$TLS_DIR/leaf.cnf" <<CNF
[req]
distinguished_name = dn
prompt = no
[dn]
CN = mvp-dev-api
[ext]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
# สามชื่อบนพอร์ต 443 เดียวกัน แยกด้วย SNI — พอร์ตเดียวเสิร์ฟหลาย host
# ได้ก็ต่อเมื่อแยกด้วยชื่อ ไม่ใช่ด้วยเลขพอร์ต
#
# ชื่อคงที่ driver.test ไม่มี IP ในชื่อ → ย้ายที่แล้ว cert ใบเดิมใช้ได้เลย
# (SAN ผูกกับชื่อ ไม่ใช่ IP) เปลี่ยนแค่ปลายทางที่ชื่อชี้ไป
subjectAltName = DNS:api.driver.test,DNS:staff.driver.test,DNS:dl.driver.test,DNS:localhost,IP:127.0.0.1
CNF

echo "==> CA"
openssl ecparam -name prime256v1 -genkey -noout -out "$TLS_DIR/dev-ca.key"
openssl req -x509 -new -key "$TLS_DIR/dev-ca.key" -sha256 -days 825 \
  -out "$TLS_DIR/dev-ca.crt" -subj "/CN=mvp local dev CA" \
  -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
  -addext "keyUsage=critical,keyCertSign,cRLSign"

echo "==> คีย์ปัจจุบันของ API"
openssl ecparam -name prime256v1 -genkey -noout -out "$TLS_DIR/api.key"
openssl req -new -key "$TLS_DIR/api.key" -out "$TLS_DIR/api.csr" -config "$TLS_DIR/leaf.cnf"
openssl x509 -req -in "$TLS_DIR/api.csr" -CA "$TLS_DIR/dev-ca.crt" -CAkey "$TLS_DIR/dev-ca.key" \
  -CAcreateserial -out "$TLS_DIR/api.crt" -days 825 -sha256 \
  -extfile "$TLS_DIR/leaf.cnf" -extensions ext

echo "==> คีย์สำรอง (ยังไม่เอาขึ้น server แต่ pin ไว้ตั้งแต่วันแรก)"
openssl ecparam -name prime256v1 -genkey -noout -out "$TLS_DIR/api-backup.key"

# debug build ของแอปเชื่อ CA ตัวนี้ผ่าน network_security_config
cp "$TLS_DIR/dev-ca.crt" "$RAW_DIR/dev_ca.crt"

pin() { openssl pkey -in "$1" -pubout -outform der | openssl dgst -sha256 -binary | base64; }

PIN_CURRENT=$(pin "$TLS_DIR/api.key")
PIN_BACKUP=$(pin "$TLS_DIR/api-backup.key")

cat <<TXT

===============================================================
เสร็จแล้ว — cert อยู่ที่ $TLS_DIR

SPKI pins สำหรับ build APK:
  ปัจจุบัน : $PIN_CURRENT
  สำรอง    : $PIN_BACKUP

  --dart-define=API_CERTIFICATE_PINS=$PIN_CURRENT,$PIN_BACKUP

ขั้นตอนเต็มอยู่ใน docs/runbook/certificate-pinning.md
===============================================================
TXT
