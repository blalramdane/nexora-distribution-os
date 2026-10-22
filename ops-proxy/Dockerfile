# syntax=docker/dockerfile:1
# TEMPORARY ops bridge for the one-time migration window; deleted from the
# account right after the data migration completes. Runs no app data services.
FROM node:22-alpine
WORKDIR /ops
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund --loglevel=error
COPY server.mjs ./
EXPOSE 8000
CMD ["node", "server.mjs"]
