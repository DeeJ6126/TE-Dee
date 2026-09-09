# Container service management

The target TE-Dee environment is a Docker container whose PID 1 is supervisord.
Do not install or invoke the systemd unit in `deploy/neo4j` inside this
container.

The active supervisor process was launched with
`/etc/supervisor/conf.d/supervisord.conf`. That file currently defines SSH and
MySQL directly and does not include other files. An administrator must either
add the contents of `tekg-neo4j.conf` to that active configuration or add a
dedicated include entry, then use `supervisorctl reread` and
`supervisorctl update`. Do not replace the active file without preserving its
existing SSH and MySQL program definitions. Add both `tekg-neo4j.conf` and
`tekg-php84-fpm.conf` to that active configuration.

Neo4j's own logs remain under `/app/tekg/shared/logs/neo4j`. Apache is managed
with `apache2ctl` in this container rather than `systemctl`.

The PHP-FPM program is isolated to TE-Dee and listens on `127.0.0.1:9084`.
Its DeepSeek egress currently uses the reverse SSH proxy at
`127.0.0.1:17890`; Agent and DeepThink therefore require that tunnel to remain
available until durable server egress is provided.
