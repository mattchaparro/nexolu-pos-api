# Cómo probar la tienda online de una sola pasada

Guion de prueba manual de todo lo construido. Está escrito para hacerse **en
tablet u ordenador** (el editor necesita 1024px de ancho para las tres
columnas; en móvil se reacomoda pero no se ve la vista previa al lado).

Cada punto dice qué debería pasar. Si algo no coincide, eso es el bug.

---

## 0. Antes de empezar

Necesitas un negocio con:

- el flag `online_store` encendido (superadmin → negocio → configuración),
- al menos **3 productos publicados**, uno con variantes y uno agotado,
- al menos **2 categorías**, una colgando de la otra (subcategoría).

Sin subcategorías no se puede probar el árbol, que es donde apareció el
último bug.

---

## 1. El recorrido guiado (primera vez)

Entra a **Tienda online → Editor**.

1. Debe aparecer solo, sin pedirlo, con el paso 1 de 6 centrado.
2. Pasa los 6 pasos: el foco se mueve al riel, a la vista previa, a
   Plantillas (que además **abre esa sección**), al checklist y a Guardar.
3. Sal a mitad con **Saltar**, recarga la página → **no debe volver a
   aparecer**.
4. Toca el **?** de la barra superior → debe relanzarse.

> Se recuerda por negocio: si administras dos, en el segundo debe aparecer de
> nuevo.

## 2. El checklist

En la columna derecha, arriba: **Listo para abrir**.

1. Despliégalo. Debe mostrar porcentaje y qué falta.
2. Los pendientes que **bloquean** salen con triángulo ámbar; los opcionales,
   en gris.
3. Toca un pendiente → debe **llevarte a la sección** donde se arregla.
4. Arréglalo → el punto se tacha y el porcentaje sube.

## 3. El editor

1. **Colores**: la rueda pinta, el hexadecimal se puede escribir y pegar, y
   hay paletas sugeridas. La vista previa cambia al instante.
2. **Bloques → Franja de confianza**: el ícono es un **selector visual con
   buscador**, no un campo de texto.
3. **Duplicar** un bloque (⧉). En Portada debe estar deshabilitado: solo se
   permite una.
4. **Deshacer** (↶ o Ctrl+Z): borra un bloque y recupéralo. Deshacer hasta el
   principio debe dejar el botón apagado.
5. **Presentación**: en cualquier bloque, abajo, cambia *Ancho* y *Aire*. Es
   lo que más cambia el aspecto.
6. **Clic en la vista previa**: toca un bloque en el centro → debe abrirse en
   el riel. Toca un enlace dentro de un bloque → **no debe navegar**.
7. **Pantalla completa** y **plegar el formulario** (junto a los íconos de
   celular/computador).
8. **Página de la vista previa**: cambia entre *Inicio* y *Tienda*. Los
   colores del borrador deben verse en las dos.
9. **Datos / Envío / Google**: los tres nuevos. En Google, la vista previa
   del resultado de búsqueda debe reflejar lo que escribes.
10. **Salir con cambios sin guardar** → debe preguntar. Prueba las tres
    salidas: seguir editando, salir sin guardar, guardar y salir.

## 4. Bloques nuevos

Agrega y comprueba que se pintan: **Mosaico**, **Cinta en movimiento**,
**Categorías**, **Antes y después**, **Video**, **Franja de anuncio**.

- El **video** solo acepta YouTube o Vimeo. Pega otra URL → el bloque **no
  debe pintarse**. Con una válida, no debe cargar nada hasta que toques
  reproducir.
- El **antes/después** se arrastra con el dedo y con el teclado.

## 5. La tienda pública

Abre `tienda.nexolu.co/{tu-slug}`.

1. **Inicio**: los bloques en el orden que armaste.
2. **Menú → Tienda**: árbol de categorías **con las subcategorías visibles
   sin tener que seleccionar el padre**.
3. **Filtros**: ordenar por precio, *Solo disponibles* (debe sacar el
   agotado), rango de precio. Todo debe quedar en la URL y sobrevivir a
   recargar.
4. En escritorio, la columna de filtros **no se pierde al bajar**.
5. **Cards**: agregar desde la card sin entrar; un producto con variantes
   dice *Ver opciones* y **sí navega**; el agotado está deshabilitado. Con
   dos fotos, la segunda aparece al pasar el mouse.
6. **Ficha**: descripción, *Cómo se usa*, estrellas si hay reseñas, y
   **Va bien con** si configuraste cruzadas.
7. **Compra pegajosa**: baja en la ficha → aparece la barra con el precio.
8. **Carrito**: barra de progreso al mínimo, y **Completa tu pedido** con las
   sugerencias (no debe sugerir lo que ya llevas).

## 6. Comprar y atender

1. Haz un pedido de prueba completo.
2. En el POS → **Pedidos online** debe aparecer.
3. **Confirma** el pedido eligiendo medio de pago.
4. Verifica: se creó la venta, el **stock bajó exactamente lo pedido**, y el
   pedido quedó enlazado a la venta.
5. Marca el pedido como **entregado**.
6. Abre el enlace del pedido como comprador → ahora debe ofrecer **calificar**
   lo que compró.
7. Califica → en el POS, **Opiniones** debe mostrarla **pendiente**.
8. Publícala → debe aparecer en la ficha del producto en la tienda.

## 7. Ventas cruzadas en el mostrador

1. Catálogo → edita un producto → **Se vende bien con** → elige 2.
2. Ve a **Vender** y agrega ese producto.
3. Encima del cobro debe aparecer **Se vende bien con** con esos dos.
4. Toca uno → se agrega al carrito y **desaparece de la sugerencia**.

> Funciona aunque el negocio **no** tenga tienda online: es del mostrador.

## 8. Que nada se rompió

1. Con un negocio **sin** `online_store`: no debe verse el menú de tienda ni
   el de opiniones, y `tienda.nexolu.co/{slug}` debe dar "no disponible".
2. Vender normal en ese negocio: nada debe haber cambiado.

---

## Qué está cubierto por pruebas automáticas

No hace falta comprobar a mano (1420 pruebas, `php artisan test`):

- aislamiento entre negocios en todo lo público,
- que una reseña solo la escriba quien compró, y solo lo que compró,
- que el orden y el filtro por precio usen el precio **publicado** (el mínimo
  de las variantes), no `products.price`,
- que confirmar un pedido descuente stock **una vez** y cobre el total del
  pedido,
- que un negocio migrado del monolito **no** quede con la tienda abierta,
- que ninguna columna nueva de tabla compartida rompa la migración.
