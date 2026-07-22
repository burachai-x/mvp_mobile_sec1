# syntax=docker/dockerfile:1.7
ARG NGINX_VERSION=1.27
FROM nginx:${NGINX_VERSION}-alpine

RUN rm -f /etc/nginx/conf.d/default.conf

COPY docker/nginx/security-headers.conf /etc/nginx/conf.d/security-headers.conf

ARG NGINX_ENV=dev
COPY docker/nginx/${NGINX_ENV}.conf /etc/nginx/conf.d/app.conf

# root ของ php ต้องมีอยู่จริงเพื่อให้ try_files ทำงาน
# (bind-mount ทับตอน dev)
RUN mkdir -p /var/www/html/public /srv/dist/app/v1

# เช็คแค่ว่า nginx ตอบ HTTP ได้ ไม่สนใจ status code
# (404 = nginx ทำงานปกติแต่ยังไม่มีแอป — ห้ามถือว่า unhealthy
#  `wget -qO-` คืน non-zero เมื่อเจอ 4xx/5xx จึงใช้ตรงๆ ไม่ได้)
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -S -q -O /dev/null http://127.0.0.1:8080/ 2>&1 | grep -q 'HTTP/' || exit 1
