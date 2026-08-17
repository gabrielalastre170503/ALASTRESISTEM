# AlastreSystem

Pipeline de prospección para vender webs a negocios locales. Descubre negocios,
audita su presencia web con datos objetivos, y prepara propuestas — pero **nunca
envía nada solo**: hay una puerta humana obligatoria antes de cualquier mensaje.

Corre sobre PHP de XAMPP. No usa base de datos: el estado vive en el sistema de
archivos, una carpeta por etapa.

## Estado actual

Implementadas las **fases 0 y 1**: descubrimiento, auditoría, máquina de estados
y panel de revisión. El Constructor (genera la landing), el Redactor (escribe el
borrador) y el Verificador (contrasta cada dato antes de aprobar) llegan después,
y **a propósito**: no tiene sentido automatizar la redacción antes de saber qué
hallazgo hace que la gente conteste.

## Requisitos

- XAMPP con PHP 8.1 o superior (usa `str_starts_with` y tipos estrictos)
- Extensiones `curl`, `json`, `mbstring` — vienen activas por defecto
- Una clave de Google Places API con facturación habilitada

## Puesta en marcha

```bash
cp .env.example .env
```

Rellena `GOOGLE_PLACES_API_KEY` en el `.env`. En la consola de Google Cloud:
habilita **Places API (New)**, crea una clave y **restríngela a esa API**.
Opcionalmente habilita también **PageSpeed Insights API** y pon la clave en
`GOOGLE_PSI_API_KEY` — es gratuita y añade la medición de rendimiento móvil.

## Flujo de trabajo

Hay dos formas de meter leads. **Para la Fase 0 no hace falta Places API:**

```bash
# A) A mano — buscas en Google Maps, copias 10 negocios a un CSV
#    Solo necesita la clave de PageSpeed, que es gratuita.
php bin/importar.php leads.csv "psicologia" "Valencia, Espana"

# B) Automático y gratis — OpenStreetMap, sin clave ni facturación
php bin/scout-osm.php psicologia "Valencia, España" --max=40
php bin/scout-osm.php --listar   # verticales disponibles

# C) Automático de pago — Google Places, mejor cobertura, requiere facturación
php bin/scout.php "psicologia" "Valencia, Espana" --max=20
```

Copia `leads-ejemplo.csv` para ver el formato. Solo el nombre es obligatorio;
dejar la web vacía es información, no un hueco: un negocio sin web es el mejor
lead que hay.

```bash
# 2. Auditarlos: hallazgos objetivos y citables
php bin/auditar.php --limite=10

# 3. Comprobar si de verdad no tienen web
php bin/verificar.php --etapa=20-auditado --limite=10

# 4. Ver dónde está todo
php bin/estado.php
```

### Por qué existe el paso 3

Que Google Maps no enlace una web **no prueba** que el negocio no la tenga.
En el primer lote real, 4 de cada 10 leads marcados como "sin web" sí la
tenían. Escribirle a alguien diciéndole que no tiene algo que sí tiene quema
el lead en la primera frase.

El verificador deduce dominios del nombre del negocio, los prueba, y para los
que responden comprueba que sean suyos buscando su teléfono o su calle dentro
de la página. Distingue dos niveles:

- **CONFIRMADO** — aparece su teléfono, su dirección o su nombre completo.
- **POSIBLE, revisar** — el dominio sale de su nombre y la página lo menciona,
  pero sin prueba fuerte. Hay que mirarlo a mano.

Los dominios de una sola palabra (`mercedes`, `piensa`) solo se aceptan con
prueba fuerte: con nombres comunes coinciden con marcas ajenas. Sin esa regla,
el verificador daba Mercedes-Benz como web de una psicóloga llamada Mercedes.

Y para la puerta humana, con Apache levantado:
<http://localhost/AlastreSystem/>

## El pipeline

Cada lead es un archivo JSON nombrado por su `place_id`. La etapa es la carpeta
donde vive, y **cambiar de etapa es mover el archivo**. De ahí salen tres cosas
gratis: no hay condiciones de carrera (un agente reclama el archivo moviéndolo a
`_trabajando/` antes de tocarlo), el estado se inspecciona con `ls`, y la puerta
humana es física — nada sale de `40-por-aprobar/` si tú no lo mueves.

```
pipeline/
├── 00-descubierto/   scout: place_id, nombre, web, teléfono, reseñas
├── 10-calificado/    pasó los filtros
├── 20-auditado/      hallazgos objetivos y verificables
├── 30-construido/    landing desplegada
├── 40-por-aprobar/   <-- LA PUERTA HUMANA
├── 50-enviado/
├── 60-respondido/
├── 70-reunion/
├── 90-cerrado/
├── 99-descartado/
├── _supresion/       no contactar nunca. Se consulta antes de cada envío
└── _trabajando/      reclamo temporal, evita doble procesamiento
```

## Estructura

```
AlastreSystem/
├── bin/
│   ├── scout-osm.php  descubrir vía OpenStreetMap (gratis)
│   ├── scout.php      descubrir vía Places API (de pago)
│   ├── importar.php   importar leads desde CSV
│   ├── auditar.php    PageSpeed + comprobaciones de la web
│   ├── verificar.php  ¿tiene web aunque Maps no la enlace?
│   └── estado.php     resumen del pipeline
├── lib/
│   ├── pipeline.php  estados, reclamo, escritura atómica
│   └── http.php      cliente HTTP sobre cURL
├── landings/         salida del Constructor (fase 2)
├── bootstrap.php     carga .env y librerías
└── index.php         panel de revisión
```

## El campo `citable`

Cada hallazgo del auditor lleva un campo `citable`, y es el eje del diseño:
**ese es el único texto que el Redactor tiene permitido usar en un mensaje**.
Si un dato no está ahí, no existe. Es lo que impide que un borrador generado
se invente cosas sobre el negocio de otra persona — lo que destruiría la
credibilidad en la primera frase y, en una comunicación comercial, sería
además una afirmación falsa.

## Datos y privacidad

Los JSON de `pipeline/` llevan datos de contacto de negocios reales obtenidos
de la Places API, que además limita cuánto tiempo pueden conservarse. **No se
versionan**: el repositorio lleva el esqueleto de carpetas, el contenido se
queda en local. El `.env` tampoco viaja; solo `.env.example`.

## Sobre el envío

Cuando llegue la fase de envío, va por un buzón propio de Google Workspace en un
dominio aparte del principal, con SPF, DKIM y DMARC. **No con SendGrid ni
Mailgun**: su política de uso aceptable prohíbe el correo no solicitado y
cierran la cuenta. Esos servicios sí sirven, pero para el correo transaccional
— confirmar reunión, entregar la landing, mandar la factura.

## Convenciones

- Código y comentarios en español, igual que el resto de proyectos.
- Toda salida a HTML pasa por `h()` para evitar XSS.
- Los identificadores que llegan por HTTP se validan contra una lista blanca
  antes de tocar una ruta de archivo (`id_valido()` en `index.php`).
