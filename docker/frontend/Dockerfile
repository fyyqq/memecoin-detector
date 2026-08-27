# React (Vite) frontend - local development image
FROM node:22-alpine

WORKDIR /app

# Install dependencies first for better layer caching
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install

# Source is bind-mounted in docker-compose for hot reload
COPY frontend/ ./

EXPOSE 5180

CMD ["npm", "run", "dev"]
