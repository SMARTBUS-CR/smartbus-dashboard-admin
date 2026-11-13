# Dockerfile checklist for dual-database testing

Don't create a parallel Dockerfile — extend the project's existing one(s).
Confirm:

1. Both PDO drivers the app needs are installed, e.g. for Postgres + MySQL:
   ```dockerfile
   RUN docker-php-ext-install pdo_pgsql pdo_mysql
   # or, on Alpine-based images:
   RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql
   ```
   A missing driver fails at connection time with an opaque PDO error, not
   a clear "driver not found" — check this first if tests can't connect at
   all despite correct host/port/credentials.

2. If the test stage of a multi-stage Dockerfile is separate from the
   production stage, make sure the test stage — not just the final
   production image — has both drivers and any dev dependencies (e.g.
   PostGIS client libs if geometry columns are exercised in tests).

3. If the project uses a `.dockerignore`, confirm `.env.testing` (if used)
   isn't excluded when it needs to be copied into the test container.

4. Confirm the container's PHP `memory_limit` / `max_execution_time` are
   generous enough for a full Pest run with two live database connections
   — cross-database feature tests are slower than in-memory SQLite ones.
