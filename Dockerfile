FROM node:22-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY frontend/package.json frontend/package.json
COPY backend/package.json backend/package.json
RUN npm ci
COPY frontend/ frontend/
RUN npm run build

FROM node:22-bookworm-slim AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
COPY frontend/package.json frontend/package.json
COPY backend/package.json backend/package.json
RUN npm ci --omit=dev --workspace=backend && npm cache clean --force
COPY backend/src/ backend/src/
COPY --from=build /app/frontend/dist/ frontend/dist/
USER node
EXPOSE 3000
CMD ["node", "backend/src/server.js"]
