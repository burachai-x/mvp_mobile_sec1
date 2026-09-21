#!/usr/bin/env bash
#
# Prepares the Garage cluster for local development: assigns the single node a
# layout, creates the documents bucket, and grants the app's key access to it.
#
# A fresh Garage container starts with NO ROLE ASSIGNED and no buckets, so
# every document upload fails with "Unable to write file at location" until
# this runs. Safe to run repeatedly.
#
# Local only - CLAUDE.md section 8 forbids creating buckets or touching policy
# anywhere else.
set -euo pipefail

cd "$(dirname "$0")/.."

[ -f .env ] || { echo "ไม่มีไฟล์ .env — รัน 'make init' ก่อน" >&2; exit 1; }

BUCKET=${GARAGE_BUCKET:-driver-documents}
KEY_NAME=app
DC=(docker compose)
G=("${DC[@]}" exec -T garage /garage)

access_key=$(grep '^GARAGE_ACCESS_KEY=' .env | cut -d= -f2- | tr -d '"')
secret_key=$(grep '^GARAGE_SECRET_KEY=' .env | cut -d= -f2- | tr -d '"')

if [ -z "$access_key" ] || [ "$access_key" = "CHANGE_ME" ]; then
	echo "GARAGE_ACCESS_KEY ยังไม่ได้ตั้งใน .env — รัน 'make init' ก่อน" >&2
	exit 1
fi

"${DC[@]}" ps --status running --services 2>/dev/null | grep -qx garage || {
	echo "service garage ยังไม่ได้รัน — 'make up' ก่อน" >&2
	exit 1
}

# ── layout ───────────────────────────────────────────────────
# node เดียวก็ต้องมี role ไม่งั้น Garage ไม่รับ write เลย
node=$("${G[@]}" status 2>/dev/null | awk '/NO ROLE ASSIGNED/{print $1}' | head -1)

if [ -n "$node" ]; then
	echo "==> กำหนด layout ให้ node $node"
	"${G[@]}" layout assign -z dc1 -c 1G "$node" >/dev/null
	# เลข version ต้องเป็นค่าถัดไปเสมอ อ่านจาก layout เดิมแทนการเดาว่าเป็น 1
	current=$("${G[@]}" layout show 2>/dev/null | awk '/Current cluster layout version/{print $NF}')
	"${G[@]}" layout apply --version "$(( ${current:-0} + 1 ))" >/dev/null
else
	echo "==> layout มีอยู่แล้ว — ข้าม"
fi

# ── bucket ───────────────────────────────────────────────────
if "${G[@]}" bucket list 2>/dev/null | grep -qw "$BUCKET"; then
	echo "==> bucket $BUCKET มีอยู่แล้ว — ข้าม"
else
	echo "==> สร้าง bucket $BUCKET"
	"${G[@]}" bucket create "$BUCKET" >/dev/null
fi

# ── key ──────────────────────────────────────────────────────
# import ค่าจาก .env แทนการให้ Garage สุ่ม เพื่อให้ฝั่งแอปกับฝั่ง storage
# ใช้คู่เดียวกันโดยไม่ต้องคัดลอกค่ากลับเข้า .env ทีหลัง
if "${G[@]}" key list 2>/dev/null | grep -q "$access_key"; then
	echo "==> key มีอยู่แล้ว — ข้าม"
else
	echo "==> import key ของแอป"
	"${G[@]}" key import "$access_key" "$secret_key" -n "$KEY_NAME" --yes >/dev/null
fi

# ── สิทธิ์ ───────────────────────────────────────────────────
# read+write แต่ไม่ให้ owner — แอปไม่มีเหตุต้องแก้ policy ของ bucket ตัวเอง
echo "==> ให้สิทธิ์ read/write บน $BUCKET"
"${G[@]}" bucket allow --read --write "$BUCKET" --key "$KEY_NAME" >/dev/null

echo "Garage พร้อมใช้งานแล้ว (dev)"
