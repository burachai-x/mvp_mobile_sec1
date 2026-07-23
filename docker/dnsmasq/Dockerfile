# syntax=docker/dockerfile:1.7
# DNS สำหรับ dev เท่านั้น — ตอบ *.driver.test เป็น IP ของแล็ปท็อป
# เพื่อให้มือถือ (ที่แก้ /etc/hosts ไม่ได้) resolve ชื่อโดเมนคงที่ได้
#
# สร้างเองจาก alpine เพื่อ audit ได้ ไม่ดึง image dnsmasq ของบุคคลที่สาม
ARG ALPINE_VERSION=3.20
FROM alpine:${ALPINE_VERSION}

RUN apk add --no-cache dnsmasq

# ตอบเฉพาะ driver.test ที่เหลือ forward ขึ้น upstream ไม่งั้นมือถือที่ชี้ DNS
# มาที่นี่จะเข้าเน็ตไม่ได้ · ค่า address ส่งผ่าน command ใน compose
ENTRYPOINT ["dnsmasq", "--keep-in-foreground", "--log-facility=-"]
