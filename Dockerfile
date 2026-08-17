FROM node:20-slim

# Install FFmpeg and font packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    fonts-dejavu \
    fonts-liberation \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy package descriptors and install dependencies
COPY package*.json ./
RUN npm install --production

# Copy application files, assets, music and fonts
COPY . .

# Ensure working directories
RUN mkdir -p music output temp public

ENV PORT=3000
EXPOSE 3000

CMD ["node", "server.js"]
