# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-11-15

### Added

- `MariaDb` — MariaDB as a service of a Winter application (`Components::service()`):
  High priority, ready once `mariadb-admin ping` answers, checked while it runs (`alive`),
  stopped with a normal shutdown, restarted after a crash. Server parameters (`data`,
  `socket`, `host`, `port`, `user`, `password`, `database`, `charset`, `collation`,
  `timezone` — the application's `TIME_ZONE` by default —, `settings`, `args`, `logs`)
  and component parameters (`name`, `priority`, `critical`, `retries`, `grace`,
  `startTimeout`, `alive`), each checked when the object is made.
- Three accounts: `root`, through the socket for the system's root only (`unix_socket`) —
  an administrator's: `docker compose exec -u root server mariadb
  --socket=/run/mysqld/mysqld.sock -u root`; `healthcheck`, with no rights, for the
  readiness and alive checks; and the application's `user` — all rights on its database,
  none past it, its password asked for on every connection, over TCP only with a host. A
  configuration that forgot its user (`MySqlDbConfig`'s is `root`) is refused, not let in
  with every right.
- `bin/winter-mariadb` — initialises the data on the first start and refuses a directory
  of something else; on every start, in bootstrap mode, keeps root to the socket and the
  system's root, makes `healthcheck`, and keeps the user, its password and its database in
  step with the code; then becomes the server. The system's option files are not read.
  The password is on no command line and never on disk.
- `MariaDbOutput` — MariaDB's log read into the application's (kernel's `ProgramOutput`):
  Note → info, Warning → warning, ERROR → error, a connection's thread in the context.
- `image()` for `call service docker`: `mariadb`, its client and `tzdata`; the data and
  socket directories owned by winter, the data volume.
- `dsn()`, `socketPath()` and `isReady()` for connecting and checking.
