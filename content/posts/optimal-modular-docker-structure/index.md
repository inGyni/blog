---
title: Building an Optimal Modular Docker Container Setup
description: ""
author: "Gyni"
date: 2026-02-14T11:43:41.893Z
comments: false
draft: false
---

Managing multiple Docker services can get messy fast if everything is in one monolithic compose file. I've found a structure that's clean, modular, and easy to scale, and here's how you can set it up.

## Folder Layout

```bash
docker
├── containers
│   ├── authelia
│   │   └── docker-compose.yml
│   └── caddy
│       └── docker-compose.yml
└── data
    ├── authelia
    │   ├── config
    │   └── secrets
    └── caddy
        ├── conf
        ├── config
        ├── data
        └── site
```
- `containers/` – each service has its own folder with a dedicated `docker-compose.yml`.
- `data/` – all persistent data, configs, and secrets live outside containers.

## Step 1: Create a Shared Network

All containers need to talk to each other. Instead of creating separate networks in each compose, I use a shared external network:

```bash
$ docker network create shared
```

Then, in each `docker-compose.yml` at the bottom:

```yml
networks:
  default:
    external: true
    name: shared
```

This keeps networking consistent without locking you into a single massive YAML. And if you want different services to be isolated, you can create additional networks as needed.

## Step 2: Modular `docker-compose.yml`

Here is an example for **Authelia**:
```yml
# docker/containers/authelia/docker-compose.yml
services:
  authelia:
    container_name: authelia
    image: authelia/authelia:latest
    restart: unless-stopped
    environment:
      AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE: '/secrets/JWT_SECRET'
      AUTHELIA_SESSION_SECRET_FILE: '/secrets/SESSION_SECRET'
      AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE: '/secrets/STORAGE_ENCRYPTION_KEY'
    volumes:
      - '../../data/authelia/config:/config'
      - '../../data/authelia/secrets:/secrets'

networks:
  default:
    external: true
    name: shared
```
And **Caddy**:

```yml
# docker/containers/caddy/docker-compose.yml
services:
  caddy:
    container_name: caddy
    image: caddy:latest
    restart: unless-stopped
    volumes:
      - ../../data/caddy/conf:/etc/caddy
      - ../../data/caddy/site:/srv
      - ../../data/caddy/data:/data
      - ../../data/caddy/config:/config

networks:
  default:
    external: true
    name: shared
```

Each container is independent but can still communicate through the shared network.

## Step 3: Top-Level Orchestration

If you want to bring all containers up in one command:
```yml
# docker/docker-compose.yml

services:
  authelia:
    compose:
      - ./containers/authelia/docker-compose.yml

  caddy:
    compose:
      - ./containers/caddy/docker-compose.yml
```

Then you can just run:
```bash
$ docker-compose up -d
```

And all your services will start together while staying modular.

## Step 4: Optional Tweaks

- **Common Configs/Secrets**: If multiple containers need the same secrets, create a `common/` folder in `data/` to reduce duplication.
- **Volume Paths**: Relative paths are fine, but absolute paths can prevent breakage if you move the folder.

## Takeaway

This setup hits the sweet spot: clean, modular, and scalable. Each container is self-contained, persistent data is organized, and networking is simple. Whether you are running two containers or twenty, this structure keeps your stack maintainable.