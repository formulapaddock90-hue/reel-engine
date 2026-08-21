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

# Reel visual tuning: keep source images brighter and reduce heavy black overlays.
# These substitutions are applied at image-build time so the rendering pipeline
# remains unchanged while improving exposure and color on the final MP4.
RUN sed -i 's/fps=25,setsar=1\[kb\${i}\]/eq=brightness=0.06:contrast=1.02:saturation=1.07:gamma=1.04,fps=25,setsar=1[kb${i}]/' server.js \
    && sed -i 's/color=black@0.72/color=black@0.58/g' server.js \
    && sed -i 's/color=black@0.88/color=black@0.70/g' server.js

# Ensure working directories
RUN mkdir -p music output temp public

ENV PORT=3000
EXPOSE 3000

CMD ["node", "server.js"]
