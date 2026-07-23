#!/usr/bin/env bash
#
# ตรวจการแยก api / portal ครบทุกชั้น (ADR 0007, threat model S18)
#
# การแยกนี้พังแบบเงียบ: ตั้ง APP_ROLE ผิด หรือเผลอ mount KEK ให้ api
# ระบบยังทำงานปกติทุกอย่าง แต่ isolation หายไปโดยไม่มีอะไรฟ้อง
# สคริปต์นี้คือสิ่งเดียวที่จะจับได้ → ต้องรันใน CI ไม่ใช่แค่ตอนนึกได้
#
# ครอบ 3 ชั้น: config (static) → runtime (env จริง) → network (จริง)
set -uo pipefail
cd "$(dirname "$0")/.."

# อ่าน .env ให้เห็น port ชุดเดียวกับที่ compose ใช้ ไม่งั้นถ้าใครเปลี่ยน
# STAFF_PORT สคริปต์จะยิงไป port เดิมแล้วรายงานว่า "การแยกพัง" ทั้งที่ไม่ได้พัง
# ซึ่งอันตรายกว่าไม่มีการเช็ค เพราะ false alarm ซ้ำๆ ทำให้คนเลิกเชื่อผลของมัน
if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

DC="docker compose"
fail=0
pass() { printf '  \033[32m✅\033[0m %s\n' "$1"; }
bad()  { printf '  \033[31m❌\033[0m %s\n' "$1"; fail=1; }

# ── ชั้นที่ 1: อ่านจาก compose.yaml โดยตรง ────────────────────
# ทำงานได้แม้ container ยังไม่ขึ้น จึงใช้ใน CI ได้ทันที
echo "== ชั้น 1: config ใน compose.yaml =="

api_block=$(awk '/^  api:/{f=1;next} /^  [a-z].*:$|^networks:|^volumes:/{f=0} f' compose.yaml)
portal_block=$(awk '/^  portal:/{f=1;next} /^  [a-z].*:$|^networks:|^volumes:/{f=0} f' compose.yaml)
worker_block=$(awk '/^  worker:/{f=1;next} /^  [a-z].*:$|^networks:|^volumes:/{f=0} f' compose.yaml)
sched_block=$(awk '/^  scheduler:/{f=1;next} /^  [a-z].*:$|^networks:|^volumes:/{f=0} f' compose.yaml)

# ถ้า parse ไม่เจอ block ทุก grep จะไม่เจอแล้ว "ผ่าน" หมด ทั้งที่ไม่ได้ตรวจอะไรเลย
# การเช็คที่ผ่านเสมอแย่กว่าไม่มีการเช็ค เพราะสร้างความมั่นใจปลอม
for svc in api portal worker scheduler; do
  case $svc in
    api) b=$api_block ;; portal) b=$portal_block ;;
    worker) b=$worker_block ;; scheduler) b=$sched_block ;;
  esac
  if [ -z "$b" ]; then
    bad "parse service '$svc' จาก compose.yaml ไม่เจอ — สคริปต์ตรวจอะไรไม่ได้เลย"
    echo "     (โครง compose.yaml เปลี่ยนไป ต้องแก้ awk ใน scripts/verify-isolation.sh)"
    exit 1
  fi
done

grep -q 'pii-env'    <<<"$api_block" && bad "api ได้รับ *pii-env (KEK / เลขบัตร)"     || pass "api ไม่มี *pii-env"
grep -q 'garage-env' <<<"$api_block" && bad "api ได้รับ *garage-env (credential)"     || pass "api ไม่มี *garage-env"
grep -q 'garage-net' <<<"$api_block" && bad "api อยู่บน garage-net"                    || pass "api ไม่อยู่บน garage-net"

grep -q 'pii-env' <<<"$portal_block" && pass "portal มี *pii-env"  || bad "portal ต้องมี KEK เพื่อถอดรหัสเอกสาร"
grep -q 'pii-env' <<<"$worker_block" && pass "worker มี *pii-env"  || bad "worker ต้องมี KEK เพื่อทำงานเอกสาร"
# crypto-shredding แค่ลบ dek_wrapped ไม่ได้ถอดรหัส จึงไม่ต้องมี KEK (§10.3)
grep -q 'pii-env' <<<"$sched_block"  && bad "scheduler ไม่ควรมี KEK" || pass "scheduler ไม่มี KEK (ถูกต้อง)"

# ── ชั้นที่ 2 และ 3: ต้องมี container รันอยู่ ─────────────────
if ! $DC ps --status running --services 2>/dev/null | grep -qx api; then
  echo
  echo "  (ข้ามชั้น 2-3: container ยังไม่ขึ้น — รัน 'make up' ก่อน)"
  exit $fail
fi

echo
echo "== ชั้น 2: environment จริงใน container =="
$DC exec -T api printenv DOCUMENT_KEK               >/dev/null 2>&1 && bad "api มี DOCUMENT_KEK จริง"  || pass "api ไม่มี DOCUMENT_KEK"
$DC exec -T api printenv NATIONAL_ID_ENCRYPTION_KEY >/dev/null 2>&1 && bad "api มีกุญแจเลขบัตร"        || pass "api ไม่มีกุญแจเลขบัตร"
$DC exec -T api printenv GARAGE_SECRET_KEY          >/dev/null 2>&1 && bad "api มี Garage secret"      || pass "api ไม่มี Garage secret"
$DC exec -T portal printenv DOCUMENT_KEK            >/dev/null 2>&1 && pass "portal มี DOCUMENT_KEK"   || bad "portal ไม่มี KEK"

echo
echo "== ชั้น 3: network จริง =="
$DC exec -T api    sh -c 'nc -z -w2 garage 3900' >/dev/null 2>&1 && bad "api ต่อ garage ได้"        || pass "api ต่อ garage ไม่ได้"
$DC exec -T portal sh -c 'nc -z -w2 garage 3900' >/dev/null 2>&1 && pass "portal ต่อ garage ได้"    || bad "portal ต่อ garage ไม่ได้"
$DC exec -T api    sh -c 'nc -z -w2 postgres 5432' >/dev/null 2>&1 && pass "api ต่อ postgres ได้"   || bad "api ต่อ postgres ไม่ได้"

echo
echo "== ชั้น 4: route ที่ลงทะเบียนจริง =="
api_staff=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://localhost:${API_PORT:-8080}/staff" || echo 000)
api_health=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://localhost:${API_PORT:-8080}/api/v1/health" || echo 000)
portal_login=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://localhost:${STAFF_PORT:-8081}/staff/login" || echo 000)
portal_api=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://localhost:${STAFF_PORT:-8081}/api/v1/health" || echo 000)

[ "$api_staff"    = "404" ] && pass "api /staff = 404"              || bad "api /staff = $api_staff (ต้อง 404)"
[ "$api_health"   = "200" ] && pass "api /api/v1/health = 200"      || bad "api /api/v1/health = $api_health"
[ "$portal_login" = "200" ] && pass "portal /staff/login = 200"     || bad "portal /staff/login = $portal_login"
[ "$portal_api"   = "404" ] && pass "portal /api/v1/health = 404"   || bad "portal /api/v1/health = $portal_api"

echo
[ $fail -eq 0 ] && echo "การแยกครบทุกชั้น ✅" || echo "การแยกมีจุดพัง — ห้าม deploy จนกว่าจะแก้ ❌"
exit $fail
