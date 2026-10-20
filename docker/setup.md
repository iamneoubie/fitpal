docker compose -f docker/docker-compose.yaml down --remove-orphans && rm -rf docker/mysql_data && docker compose -f docker/docker-compose.yaml up -d --build

# FitPal - Docker Setup Guide

This guide walks a student through installing Docker and running FitPal on a clean machine. Follow the sections in order.

---

## 1. Install Docker Desktop

1. Download from https://www.docker.com/products/docker-desktop/ for your operating system.
2. Run the installer with the default options.
3. Restart the computer when prompted.
4. Open **Docker Desktop** and wait until it reports it is running.

Verify in a terminal:

```bash
docker --version
docker compose version
```

Both must print a version. If either is missing, reinstall Docker Desktop.

### 1.1 Windows prerequisites

1. Press `Win + R`, type `optionalfeatures.exe`, press Enter.
2. Check both:
   - [x] Virtual Machine Platform
   - [x] Windows Subsystem for Linux
3. Click **OK** and restart.

Hyper-V is not required.

If Docker reports **"virtualization not enabled"**, open PowerShell as Administrator and run:

```powershell
powershell.exe -NoProfile -Command "Get-ComputerInfo -Property 'HyperV*'; bcdedit /enum '{current}'; bcdedit /set hypervisorlaunchtype auto"
```

Restart the computer, then reopen Docker Desktop.

### 1.2 Linux

Install Docker Engine and the Compose plugin from your distribution's package manager, then add your user to the `docker` group:

```bash
sudo usermod -aG docker $USER
```

Log out and log back in for the change to apply.

---

## 2. Start the Project

Run every command from inside the `fitpal/` project root.

### 2.1 First start

```bash
docker compose -f docker/docker-compose.yaml up -d --build
```

This builds the web image, pulls the database and phpMyAdmin images the first time, starts all containers, and runs `sql/database.sql` plus `sql/seed/all-in-one.sql` when the database is empty.

### 2.2 Confirm everything is running

```bash
docker compose -f docker/docker-compose.yaml ps
```

All three services must show **running**.

### 2.3 Confirm the database finished initializing

```bash
docker compose -f docker/docker-compose.yaml logs -f db
```

Wait for **`ready for connections`**, then press `Ctrl + C`.

### 2.4 Open the application

| Service    | URL                   |
| ---------- | --------------------- |
| FitPal     | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

Seeded demo accounts:

| Role       | Username          | Password   |
| ---------- | ----------------- | ---------- |
| Customer   | `user`            | `user123`  |
| Admin      | `admin`           | `admin123` |
| Rider      | `rider_carlos`    | `rider123` |
| Restaurant | `greenbowl_owner` | `owner123` |

---

## 3. Day-to-Day Commands

| Action                       | Command                                                                                                                                |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Stop, keep data              | `docker compose -f docker/docker-compose.yaml stop`                                                                                    |
| Start again                  | `docker compose -f docker/docker-compose.yaml start`                                                                                   |
| Remove containers, keep data | `docker compose -f docker/docker-compose.yaml down`                                                                                    |
| Follow logs                  | `docker compose -f docker/docker-compose.yaml logs -f`                                                                                 |
| Rebuild, keep data           | `docker compose -f docker/docker-compose.yaml down --remove-orphans` then `docker compose -f docker/docker-compose.yaml up -d --build` |

---

## 4. Full Reset (Deletes All Local Database Data)

Use this when the seed data is corrupted, the schema changed, or you want a clean slate.

**WARNING: every row you created and every seeded row is deleted. This cannot be undone.**

```bash
docker compose -f docker/docker-compose.yaml down --remove-orphans
rm -rf docker/mysql_data
docker compose -f docker/docker-compose.yaml up -d --build
```

On Windows PowerShell, replace the second line with:

```powershell
Remove-Item -Recurse -Force docker\mysql_data
```

Confirm the seed ran again:

```bash
docker compose -f docker/docker-compose.yaml logs -f db
```

Wait for **`ready for connections`**, then press `Ctrl + C`.

---

## 5. Troubleshooting

| Symptom                                       | Fix                                                                                                                                |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `docker: command not found`                   | Docker Desktop is not installed or not on the PATH. Reinstall and restart the terminal.                                            |
| `Cannot connect to the Docker daemon`         | Docker Desktop is not running. Open it and wait for the whale icon to settle.                                                      |
| `Ports are not available: 8080 / 3306 / 8081` | Another program is using the port. Stop it, or edit the host port on the left of `docker/docker-compose.yaml` and use the new URL. |
| Database is empty after startup               | Initialization scripts run only when `docker/mysql_data/` is empty. Run Section 4.                                                 |
| phpMyAdmin cannot log in                      | Confirm the `db` container is running, then check its logs.                                                                        |
| PHP edits not showing                         | Restart the web container: `docker compose -f docker/docker-compose.yaml restart web`.                                             |
| A container restarts in a loop                | Read its logs: `docker compose -f docker/docker-compose.yaml logs <service>`.                                                      |

---

## 6. Complete Cleanup

Removes this project's containers, local database, and built images.

**WARNING: the local database is permanently deleted.**

```bash
docker compose -f docker/docker-compose.yaml down --remove-orphans --rmi local
rm -rf docker/mysql_data
```

Images pulled from Docker Hub are kept.

---

## 7. Rules to Remember

- Run every command from inside `fitpal/`.
- Initialization scripts run only when `docker/mysql_data/` is empty.
- Do not delete `docker/mysql_data/` unless you intend to lose the data.
- The empty MySQL root password is for isolated local development only.
- If a container fails, read its logs before changing anything.
