# 📄 Product Requirement Document (PRD)
## Project: Mobile & API Security Hardening (MVP)

---

## 1. Executive Summary & Objective
เอกสารฉบับนี้ระบุข้อกำหนดทางเทคนิค (Technical Requirements) ในระดับ **MVP** สำหรับระบบลงทะเบียนอุปกรณ์และความปลอดภัยของแอปพลิเคชัน เพื่อป้องกันการถูกดัดแปลงแก้ไข (Tampering), การทำ Reverse Engineering, การเข้าถึงระบบโดยอุปกรณ์ที่ไม่ได้รับอนุญาต และการใช้เครื่องที่ผ่านการ Root/Jailbreak

---

## 2. Security & Anti-Tampering Standard (Release Build Only)

### 2.1 Code Obfuscation & Build Security
* **Production Key Management:**
  * **ห้ามใช้ `debug.keystore` ในการ Build Release APK โดยเด็ดขาด**
  * ต้องใช้ **Production Keystore (Release Key)** ที่มีการเข้ารหัสด้วย Password ที่ปลอดภัย และจัดเก็บไฟล์ `.jks` ไว้ใน Secure Environment (เช่น CI/CD Secrets / Vault)
* **Code Obfuscation:**
  * **Flutter/Android:** เปิดใช้งาน ProGuard / R8 ใน `android/app/build.gradle` (`minifyEnabled true`, `shrinkResources true`)
  * กำหนดไฟล์ `proguard-rules.pro` เพื่อ Obfuscate โค้ดฝั่ง Native และซ่อน Class Name / Method Name ทั้งหมด ยกเว้น Model ที่ต้องใช้ในการ Parse JSON

### 2.2 Root / Jailbreak & Environment Detection
* **Client-Side Checking (Flutter):**
  * ก่อนเข้าถึงหน้าแรกของ App ให้ใช้ Package (เช่น `flutter_jailbreak_detection` หรือ Native Check) ตรวจจับ:
    * Root Status / SU Binary
    * Developer Options / Test-Keys
    * Emulator / Mock Location
  * **Behavior:** หากตรวจพบ ให้แสดงหน้าจอแจ้งเตือนความปลอดภัย และปิดแอปพลิเคชันทันที (Kill App)

### 2.3 App Signature Hash Verification
* **Signature Extraction:**
  * ดึงค่า **SHA-256 Fingerprint** จาก Production Release Keystore
* **Integrity Handshake:**
  * เมื่อ App ส่ง Request ไปยัง API Server ต้องแนบ Header `X-App-Signature` (หรือคำนวณ Signature ร่วมกับ Payload HMAC)
  * **API Server Validation:** Server นำ Signature ที่ส่งมา เทียบกับ Signature ค่ามาตรฐานที่ลงทะเบียนไว้ใน Database/Environment Variable หากไม่ตรงกันให้ตอบกลับ `HTTP 403 Forbidden`

---

## 3. Device Activation & Authentication Flow (MVP)

```text
[ เจ้าหน้าที่ Admin ] ──► สร้าง Code / QR Code ใน Web Portal
                                   │
                                   ▼
[ แอป Flutter ] ────► สแกน QR Code ──► อ่าน Activation Token
                                   │
                                   ▼
[ แอป Flutter ] ────► ดึง Hardware UUID ──► ส่ง API ไป Enroll Device
                                   │
                                   ▼
[ แอป Flutter ] ────► ตั้ง PIN Code (6 หลัก) ──► เข้าสู่หน้าหลัก
```

---

## 4. Requirement Spec: Flutter Mobile App (Client)

### 4.1 Feature Requirements
1. **Root / Tamper Checking:** ตรวจจับ Root สภาพแวดล้อมตั้งแต่เริ่มต้น App
2. **QR Code Scanner Screen:** หน้าจอสำหรับสแกน Activation QR Code เพื่อดึง `activation_token`
3. **Hardware UUID Binding:** ดึงค่า Hardware UUID เฉพาะของเครื่อง (เช่น `android_id` ผ่าน `device_info_plus`)
4. **PIN Code Setup & Input Screen:**
   * หน้าจอตั้งค่า PIN 6 หลักเมื่อลงทะเบียนครั้งแรก
   * หน้าจอยืนยัน PIN สำหรับการเปิดเข้าใช้งานประจำวัน (Session Unlock)

### 4.2 Data Payload & API Contracts (Flutter -> API)

#### API 1: Device Activation Request
* **Endpoint:** `POST /api/v1/device/activate`
* **Headers:**
  ```http
  Content-Type: application/json
  X-App-Signature: <SHA256_APP_SIGNATURE_HASH>
  ```
* **Request Body:**
  ```json
  {
    "activation_token": "ACT-8F9B2C1D-2026",
    "hardware_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "device_model": "Samsung Galaxy A54",
    "os_version": "Android 14"
  }
  ```
* **Response (200 OK):**
  ```json
  {
    "status": "success",
    "device_token": "dev_token_xyz123...",
    "user": {
      "user_id": "USR-001",
      "name": "สมชาย สายลุย"
    }
  }
  ```

#### API 2: PIN Setup Request
* **Endpoint:** `POST /api/v1/auth/pin/setup`
* **Headers:**
  ```http
  Authorization: Bearer <device_token>
  X-App-Signature: <SHA256_APP_SIGNATURE_HASH>
  ```
* **Request Body:**
  ```json
  {
    "hardware_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "pin_hash": "<SHA256_OF_PIN>"
  }
  ```

---

## 5. Requirement Spec: API Server (Backend)

### 5.1 Business Logic Requirements
1. **App Signature Guard (Middleware):**
   * สร้าง Middleware ตรวจสอบ HTTP Header `X-App-Signature` ทุกครั้งที่มี Request มาจาก Mobile API
   * Reject ทันทีหาก Header หายไป หรือค่า Hash ไม่ตรงกับ Release Key ของบริษัท
2. **Activation Code Management:**
   * สามารถสร้าง Activation Token ที่ผูกกับ ID ผู้ใช้งาน/พนักงาน โดยมีอายุการใช้งาน (e.g. 15 นาที)
   * Token สามารถใช้ลงทะเบียนได้เพียง **1 ครั้งเท่านั้น (Single-Use)**
3. **Device Binding Logic:**
   * บันทึกความสัมพันธ์ระหว่าง `user_id` + `hardware_uuid` + `device_token` ใน Database
   * หากมีการพยายามใช้ `user_id` เดียวกันบน `hardware_uuid` เครื่องอื่น ระบบจะไม่อนุญาต จนกว่า Admin จะสั่ง Reset Device บน Web Portal

### 5.2 Database Schema (MVP)

#### Table: `devices`
| Column Name | Type | Key | Description |
|---|---|---|---|
| `id` | UUID | PK | รหัส Primary Key |
| `user_id` | VARCHAR | FK | รหัสผู้ใช้งาน / คนขับ / พนักงานส่งเสริม |
| `hardware_uuid` | VARCHAR | UNIQUE | รหัสประจำเครื่อง Android |
| `device_token` | VARCHAR | UNIQUE | Token ยืนยันเครื่องที่ผ่าน Activation แล้ว |
| `pin_hash` | VARCHAR | - | ค่า Hashed PIN 6 หลัก |
| `status` | VARCHAR | - | `ACTIVE`, `REVOKED`, `PENDING` |
| `created_at` | TIMESTAMP | - | วันเวลาที่ลงทะเบียน |

#### Table: `activation_tokens`
| Column Name | Type | Key | Description |
|---|---|---|---|
| `token` | VARCHAR | PK | Activation Code / QR Token |
| `user_id` | VARCHAR | FK | ผู้ได้รับสิทธิ์ลงทะเบียน |
| `is_used` | BOOLEAN | - | สถานะการใช้งาน (`true`/`false`) |
| `expired_at` | TIMESTAMP | - | วันเวลาหมดอายุของ Code |

---

## 6. Acceptance Criteria (MVP Definition of Done)

* [ ] **Build:** Flutter App สามารถ Build Release APK ที่ผ่านการ Obfuscate ด้วย R8/ProGuard โดยใช้ Production Keystore เท่านั้น
* [ ] **Root Guard:** หากเปิด App บนเครื่องที่ Rooted ระบบจะแสดง Warning และปิดแอปทันที
* [ ] **Activation:** สามารถใช้กล้องสแกน QR Code เพื่อนำ Activation Token ไปส่งผูกกับ Hardware UUID ได้สำเร็จ
* [ ] **Binding Security:** นำ Activation Code เดียวกันไปสแกนบนเครื่องที่สอง จะต้องแสดง Error ตอบกลับจาก Server
* [ ] **Signature Check:** หากยิง API โดยไม่มี Header `X-App-Signature` หรือส่งค่า Hash ปลอม API Server จะตอบกลับ `403 Forbidden`
* [ ] **PIN Auth:** ตั้งค่า PIN 6 หลักสำเร็จ และสามารถใช้ PIN เพื่อยืนยันเข้าสู่ระบบในครั้งถัดไปได้
