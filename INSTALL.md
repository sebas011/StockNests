# Install StockNest on another device

Download this repository to install the GAA/APPCSE inventory system on Windows, macOS or Linux. Each new installation has its own database. GitHub contains the application source and installation files; it does not contain your users, inventory records, passwords or database backups.

The implemented core has passed backend, frontend and browser checks. See [verification](docs/verification.md) and [remaining features](docs/implementation-status.md) for the current development scope.

## 1. Install the prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/), running with Linux containers. On Linux, Docker Engine with the Compose plugin also works.
- [Node.js 24 LTS](https://nodejs.org/en/download). This runs the initial configuration script; application dependencies build inside Docker.
- Git if you want to clone and update the repository. Git is optional for a ZIP download.

You do not need to install PHP, Composer, PostgreSQL or Redis separately for this method. The first build needs internet access to download dependencies and container images.

## 2. Download the application

**With Git**, open PowerShell or a terminal:

```sh
git clone https://github.com/sebas011/StockNests.git
cd StockNests
```

**With a ZIP**, open [the repository](https://github.com/sebas011/StockNests), choose **Code → Download ZIP**, and extract it. Open a terminal in the extracted folder containing `compose.yaml` and `README.md`. Run the commands below from that folder.

## 3. Initialize and start

Start Docker Desktop, then run:

```sh
node scripts/initialize-env.mjs
docker compose up -d --build --wait
docker compose exec app php artisan migrate --seed
```

The first command creates your local `.env` with a random application key and database password. Repeating it preserves existing keys. Keep this file private and backed up; it is excluded from Git.

The first build can take several minutes. A completed migration/seed creates the GAA/APPCSE reference data and initial role definitions. It creates no administrator, sample stock or sample expenses.

## 4. Create your administrator

Replace the example address/name with your own:

```sh
docker compose exec app php artisan stocknest:admin --email=admin@example.test --name="StockNest Administrator"
```

Enter a unique password when prompted. The password must have at least 12 characters and include uppercase/lowercase letters, a number and a symbol. There is no shared default administrator password.

Open **[http://localhost:8080](http://localhost:8080)** and log in with that account. The development email inbox is **[http://localhost:8025](http://localhost:8025)**. These addresses work on the device running Docker.

The administrator can configure references and create named user accounts. Follow the [administrator guide](docs/guides/administrator.md) and [end-user guide](docs/guides/end-user.md).

## Optional: try sample transactions

Only in a separate, disposable installation, set this line in `.env`:

```dotenv
STOCKNEST_DEMO=true
```

Then run:

```sh
docker compose up -d --wait
docker compose exec app php artisan db:seed --class=DemoSeeder
```

The seeder prints a newly generated local demo password. Demo accounts include `admin@stocknest.test`, `requester@stocknest.test`, `head@stocknest.test`, `approver@stocknest.test` and `custodian@stocknest.test`. Demo records are intentionally labeled and include separately attributed GAA/APPCSE transactions. Use your own email when creating an administrator first, so it does not collide with these demo accounts.

## Stop, restart and update

```sh
# Stop services; the database remains in its Docker volume.
docker compose down

# Start the existing installation again.
docker compose up -d --wait
```

Keep the project folder, its `.env`, and Docker volumes. Removing Docker volumes deletes the local database; it is not an update step.

For a Git installation, back up the database, private files and existing `.env` before updating. Review the changes, then run:

```sh
git pull --ff-only
docker compose up -d --build --wait
docker compose exec app php artisan migrate --force
```

Keep the existing application key and database password. Do not run `migrate:fresh`, regenerate the application key, or install demo data as an update. ZIP users can get later code using Download ZIP, but must preserve their original `.env` and data; Git is the simpler update method.

## Move existing data or share one system

A fresh download creates a separate installation; it does not copy records from another device. To move an existing installation, transfer a consistent PostgreSQL backup, private files and the original encryption key securely, using the same application revision. Follow [backup and restore instructions](docs/operations/deployment-and-backup.md). Never upload these private files to this repository.

If several employees need the same live records, deploy one central instance and let their devices access its HTTPS address. Separate local installations do not automatically synchronize. The supplied Compose configuration publishes only localhost ports; hosting needs the additional controls in the [deployment guide](docs/operations/deployment-and-backup.md).

## Troubleshooting

| Problem | What to check |
|---|---|
| Docker cannot connect | Open Docker Desktop and wait for its engine to start. |
| `node` is not recognized | Install Node.js, then open a new terminal. |
| Port already in use | Stop the other local service using 8080, 8025 or 54329, or adjust the host port in `compose.yaml`. |
| Login page is unavailable | Run `docker compose ps` and `docker compose logs --tail=80 app`. Confirm build and migration commands succeeded. |
| Database authentication fails | Keep the original `.env` password for an existing Docker database volume. Replacing the file does not reset the database password. |
| Public registration is unavailable | This is intentional. Create the first administrator with the command above, then create user accounts through administration. |

For host-based development without Docker, see [local setup](docs/operations/local-setup.md).
