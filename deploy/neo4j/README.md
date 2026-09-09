# Neo4j deployment

TE-Dee uses the `tekg3` database. The packaged Neo4j installation stays under
`/app/tekg/services`, while mutable database, log, run, and import paths stay
under `/app/tekg/shared`.

The supplied configuration binds Bolt and HTTP to loopback only. Apache does
not proxy the Neo4j Browser, and neither database port should be exposed
publicly.

The systemd unit below is retained for hosts that boot with systemd. It must not
be used in the current Docker deployment, whose PID 1 is supervisord. Use the
configuration under `deploy/supervisor` for the current server.

On a future systemd host, an administrator can install the service with:

```bash
sudo cp /app/tekg/app/deploy/neo4j/tekg-neo4j.service /etc/systemd/system/tekg-neo4j.service
sudo systemctl daemon-reload
sudo systemctl enable --now tekg-neo4j
sudo systemctl status tekg-neo4j
```

Set the initial Neo4j password before the first service start and store the
application credential only in the untracked server-local PHP configuration.
