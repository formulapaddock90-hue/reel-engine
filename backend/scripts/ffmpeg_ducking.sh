#!/bin/bash
# ==============================================================================
# FFmpeg Sidechain Compression Audio Ducking Script
# Automatically attenuates background music when voiceover audio is active.
# ==============================================================================

VOICEOVER_INPUT=$1
MUSIC_INPUT=$2
OUTPUT_AUDIO=$3

if [ -z "$VOICEOVER_INPUT" ] || [ -z "$MUSIC_INPUT" ] || [ -z "$OUTPUT_AUDIO" ]; then
    echo "Usage: ./ffmpeg_ducking.sh <voiceover.wav> <music.mp3> <output.m4a>"
    exit 1
fi

echo "Applying FFmpeg sidechain compression filter..."

ffmpeg -y -i "$VOICEOVER_INPUT" -i "$MUSIC_INPUT" \
  -filter_complex "[1:a][0:a]sidechaincompress=threshold=0.03:ratio=10:attack=15:release=200[bg_ducked]; [0:a][bg_ducked]amix=inputs=2:duration=first" \
  -c:a aac -b:a 192k "$OUTPUT_AUDIO"

echo "Audio ducking completed successfully: $OUTPUT_AUDIO"
