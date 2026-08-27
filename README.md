# Memecoin Detector

A lightweight Memecoin Intelligence platform for detecting trending memecoins,
related-token movements, pump catalysts, and historical leaders.

This repository currently contains **only the local development foundation** —
a Dockerized Laravel API, a React frontend, and PostgreSQL wired together.
Feature work (Dexscreener integration, detection, ranking, AI analysis, etc.)
lands in later sprints.

## Tech stack

| Layer         | Choice                          |
| ------------- | ------------------------------- |
| Backend       | Laravel 12 (PHP 8.4), API-only  |
| Frontend      | React 19 + TypeScript + Vite    |
| Database      | PostgreSQL 16                   |
| Runtime       | Docker + Docker Compose         |
| Market data   | Dexscreener API *(later sprint)*|
| DB GUI        | DBeaver                         |

## Folder structure

```
memecoin-detector/
├── backend/            Laravel API application
├── frontend/           React (Vite) application
├── docker/
│   ├── backend/        Backend image (Dockerfile + entrypoint)
│   └── frontend/       Frontend image (Dockerfile)
├── docs/
├── docker-compose.yml  Local dev orchestration
├── .env.example        Compose environment template
└── README.md
```

## Prerequisites

- Docker + Docker Compose
- Ports `8010`, `5180`, and `5433` free on the host

## Start the project

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

docker compose up -d --build
```

First boot installs Composer + npm dependencies and runs migrations, so give
the backend a minute. Watch progress with `docker compose logs -f backend`.

Hot reload is enabled for both apps: edit files in `backend/` or `frontend/`
and changes are picked up automatically.

## Stop the project

```bash
docker compose down          # stop containers, keep data
docker compose down -v       # also delete the PostgreSQL volume
```

## URLs

| Service            | URL                                  |
| ------------------ | ------------------------------------ |
| Backend (Laravel)  | http://localhost:8010                |
| Health endpoint    | http://localhost:8010/api/health     |
| Frontend (React)   | http://localhost:5180                |

## PostgreSQL connection (DBeaver)

| Field    | Value       |
| -------- | ----------- |
| Host     | `localhost` |
| Port     | `5433`      |
| Database | `memecoin`  |
| User     | `memecoin`  |
| Password | `secret`    |

These are the local defaults from `.env.example`. Override them in `.env`
(`POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_PORT`) before
the first `docker compose up`. Inside the Docker network the database host is
`postgres:5432`.

## Health endpoint

```bash
curl http://localhost:8010/api/health
# {"status":"ok"}
```

Used only to confirm the frontend/backend/database wiring is working.
