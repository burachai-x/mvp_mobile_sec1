# syntax=docker/dockerfile:1.7
ARG NGINX_VERSION=1.27
FROM nginx:${NGINX_VERSION}-alpine

RUN rm -f /etc/nginx/conf.d/default.conf

COPY docker/nginx/security-headers.conf /etc/nginx/conf.d/security-headers.conf
COPY docker/nginx/api-app.conf /etc/nginx/snippets/api-app.conf

# staff ACL dir ต้องมีอยู่เสมอ ไม่งั้น include glob ใน prod.conf ล้มเหลว
# (dev ไม่ได้ใช้ ปล่อยว่างไว้)
RUN mkdir -p /etc/nginx/staff-acl

ARG NGINX_ENV=dev
COPY docker/nginx/${NGINX_ENV}.conf /tmp/app.conf

# dev: วางตรงลง conf.d — hostname คงที่ (driver.test) ไม่มี env ให้แทน
# prod: วางเป็น template — entrypoint (20-envsubst-on-templates.sh) แทนค่า
#       ${API_HOST}/${STAFF_HOST}/${DL_HOST} จาก env ตอน container start
#       ตัวแปรของ nginx เอง ($host ฯลฯ) ไม่ถูกแตะ เพราะ envsubst แทนเฉพาะ
#       ตัวที่มีใน env จริง
RUN if [ "$NGINX_ENV" = "prod" ]; then \
        mkdir -p /etc/nginx/templates && \
        mv /tmp/app.conf /etc/nginx/templates/app.conf.template; \
    else \
        mv /tmp/app.conf /etc/nginx/conf.d/app.conf; \
    fi

# root ของ php ต้องมีอยู่จริงเพื่อให้ try_files ทำงาน
# (bind-mount ทับตอน dev)
RUN mkdir -p /var/www/html/public /srv/dist/app/v1

# เช็คแค่ว่า nginx ตอบได้ ไม่สนใจ status code
# (404/301 = nginx ทำงานปกติ — ห้ามถือว่า unhealthy)
#
# ยิง :80 เพราะทั้ง dev และ prod มี listener ที่พอร์ตนี้เสมอ (prod เป็น redirect
# 301) ก่อนหน้านี้ยิง :8080 ซึ่งหายไปตอนย้ายมาพอร์ตมาตรฐาน ทำให้ container ขึ้น
# unhealthy ทั้งที่ทำงานได้ · `wget -qO-` คืน non-zero เมื่อเจอ 3xx/4xx/5xx
# จึง grep หา HTTP/ แทน
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -S -q -O /dev/null http://127.0.0.1:80/ 2>&1 | grep -q 'HTTP/' || exit 1
