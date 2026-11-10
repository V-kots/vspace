FROM postgres:17.6-alpine

# Patroni + etcd v2/v3 client. python-etcd из extra [etcd] работает через
# etcd v2 API, поэтому etcd запускается с --enable-v2=true.
RUN apk add --no-cache \
        python3 \
        py3-pip \
        py3-psycopg2 \
        gettext \
        curl \
    && python3 -m pip install --no-cache-dir --break-system-packages 'patroni[etcd]==4.1.5'

COPY entrypoint.sh /usr/local/bin/patroni-entrypoint.sh
COPY post_bootstrap.sh /usr/local/bin/post_bootstrap.sh
COPY patroni.yml.tmpl /etc/patroni.yml.tmpl

RUN chmod +x /usr/local/bin/patroni-entrypoint.sh /usr/local/bin/post_bootstrap.sh

ENTRYPOINT ["/usr/local/bin/patroni-entrypoint.sh"]
