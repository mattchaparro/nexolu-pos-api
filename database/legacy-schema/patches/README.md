# Schema patches

> **Congelado desde 2026-08-20.** Este sistema quedó reemplazado por
> migraciones reales de Laravel (ver "Database & migrations" en
> `CLAUDE.md` y `app/Console/Commands/SeedMigrationsBaseline.php`). Los
> archivos que ya están acá se quedan como historial - no los borres, y
> no crees uno nuevo: un cambio de esquema nuevo (tabla nueva o `ALTER`
> sobre una existente) va con `php artisan make:migration`.

`database/legacy-schema/schema.sql` es la fuente de verdad, pero solo se carga
completo **una vez** por ambiente (`mysql ... < schema.sql`, nunca
`php artisan migrate` — ver `CLAUDE.md`). Cuando una tabla 100% nueva se
agrega a `schema.sql` *después* de esa carga inicial (ej. `whatsapp_logs`,
`pos_payment_methods`), cualquier ambiente que ya estaba corriendo — tu local,
`testing`, el droplet de producción — se queda sin esa tabla hasta que alguien
la crea a mano.

`php artisan schema:apply-patches` resuelve eso: aplica los archivos `.sql` de
esta carpeta que aún no corrieron contra la conexión actual, y lleva su propio
registro (tabla `schema_patches`, se crea sola) para no repetirlos.

## Reglas de un patch

- **Solo tablas 100% nuevas** — nunca un `ALTER TABLE` sobre algo que el
  legacy ya usa. Esa sigue siendo la misma regla de `CLAUDE.md` para
  `schema.sql`; un patch no es una excepción, es la misma regla aplicada
  incrementalmente.
- `CREATE TABLE IF NOT EXISTS`, nunca `DROP TABLE`. Un patch jamás borra nada.
- Nombre: `YYYY_MM_DD_NNNNNN_descripcion.sql` (mismo estilo que las migraciones
  de Laravel, aunque esto no es una migración) — el orden de ejecución es
  alfabético por nombre de archivo.
- Un patch nuevo aquí **siempre acompaña** el mismo cambio ya reflejado en
  `schema.sql` (para que una carga inicial fresca y un ambiente parcheado
  terminen con el mismo esquema) — quedan duplicados a propósito, no hay forma
  de evitarlo sin una herramienta de migraciones real, que este proyecto
  decidió no usar para estas tablas.

## Cuándo correrlo

- **Deploy nuevo (droplet fresco):** después de cargar `schema.sql` una vez,
  corre `schema:apply-patches` igual — es idempotente y confirma que no falta
  nada, sin costo si ya está todo.
- **Cada deploy posterior:** parte del `deploy.sh`, siempre, no solo cuando
  sabes que agregaste una tabla — así nadie se olvida.
- **Tu local:** cada vez que hagas `git pull` y veas un archivo nuevo en esta
  carpeta.

```bash
php artisan schema:apply-patches            # aplica lo pendiente
php artisan schema:apply-patches --dry-run  # solo lista que falta, no toca nada
```
