# Runbook: nginx บน production

`docker/nginx/prod.conf` เป็น **template** — nginx แทนค่า `${...}` จาก env ตอน
container start ไม่ต้องแก้ไฟล์ต่อ deployment แค่ตั้ง env + วาง cert + วาง ACL

## 1. env ที่ต้องตั้ง (compose หรือ orchestrator)

```
API_HOST=api.example.com       # ที่แอปคนขับต่อ — pin ที่ host นี้ (§11.5)
STAFF_HOST=staff.example.com   # หน้าเจ้าหน้าที่ — หลัง VPN/allowlist เท่านั้น
DL_HOST=dl.example.com         # manifest + APK — ห้าม pin
```

`DL_HOST` **ต้องคนละโดเมนกับ `API_HOST`** ไม่ใช่แค่ path ต่างกัน — เป็นช่องทางกู้คืน
ถ้า pin ฝั่ง API พังพร้อมกับช่องนี้ แอปทั้งฐานจะแก้ไม่ได้เลย (architecture.md §11.5)

## 2. cert — mount ตอน runtime ห้ามอบเข้า image

```
/etc/nginx/tls/system.crt   /etc/nginx/tls/system.key    # api + staff
/etc/nginx/tls/dl.crt       /etc/nginx/tls/dl.key         # ช่องอัปเดต
```

**สองใบแยกกันโดยเจตนา** — หมุนคีย์ของ API ได้โดยไม่แตะช่องอัปเดต
private key อบเข้า image layer = อยู่ถาวรใน history ใครก็ `docker history`/`docker save` ดูได้ (CLAUDE.md §5)
→ mount ผ่าน Docker secret หรือ volume ที่ผูกกับตัวจัดการ cert (Let's Encrypt / ACM ฯลฯ)

เพิ่ม mount ใน override ของ nginx:
```yaml
  nginx:
    volumes:
      - /path/to/certs:/etc/nginx/tls:ro
      - /path/to/staff-acl:/etc/nginx/staff-acl:ro
```

## 3. ACL ของหน้าเจ้าหน้าที่ (ADR 0007)

`STAFF_HOST` **deny all โดยค่าเริ่มต้น** จนกว่าจะมีไฟล์ allow — กันเผลอเปิด portal สู่อินเทอร์เน็ต
วางไฟล์ `.conf` ใน `/etc/nginx/staff-acl/` เช่น:

```nginx
# /etc/nginx/staff-acl/office.conf
allow 203.0.113.0/24;   # วง office
allow 10.8.0.0/24;      # VPN
```

`deny all;` ต่อท้ายอยู่ใน prod.conf แล้ว ที่ไม่อยู่ในลิสต์จะถูกปฏิเสธ
dir นี้มีอยู่ใน image เสมอ (แม้ว่าง) glob ที่ไม่เจอไฟล์ nginx ยอมรับได้

## 4. ยืนยันหลัง deploy

```bash
# hostname ถูกแทนแล้ว
docker compose exec nginx grep server_name /etc/nginx/conf.d/app.conf

# syntax + cert โหลดได้
docker compose exec nginx nginx -t

# :80 redirect ไป https
curl -sI http://api.example.com/ | grep -i location

# staff ถูก deny จากนอก allowlist
curl -sk -o /dev/null -w '%{http_code}\n' https://staff.example.com/staff/login   # 403 ถ้ายิงจากนอก ACL

make verify-isolation
```

## หมายเหตุ

- **ยังไม่มี `docker/nginx/prod.conf` เวอร์ชัน HSTS preload** — HSTS ตั้ง `max-age=2y; includeSubDomains`
  แต่ยังไม่ใส่ `preload` (submit ไป hstspreload.org แล้วถอนยาก) เพิ่มเมื่อโดเมนนิ่งแล้ว
- `dl` **ไม่มี HSTS** โดยเจตนา — ช่องกู้คืนต้องไม่ผูกมัดตัวเองไว้กับ https ถาวร
