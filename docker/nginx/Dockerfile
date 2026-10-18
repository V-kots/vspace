FROM nginx:1.31.6-alpine

RUN apk add --no-cache openssl

COPY docker-entrypoint.d/ /docker-entrypoint.d/
RUN chmod +x /docker-entrypoint.d/*.sh
