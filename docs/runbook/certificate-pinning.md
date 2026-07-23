# Runbook: Certificate Pinning และการหมุน cert ของ API

เอกสารนี้เป็นขั้นตอนปฏิบัติ ไม่ใช่เอกสารออกแบบ
เหตุผลเบื้องหลังอยู่ใน `docs/architecture.md` §6.7 และ `CLAUDE.md` §7

**ผู้รับผิดชอบ:** คนที่ดูแล cert ของ API และคนที่ build APK ต้องเป็นคนคุยกัน
**ความถี่:** ทุกครั้งที่ **เปลี่ยนคีย์** ของ cert ฝั่ง API (ไม่ใช่ทุกครั้งที่ต่ออายุ)

---

## 0. สิ่งที่ pin และไม่ pin

| host | pin ไหม | เหตุผล |
|---|---|---|
| API ของระบบ | **pin** | ช่องทางที่ข้อมูลคนขับและ token วิ่งผ่าน |
| host ของ manifest / APK | **ห้าม pin** | เป็นช่องทางกู้คืน ถ้า pin พังที่นี่ = แอปทั้งฐานแก้ไม่ได้ ความปลอดภัยมาจากลายเซ็นบน manifest |
| Garage | ไม่ต้อง | แอปไม่เคยต่อโดยตรง ดึงเอกสารผ่าน backend เสมอ |

pin เป็นแบบ **SPKI** (hash ของ public key) ไม่ใช่ hash ของทั้งใบ cert
→ **ต่ออายุ cert ด้วยคีย์เดิม ไม่ต้องปล่อยแอปใหม่**
→ **เปลี่ยนคีย์เมื่อไหร่ ต้องปล่อยแอปใหม่** และนั่นคือเหตุผลที่ต้องมี backup pin

---

## 1. คำนวณ pin

```bash
openssl x509 -in api.crt -pubkey -noout \
  | openssl pkey -pubin -outform der \
  | openssl dgst -sha256 -binary \
  | base64
```

ได้ค่าเช่น `KNydRgNoYVvChX6xxsHLWnn4xSRui5RgAbpjcA4u7sU=`

ถ้ายังไม่มี cert แต่มีคีย์อยู่แล้ว คำนวณจากคีย์ตรงๆ ได้:

```bash
openssl pkey -in api.key -pubout -outform der | openssl dgst -sha256 -binary | base64
```

**ตรวจกับของจริงที่ server เสิร์ฟอยู่เสมอ** อย่าเชื่อไฟล์ในเครื่องตัวเอง:

```bash
openssl s_client -connect api.example.com:443 -servername api.example.com </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout \
  | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```

---

## 1.5 ทดสอบบนเครื่อง dev

`scripts/dev-tls.sh` สร้าง CA + cert ของ dev (มี IP SAN ของเครื่อง) แล้วพิมพ์ pin ทั้งสองตัวออกมา
nginx เสิร์ฟ API ผ่าน TLS ที่ **:8443** โดย mount cert เข้าไป ไม่ได้ COPY ลง image

```bash
./scripts/dev-tls.sh
docker compose up -d nginx
```

ตรวจว่า TLS ใช้ได้จริงโดย **ไม่ใช้ `-k`** (ถ้าต้องใช้ `-k` แปลว่า chain ยังไม่ผ่าน):

```bash
curl --cacert docker/nginx/dev-tls/dev-ca.crt \
  -H "X-App-Signature: <sig>" https://<ip>:8443/api/v1/health
```

ตรวจตรรกะ pin กับ handshake จริงด้วยโค้ดชุดเดียวกับที่แอปใช้:

```bash
cd apps/mobile
dart run tool/check_pin.dart <ip> 8443 ../../docker/nginx/dev-tls/dev-ca.crt <pin1>,<pin2>
```

ควรได้ครบ 3 เคส — pin ถูก `accepted: true`, pin ผิด `accepted: false`,
และถ้าไม่เชื่อ CA จะ **`HandshakeException` ตั้งแต่ก่อนถึงขั้นตรวจ pin**
เคสที่สามคือหลักฐานว่า pinning ไม่ได้แทนที่การตรวจ chain แต่ซ้อนทับลงไป

debug build เชื่อ CA ของ dev ผ่าน `network_security_config.xml`
(`apps/mobile/android/app/src/debug/res/raw/dev_ca.crt`) — release build ไม่เห็นไฟล์นี้

---

## 2. Build APK พร้อม pin

ต้องมี **อย่างน้อย 2 pin** เสมอ — pin ปัจจุบัน + pin สำรอง
แอปจะ **โยน `ArgumentError` ทันทีที่เปิด** ถ้าใส่มาแค่ 1 ตัว ตั้งใจให้พังตั้งแต่ QA ไม่ใช่ไปพังตอนหมุนคีย์

```bash
flutter build apk --release \
  --dart-define=API_BASE_URL=https://api.example.com \
  --dart-define=API_CERTIFICATE_PINS=<pin ปัจจุบัน>,<pin สำรอง> \
  --dart-define=API_CERTIFICATE_PIN_EXPIRY=2027-06-30 \
  --dart-define=APP_SIGNATURE=<sha256 ของ signing cert> \
  --dart-define=APP_VERSION=<versionCode>
```

ไม่ใส่ `API_CERTIFICATE_PINS` = **ปิด pinning** ใช้กับ build dev ที่ยิงเข้า LAN ด้วย HTTP เท่านั้น
build ที่จะเอาไปแจกจริงต้องมี pin เสมอ

---

## 3. `API_CERTIFICATE_PIN_EXPIRY` คืออะไร

วันที่ pin set **เลิกถูกบังคับ** พ้นวันนี้ไปแอปจะกลับไปเชื่อ system trust store อย่างเดียว

**ไม่ใช่วันหมดอายุของ cert** และไม่ใช่เรื่องเดียวกัน — เป็นวาล์วนิรภัย
ถ้าลืมหมุน pin แล้วไม่มีวาล์วนี้ แอปทุกเครื่องจะต่อ API ไม่ได้พร้อมกัน และแก้ไม่ได้เพราะแอปอัปเดตตัวเองผ่าน API ไม่ได้แล้ว

- ตั้งไว้ **หลังวันหมดอายุ cert ปัจจุบันอย่างน้อย 3 เดือน**
- การ pin หมดอายุ **ไม่ได้ปิด TLS** — chain, hostname, วันหมดอายุ ยังตรวจตามปกติ แค่หยุดจำกัดว่าเป็นคีย์ไหน
- ทุกครั้งที่ปล่อยแอปใหม่ ให้เลื่อนวันนี้ออกไปด้วย

---

## 4. ขั้นตอนหมุนคีย์ (ต้องทำตามลำดับ)

สลับลำดับเมื่อไหร่ = แอปที่ติดตั้งอยู่ต่อ API ไม่ได้

```
1. สร้างคีย์ใหม่ + cert ใหม่ แต่ยัง "ไม่" เอาขึ้น server
2. คำนวณ pin ของคีย์ใหม่
3. ปล่อยแอปเวอร์ชันใหม่ที่มี pin = { คีย์ปัจจุบัน, คีย์ใหม่ }
4. รอจนคนขับอัปเดตครบ  ← ข้ามไม่ได้
5. สลับ cert บน server เป็นคีย์ใหม่
6. ปล่อยแอปอีกเวอร์ชันที่มี pin = { คีย์ใหม่, คีย์สำรองถัดไป }
```

**ขั้นที่ 4 คือขั้นที่คนมักข้าม** ตรวจก่อนทำขั้นที่ 5 ว่ามีเครื่องไหนยังใช้เวอร์ชันเก่าอยู่:

```sql
SELECT app_version, count(*) FROM devices
WHERE status = 'active' GROUP BY app_version ORDER BY app_version;
```

ถ้ายังมีเครื่องค้างเวอร์ชันเก่า **อย่าสลับ cert** เครื่องพวกนั้นจะเข้าไม่ได้ทันที
และเนื่องจากแอปแจกแบบ sideload การบังคับให้อัปเดตทำได้แค่ผ่าน manifest ซึ่งไม่ได้ถูก pin (โดยตั้งใจ) จึงยังกู้ได้

---

## 5. ถ้า pin พังไปแล้ว

อาการ: แอปต่อ API ไม่ได้ ขึ้น `CertificatePinMismatch` ส่วน host ของ manifest ยังเข้าได้ปกติ

1. **อย่าเพิ่งรีบเปลี่ยน cert กลับ** ตรวจก่อนว่าเป็นการโจมตีจริงหรือหมุนคีย์ผิดขั้นตอน
   ```bash
   openssl s_client -connect api.example.com:443 -servername api.example.com </dev/null 2>/dev/null \
     | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der \
     | openssl dgst -sha256 -binary | base64
   ```
   เทียบกับ pin ที่ build เข้าไปในแอปเวอร์ชันนั้น
2. ถ้า pin ที่ได้ **ไม่ใช่ของเรา** → ถือเป็น incident ไปที่ `docs/security/threat-model.md`
3. ถ้าเป็นความผิดพลาดของเราเอง → **เอา cert เดิมกลับขึ้น server** เร็วที่สุด
   เร็วกว่าการปล่อยแอปใหม่เสมอ เพราะแอปใหม่ต้องรอคนขับกดอัปเดต
4. ถ้าคีย์เดิมหายจริงและกู้ไม่ได้ → ปล่อยแอปใหม่ผ่านช่องทาง manifest (ซึ่งไม่ถูก pin) แล้วให้คนขับอัปเดต
   ระหว่างนั้นคนขับใช้งานไม่ได้ นี่คือเหตุผลที่ห้าม pin ช่องทาง manifest

---

## 6. Checklist ก่อนปล่อยแอป

- [ ] pin ≥ 2 ตัว และตัวหนึ่งตรงกับ cert ที่ server เสิร์ฟอยู่ **ตอนนี้** (ตรวจด้วย `s_client` ไม่ใช่ไฟล์ในเครื่อง)
- [ ] `API_CERTIFICATE_PIN_EXPIRY` อยู่หลังวันหมดอายุ cert ปัจจุบัน ≥ 3 เดือน
- [ ] `API_BASE_URL` เป็น `https://` — ถ้าตั้ง pin ไว้แต่ URL เป็น `http://` แอปจะโยน `StateError`
      ตั้งใจให้พัง เพราะเคสนี้คือ "ทุกอย่างดูใช้ได้ แต่ไม่ได้ pin อะไรเลย"
- [ ] host ของ manifest/APK **ไม่ได้** ถูก pin
- [ ] `versionCode` เพิ่มขึ้น (CLAUDE.md §4)
