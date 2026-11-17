# Despliegue en Hostinger KVM con Docker

Supuestos: VPS nuevo con Ubuntu 24.04 y Docker, dominio pjservices-srl.com.
Si el VPS ya tiene servicios, revisar sus puertos y configuración antes de seguir.
No reinstalar un VPS que contenga datos. Los comandos Linux se ejecutan como root.

## 1. Preparar Hostinger

En hPanel > VPS > Administrar, escoger la plantilla Ubuntu con Docker durante
la configuración inicial. Guardar la IP y configurar la contraseña o clave SSH.
En el firewall de Hostinger permitir TCP 22 (SSH), 80 (HTTP), 443 (HTTPS).
No abrir 3000 ni 3306. Si SSH usa otro puerto, permitir ese puerto.

Desde PowerShell en Windows (sustituir IP_DEL_VPS):

```powershell
ssh root@IP_DEL_VPS
```

Comprobar la huella SSH con la mostrada por el servidor/hPanel antes de aceptarla.
Al escribir la contraseña no aparecen caracteres: es normal.

Dentro del VPS:

```bash
cat /etc/os-release
docker --version
docker compose version
apt update && apt upgrade -y
mkdir -p /opt/projects/pjservices /opt/proxy
```

Si Docker o Compose faltan, seguir la instalación oficial para Ubuntu:
https://docs.docker.com/engine/install/ubuntu/
No continuar hasta que ambos comandos de versión funcionen.
Si aparece /var/run/reboot-required, reiniciar con reboot y volver a conectar.

## 2. Subir los archivos

Abrir OTRA ventana PowerShell en Windows:

```powershell
Set-Location 'D:\pagina pj'
tar.exe -czf pjservices-docker.tar.gz Dockerfile .dockerignore compose.yaml .env.example package.json package-lock.json backend/package.json backend/src frontend/package.json frontend/index.html frontend/vite.config.js frontend/src frontend/public/images deploy DEPLOY_HOSTINGER.md
scp .\pjservices-docker.tar.gz root@IP_DEL_VPS:/opt/projects/pjservices/
```

El paquete omite contraseñas locales, node_modules y la implementación PHP anterior.
En el VPS:

```bash
cd /opt/projects/pjservices
tar -xzf pjservices-docker.tar.gz
cp -r deploy/proxy/. /opt/proxy/
cp .env.example .env
chmod 600 .env
openssl rand -hex 32
openssl rand -hex 32
nano .env
```

Colocar la primera clave en DB_PASSWORD y la segunda en MYSQL_ROOT_PASSWORD.
Mantener DB_USER=pjservices. Completar SMTP con los datos reales del proveedor del
correo existente; SMTP_HOST del ejemplo no está verificado. Puerto 465 usa TLS
directo; 587 usa STARTTLS. Para contraseñas con $ o # usar comillas simples.
FRONTEND_URL debe coincidir con la URL pública, sin barra final.
Guardar en nano con Ctrl+O, Enter; salir con Ctrl+X.

## 3. Dominio y correo

En el proveedor que administra los DNS, establecer A de @ y www a la IP del VPS.
Si www ya es CNAME al dominio principal se puede conservar. Corregir o quitar
AAAA de @/www que apunten a otro servidor si no se va a configurar IPv6.
No cambiar los nameservers, MX, SPF, DKIM, DMARC ni el host de correo.
Si el MX o mail apuntan indirectamente al dominio principal, resolver primero
con el proveedor cómo conservar el destino actual del correo antes de cambiar @.
El VPS no configura buzones: el formulario usa el proveedor SMTP existente.

En PowerShell verificar:

```powershell
Resolve-DnsName pjservices-srl.com -Type A
Resolve-DnsName www.pjservices-srl.com -Type A
```

Ambos deben resolver a la IP del VPS. Si el dominio es otro, editar .env y
/opt/proxy/conf/Caddyfile para usarlo. Si hay Cloudflare, comenzar en modo
Solo DNS para simplificar la validación inicial de HTTPS.

## 4. Arrancar

En el VPS:

```bash
docker network inspect proxy >/dev/null 2>&1 || docker network create proxy
cd /opt/projects/pjservices
docker compose config --quiet
docker compose up -d --build
docker compose ps
docker compose logs --tail=80 app db
cd /opt/proxy
docker compose up -d
docker compose logs --tail=80 caddy
```

Esperar a que app y db estén healthy. Caddy obtiene y renueva el certificado
cuando el DNS y los puertos 80/443 son accesibles desde Internet.

```bash
curl -fsS https://pjservices-srl.com/api/health
curl -fsS https://pjservices-srl.com/api/database
```

Ambas rutas deben devolver status ok. Abrir https://pjservices-srl.com y enviar
una cotización de prueba propia; comprobar recepción en info@pjservices-srl.com.
Health no verifica el envío de correo. Si falla, revisar logs de app y SMTP.

MySQL crea una base vacía. El código actual no guarda cotizaciones en tablas;
las envía por correo. Si tienes datos previos, exportarlos desde Workbench e
importarlos; no se transfieren automáticamente. No ejecutar down -v: borra datos.
Las claves MySQL de .env se aplican al inicializar un volumen vacío; cambiar
.env después no cambia las claves de usuarios existentes en MySQL.

## 5. Actualizar solo esta aplicación

Volver a generar y subir el paquete desde Windows. Después, en el VPS:

```bash
cd /opt/projects/pjservices
tar -xzf pjservices-docker.tar.gz
docker compose up -d --build app
docker compose ps
```

No volver a copiar .env.example encima de .env ni sobrescribir /opt/proxy.
Esta actualización puede interrumpir unos segundos la aplicación.

## 6. Copias de la base

En el VPS, antes de cambios importantes y periódicamente:

```bash
cd /opt/projects/pjservices
mkdir -p /opt/backups/pjservices
chmod 700 /opt/backups/pjservices
umask 077
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > /opt/backups/pjservices/database-$(date +%F-%H%M%S).sql
```

Comprobar que el comando termina sin errores y el archivo contiene un volcado SQL.
Descargar una copia fuera del VPS usando scp y guardar también .env de forma
segura. Un volumen persistente no es un respaldo. Probar restauraciones en una
base separada antes de confiar en los respaldos.

## 7. Otros proyectos

Crear /opt/projects/otroproyecto con su propio Compose, nombre de proyecto,
imagen, variables, credenciales y volúmenes. Su servicio web se conecta a la red
externa proxy con un alias único, por ejemplo otroproyecto-app, sin publicar
puertos del host. Su base solo se conecta a la red privada de ese proyecto.
Añadir al Caddyfile existente (sin borrar los bloques de PJ Services):

```caddyfile
otrodominio.com {
    reverse_proxy otroproyecto-app:3000
}
```

Usar el puerto interno real de esa nueva aplicación y configurar su DNS.
Validar y recargar el proxy compartido:

```bash
cd /opt/proxy
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
```

Cada proyecto mantiene sus dependencias. CPU, RAM y disco siguen compartidos;
vigilar docker stats y df -h y definir límites según las necesidades reales.
No instalar un segundo proxy que intente ocupar 80/443.
