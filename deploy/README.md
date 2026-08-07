# Despliegue: un droplet, Docker Compose, todo en MySQL

Orquesta los 4 servicios del ecosistema Nexolu (`nexolu-pos-api`,
`nexolu-ia-core`, `nexolu-comms-api`, `nexolu-payments-core`) en un solo
droplet de DigitalOcean. Un motor de base de datos (MySQL), un reverse
proxy (Caddy, TLS automático), Docker Compose en vez de Kubernetes -
tamaño correcto para un SaaS que recién empieza.

## 0. Antes de empezar

Este directorio (`deploy/`) vive dentro del repo `nexolu-pos-api` porque no
se pudo crear un repo de infraestructura aparte (permiso denegado por la
GitHub App instalada). Si más adelante prefieres tenerlo en su propio repo,
es mover esta carpeta sin cambios - no tiene ningún acoplamiento real al
código de `nexolu-pos-api`, solo referencias relativas a las carpetas
hermanas.

## 1. Provisionar el droplet

- Ubuntu 24.04 LTS, 2 vCPU / 4 GB RAM como piso razonable para los 4
  servicios + MySQL + Redis en este tamaño de operación (subir si el uso
  real lo pide - no hay nada en este setup que dependa del tamaño).
- Instalar Docker Engine + Docker Compose plugin:
  ```bash
  curl -fsSL https://get.docker.com | sh
  ```
- Apuntar los 3 dominios (`pos.nexolu.co`, `comms.nexolu.co`,
  `payments.nexolu.co`) a la IP del droplet (registro A). Caddy pide el
  certificado TLS solo cuando el dominio ya resuelve hacia él.

## 2. Clonar los 4 repos como hermanos

```bash
mkdir -p /opt/nexolu && cd /opt/nexolu
git clone https://github.com/mattchaparro/nexolu-pos-api.git
git clone https://github.com/mattchaparro/nexolu-ia-core.git
git clone https://github.com/mattchaparro/nexolu-comms-api.git
git clone https://github.com/mattchaparro/nexolu-payments-core.git
```

`docker-compose.yml` asume exactamente esta estructura (rutas relativas
`../../nexolu-ia-core`, etc. desde `nexolu-pos-api/deploy/`).

## 3. Configurar cada `.env`

Cada servicio sigue usando su **propio** `.env` real, copiado de su propio
`.env.example`, exactamente como en desarrollo local - `docker-compose.yml`
solo los referencia (`env_file:`), no los reemplaza.

```bash
cd /opt/nexolu/nexolu-pos-api && cp .env.example .env
cd /opt/nexolu/nexolu-ia-core && cp .env.example .env
cd /opt/nexolu/nexolu-comms-api && cp .env.example .env
cd /opt/nexolu/nexolu-payments-core && cp .env.example .env
```

Completa cada uno con las credenciales reales. Puntos clave para el
contexto de Docker (los nombres de host son los nombres de servicio del
`docker-compose.yml`, no `127.0.0.1`):

**`nexolu-pos-api/.env`**
```
DB_HOST=mysql
DB_DATABASE=pos_saas
DB_USERNAME=nexolu
DB_PASSWORD=<el mismo MYSQL_APP_PASSWORD de deploy/.env>
REDIS_HOST=redis
IA_CORE_BASE_URL=http://ia-core:8000
COMMS_CORE_BASE_URL=http://comms-api:8000
PAYMENTS_CORE_BASE_URL=http://payments-core:8000
MESSAGING_DRIVER=whatsapp_direct   # cambiar a nexolu_comms cuando se decida el cutover
```

**`nexolu-ia-core/.env`, `nexolu-comms-api/.env`, `nexolu-payments-core/.env`**
```
DATABASE_URL=mysql+aiomysql://nexolu:<MYSQL_APP_PASSWORD>@mysql:3306/<su_base>
```
(`nexolu_ia_core`, `nexolu_comms`, `nexolu_payments_core` respectivamente -
ver `deploy/mysql/init.sh`).

Y `deploy/.env` (infraestructura pura):
```bash
cd /opt/nexolu/nexolu-pos-api/deploy && cp .env.example .env
```
Completa `MYSQL_ROOT_PASSWORD`, `MYSQL_APP_PASSWORD` y los 3 dominios.

## 4. Levantar todo

```bash
cd /opt/nexolu/nexolu-pos-api/deploy
docker compose up -d --build
```

Esto construye las 4 imágenes (una vez cada una, `pos-web`/`pos-queue`/
`pos-scheduler` comparten la misma) y arranca MySQL, Redis, Caddy y los 4
servicios.

## 5. Cargar el esquema de `pos-api` (una sola vez)

**Nunca se corre `php artisan migrate` en `pos-api`** - el esquema (85
tablas) viene completo de `database/legacy-schema/schema.sql`. Cárgalo a
mano contra el contenedor de MySQL:

```bash
docker compose exec -T mysql mysql -unexolu -p<MYSQL_APP_PASSWORD> pos_saas \
  < /opt/nexolu/nexolu-pos-api/database/legacy-schema/schema.sql
```

## 6. Migrar el esquema de los 3 servicios Python (una sola vez, y en cada deploy que agregue una migración)

Estos sí usan Alembic normalmente - a diferencia de `pos-api`, sus tablas
son nuevas y propias, no un dump heredado:

```bash
docker compose run --rm ia-core alembic upgrade head
docker compose run --rm comms-api alembic upgrade head
docker compose run --rm payments-core alembic upgrade head
```

## 7. Verificar

```bash
docker compose ps
curl -s https://pos.nexolu.co/up
curl -s https://comms.nexolu.co/health
curl -s https://payments.nexolu.co/health
docker compose logs -f pos-queue   # confirmar que el worker esta corriendo, no queue:listen
```

## Deploys posteriores

```bash
cd /opt/nexolu/nexolu-pos-api && git pull
cd /opt/nexolu/nexolu-ia-core && git pull
cd /opt/nexolu/nexolu-comms-api && git pull
cd /opt/nexolu/nexolu-payments-core && git pull

cd /opt/nexolu/nexolu-pos-api/deploy
docker compose up -d --build
# Si algun servicio Python trajo una migracion nueva:
docker compose run --rm ia-core alembic upgrade head
```

`docker compose up -d --build` reinicia cada contenedor cuya imagen cambió
- unos segundos de downtime por contenedor, aceptable a este tamaño de
operación (no es el reload sin downtime que da Laravel Cloud, ver la
conversación sobre esa alternativa).

## Respaldo

Punto único de falla real de este setup: si el droplet se cae, se pierde
la app Y los datos a la vez si no hay respaldo fuera del droplet.

```bash
# Cron diario en el HOST (no en un contenedor), subiendo a DigitalOcean
# Spaces o similar - fuera de alcance de este README, pero no lo saltes.
docker compose exec -T mysql mysqldump -uroot -p<MYSQL_ROOT_PASSWORD> \
  --all-databases > backup-$(date +%F).sql
```

## Qué falta / decisiones pendientes

- **Backups automáticos fuera del droplet** - el comando de arriba es
  manual, falta el cron + subida a almacenamiento externo.
- **Base de datos por servicio con su propio usuario** (en vez de un solo
  `nexolu` compartido) - déjalo para cuando el aislamiento importe más que
  la simplicidad de hoy.
- **Exponer `comms-api` públicamente solo si de verdad se activa el
  cutover** (`MESSAGING_DRIVER=nexolu_comms` + webhook de Meta apuntando
  ahí) - hasta entonces el bloque de Caddy no hace daño pero tampoco sirve
  de nada.
