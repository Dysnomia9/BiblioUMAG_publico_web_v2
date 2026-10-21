# Node 24 es la versión LTS activa (la 20 dejó de tener soporte en abril de 2026).
FROM node:24-alpine AS base

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm install

COPY . .

# Producción (docker-compose.prod.yml): compila la aplicación y nginx sirve solo
# los archivos generados. El código fuente, con sus comentarios, no se publica.
FROM base AS build
RUN npm run build

FROM nginx:1.30-alpine AS produccion
# Los arreglos de seguridad de Alpine que la imagen oficial todavía no trae.
RUN apk upgrade --no-cache
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY nginx-cabeceras.conf /etc/nginx/snippets/cabeceras.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80

# Desarrollo (docker-compose.yml): servidor de Vite con recarga en caliente. Va
# última para que sea la etapa que se construye cuando no se indica otra.
FROM base AS desarrollo

# Dentro del contenedor Vite tiene que escuchar en todas las interfaces para que el
# puerto publicado le llegue; a qué red se publica lo decide docker-compose.yml.
ENV VITE_HOST=0.0.0.0
EXPOSE 5173

# "npm audit" reporta vulnerabilidades conocidas del package-lock.json contra
# el advisory de npm. No bloquea el arranque (el ";" sigue a "npm run dev"
# aunque audit termine con código distinto de cero por hallazgos o sin red).
# Se escribe dentro de /app, que ya está mapeado al host (ver docker-compose.yml),
# así que el reporte queda en frontend/reports/npm-audit.log para revisar.
CMD ["sh", "-c", "mkdir -p reports && npm audit > reports/npm-audit.log 2>&1; npm run dev"]
