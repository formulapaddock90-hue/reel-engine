# Official Python slim image
FROM python:3.11-slim

# Install FFmpeg, fonts, and system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    fonts-dejavu \
    fonts-liberation \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Install Python requirements
RUN pip install --no-cache-dir google-genai

# Copy application files
COPY . /app

# Create exports folder
RUN mkdir -p exports assets/audio

# Expose dynamic port
EXPOSE 5173

# Start Python Reel Server
CMD ["python", "server.py"]
