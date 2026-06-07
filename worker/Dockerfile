FROM node:22-bookworm
WORKDIR /worker

RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium fonts-liberation ca-certificates \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
COPY package*.json ./
RUN npm install --omit=dev --ignore-scripts
COPY src ./src

RUN mkdir -p /data/sessions
EXPOSE 3001
CMD ["node", "src/server.js"]
