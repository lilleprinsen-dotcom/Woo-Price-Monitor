FROM node:22-bookworm

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev \
	&& npx playwright install --with-deps chromium

COPY src ./src

ENV NODE_ENV=production
ENV PORT=8787

EXPOSE 8787

CMD ["node", "src/server.js"]
