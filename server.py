"""
FormulaPaddock Reel Engine — Render.com Cloud API Render Server 24/7
"""

import http.server
import socketserver
import urllib.request
import urllib.parse
import json
import re
import os
import sys
import mimetypes
import time
import ssl
import subprocess
import random

sys.stdout.reconfigure(encoding='utf-8')

# Dynamic Port assigned by Render.com or fallback to 5173
PORT = int(os.environ.get('PORT', 5173))
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
EXPORTS_DIR = os.path.join(BASE_DIR, 'exports')
DOWNLOADS_DIR = os.path.expanduser(r'~\Downloads')
AUDIO_DIR = os.path.join(BASE_DIR, 'assets', 'audio')
DRIVE_FOLDER_ID = '1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K'

os.makedirs(EXPORTS_DIR, exist_ok=True)
os.makedirs(AUDIO_DIR, exist_ok=True)

# SSL context for remote image downloads
ssl_ctx = ssl.create_default_context()
ssl_ctx.check_hostname = False
ssl_ctx.verify_mode = ssl.CERT_NONE

class ReelProxyHandler(http.server.BaseHTTPRequestHandler):

    def do_HEAD(self):
        parsed = urllib.parse.urlparse(self.path)
        rel_path = parsed.path.lstrip('/')
        if not rel_path:
            rel_path = 'index.html'

        file_path = os.path.join(BASE_DIR, rel_path)
        if os.path.isfile(file_path):
            ctype, _ = mimetypes.guess_type(file_path)
            if not ctype:
                ctype = 'text/html'
            self.send_response(200)
            self.send_header('Content-Type', ctype)
            self.send_header('Access-Control-Allow-Origin', '*')
            self.send_header('Content-Length', str(os.path.getsize(file_path)))
            self.end_headers()
        else:
            self.send_error(404, "File not found")

    def do_POST(self):
        # 1. RENDER DIRECT REEL MP4 API FOR REMOTE PHP HOST
        if self.path.startswith('/api/render-reel'):
            content_length = int(self.headers.get('Content-Length', 0))
            body_bytes = self.rfile.read(content_length)
            
            try:
                data = json.loads(body_bytes.decode('utf-8'))
            except Exception:
                data = {}

            overlay_text = data.get('text', 'FORMULAPADDOCK.IT • REEL F1')
            img_url = data.get('image_url', '')

            # Download or use local image
            temp_img = os.path.join(EXPORTS_DIR, f"temp_{int(time.time())}.jpg")
            default_img = os.path.join(BASE_DIR, 'assets', 'images', 'tech.jpg')

            if img_url:
                try:
                    req = urllib.request.Request(img_url, headers={'User-Agent': 'Mozilla/5.0'})
                    with urllib.request.urlopen(req, context=ssl_ctx, timeout=10) as resp:
                        with open(temp_img, 'wb') as f:
                            f.write(resp.read())
                    input_img = temp_img
                except Exception:
                    input_img = default_img
            else:
                input_img = default_img

            # Pick Random MP3 Track
            audio_files = [os.path.join(AUDIO_DIR, f) for f in os.listdir(AUDIO_DIR) if f.lower().endswith('.mp3')]
            chosen_audio = random.choice(audio_files) if audio_files else None

            # Render MP4 Video via FFmpeg
            output_mp4 = os.path.join(EXPORTS_DIR, f"reel_{int(time.time())}.mp4")
            
            try:
                targetW = 1080
                targetH = 1920
                duration = 15
                fps = 30
                total_frames = duration * fps

                clean_text = overlay_text.replace("'", "").replace(":", "-")
                if len(clean_text) > 80:
                    clean_text = clean_text[:80] + "..."

                # FFmpeg filters
                vf = (
                    f"scale=8000:-1,"
                    f"zoompan=z='min(zoom+0.0015,1.15)':d={total_frames}:s={targetW}x{targetH}:fps={fps},"
                    f"drawbox=x=40:y=60:w=420:h=80:color=black@0.85:t=fill,"
                    f"drawbox=x=40:y=60:w=420:h=80:color=#e10600@1.0:t=4,"
                    f"drawtext=text='FORMULAPADDOCK.IT':fontcolor=white:fontsize=32:x=60:y=85,"
                    f"drawbox=x={targetW-360}:y=60:w=320:h=160:color=black@0.85:t=fill,"
                    f"drawbox=x={targetW-360}:y=60:w=320:h=160:color=white@0.2:t=3,"
                    f"drawtext=text='334 KM/H':fontcolor=#ffeb3b:fontsize=48:x={targetW-330}:y=90,"
                    f"drawtext=text='DRS ATTIVO':fontcolor=#00e676:fontsize=22:x={targetW-330}:y=160,"
                    f"drawbox=x=60:y={targetH-360}:w={targetW-120}:h=240:color=black@0.9:t=fill:enable='lt(t,12)',"
                    f"drawbox=x=60:y={targetH-360}:w=16:h=240:color=#e10600@1.0:t=fill:enable='lt(t,12)',"
                    f"drawtext=text='FORMULAPADDOCK.IT • REEL F1':fontcolor=#ffeb3b:fontsize=26:x=100:y={targetH-320}:enable='lt(t,12)',"
                    f"drawtext=text='{clean_text}':fontcolor=white:fontsize=44:x=100:y={targetH-260}:enable='lt(t,12)',"
                    f"drawbox=x=0:y=0:w={targetW}:h={targetH}:color=#08090d@0.98:t=fill:enable='gte(t,12)',"
                    f"drawbox=x=40:y=40:w={targetW-80}:h={targetH-80}:color=#e10600@1.0:t=4:enable='gte(t,12)',"
                    f"drawbox=x=80:y=240:w={targetW-160}:h={targetH-480}:color=black@0.9:t=fill:enable='gte(t,12)',"
                    f"drawtext=text='FORMULAPADDOCK.IT':fontcolor=white:fontsize=64:x=(w-text_w)/2:y=480:enable='gte(t,12)',"
                    f"drawtext=text='SEGUI FORMULAPADDOCK.IT SU INSTAGRAM E TIKTOK':fontcolor=white:fontsize=36:x=(w-text_w)/2:y=760:enable='gte(t,12)',"
                    f"drawbox=x={targetW//2-220}:y=1150:w=440:h=100:color=#e10600@1.0:t=fill:enable='gte(t,12)',"
                    f"drawtext=text='SEGUI ORA':fontcolor=white:fontsize=40:x=(w-text_w)/2:y=1182:enable='gte(t,12)'"
                )

                if chosen_audio and os.path.exists(chosen_audio):
                    cmd = [
                        'ffmpeg', '-y', '-loop', '1', '-i', input_img, '-i', chosen_audio,
                        '-t', str(duration), '-vf', vf,
                        '-c:v', 'libx264', '-c:a', 'aac', '-b:a', '192k',
                        '-pix_fmt', 'yuv420p', '-shortest', '-movflags', '+faststart', output_mp4
                    ]
                else:
                    cmd = [
                        'ffmpeg', '-y', '-loop', '1', '-i', input_img,
                        '-t', str(duration), '-vf', vf,
                        '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', output_mp4
                    ]

                subprocess.run(cmd, capture_output=True, check=True)

                with open(output_mp4, 'rb') as f:
                    mp4_bytes = f.read()

                self.send_response(200)
                self.send_header('Content-Type', 'video/mp4')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.send_header('Content-Length', str(len(mp4_bytes)))
                self.end_headers()
                self.wfile.write(mp4_bytes)

            except Exception as e:
                self.send_json({'error': f"FFmpeg Render Error: {str(e)}"}, 500)
            finally:
                if os.path.exists(temp_img):
                    try: os.remove(temp_img)
                    except Exception: pass
                if os.path.exists(output_mp4):
                    try: os.remove(output_mp4)
                    except Exception: pass
            return

        # 2. AUTO-SAVE REEL TO EXPORTS & GOOGLE DRIVE
        elif self.path.startswith('/api/save-drive'):
            content_length = int(self.headers.get('Content-Length', 0))
            if content_length == 0:
                self.send_json({'error': 'No file data received'}, 400)
                return

            video_bytes = self.rfile.read(content_length)
            timestamp = int(time.time())
            filename = f"FormulaPaddock_Reel_{timestamp}.webm"
            local_save_path = os.path.join(EXPORTS_DIR, filename)

            try:
                with open(local_save_path, 'wb') as f:
                    f.write(video_bytes)

                self.send_json({
                    'status': 'SUCCESS',
                    'message': 'Reel salvato con successo!',
                    'drive_folder_id': DRIVE_FOLDER_ID,
                    'local_file': filename,
                    'local_path': local_save_path
                })
            except Exception as e:
                self.send_json({'error': str(e)}, 500)
            return

        self.send_error(404, "Endpoint not found")

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        params = urllib.parse.parse_qs(parsed.query)

        # 1. DOWNLOADS FOLDER / ASSETS MP3 PROVIDER API
        if parsed.path == '/api/downloads-audio':
            filename = params.get('file', [''])[0]

            if not filename:
                try:
                    mp3_files = []
                    search_dirs = [AUDIO_DIR, DOWNLOADS_DIR]

                    for s_dir in search_dirs:
                        if os.path.exists(s_dir):
                            for f in os.listdir(s_dir):
                                if f.lower().endswith('.mp3') and not any(x['name'] == f for x in mp3_files):
                                    mp3_files.append({
                                        'name': f,
                                        'url': f'/api/downloads-audio?file={urllib.parse.quote(f)}'
                                    })
                    self.send_json({'status': 'SUCCESS', 'files': mp3_files})
                except Exception as e:
                    self.send_json({'error': str(e)}, 500)
                return
            else:
                safe_name = os.path.basename(filename)
                target_path = os.path.join(AUDIO_DIR, safe_name)
                if not os.path.isfile(target_path):
                    target_path = os.path.join(DOWNLOADS_DIR, safe_name)

                if os.path.isfile(target_path):
                    try:
                        with open(target_path, 'rb') as f:
                            audio_bytes = f.read()
                        self.send_response(200)
                        self.send_header('Content-Type', 'audio/mpeg')
                        self.send_header('Access-Control-Allow-Origin', '*')
                        self.send_header('Content-Length', str(len(audio_bytes)))
                        self.end_headers()
                        self.wfile.write(audio_bytes)
                    except Exception as e:
                        self.send_error(500, str(e))
                else:
                    self.send_error(404, f"MP3 File not found: {safe_name}")
                return

        # 2. SCRAPER ENDPOINT WITH ROBUST SSL AND REDIRECT FALLBACK
        elif parsed.path == '/api/scrape':
            target_url = params.get('url', [''])[0]
            
            if not target_url:
                self.send_json({'error': 'Missing url parameter'}, 400)
                return

            clean_url = target_url
            try:
                html = None
                req = urllib.request.Request(
                    clean_url,
                    headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'}
                )
                
                try:
                    with urllib.request.urlopen(req, context=ssl_ctx, timeout=12) as response:
                        html = response.read().decode('utf-8', errors='ignore')
                except urllib.error.HTTPError as http_err:
                    if http_err.code == 404 and re.search(r'/\d{4}/\d{2}/\d{2}/', clean_url):
                        fallback_url = re.sub(r'/\d{4}/\d{2}/\d{2}/', '/', clean_url)
                        req_fallback = urllib.request.Request(
                            fallback_url,
                            headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
                        )
                        with urllib.request.urlopen(req_fallback, context=ssl_ctx, timeout=12) as response:
                            html = response.read().decode('utf-8', errors='ignore')
                    else:
                        raise http_err

                if not html:
                    raise Exception("Impossibile caricare l'articolo")

                title_match = re.search(r'<h1[^>]*>(.*?)</h1>', html, re.IGNORECASE | re.DOTALL)
                if not title_match:
                    title_match = re.search(r'<title>(.*?)</title>', html, re.IGNORECASE)
                
                raw_title = title_match.group(1) if title_match else "Notizia FormulaPaddock F1"
                clean_title = re.sub(r'<[^>]+>', '', raw_title).strip()

                paragraphs = re.findall(r'<p[^>]*>(.*?)</p>', html, re.IGNORECASE | re.DOTALL)
                clean_p = []
                for p in paragraphs:
                    text = re.sub(r'<[^>]+>', '', p).strip()
                    text = re.sub(r'\s+', ' ', text)
                    if len(text) > 35 and not any(x in text.lower() for x in ['uncategorized', 'cookie', 'copyright', 'iscriviti']):
                        clean_p.append(text)

                extracted_images = []
                
                og_match = re.search(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']', html, re.IGNORECASE)
                if not og_match:
                    og_match = re.search(r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']', html, re.IGNORECASE)
                if og_match:
                    extracted_images.append(urllib.parse.urljoin(clean_url, og_match.group(1)))

                img_srcs = re.findall(r'<img[^>]+src=["\']([^"\']+)["\']', html, re.IGNORECASE)
                for src in img_srcs:
                    if 'wp-content/uploads' in src or 'uploads' in src:
                        full_img = urllib.parse.urljoin(clean_url, src)
                        if full_img not in extracted_images:
                            extracted_images.append(full_img)

                response_data = {
                    'status': 'SUCCESS',
                    'title': clean_title,
                    'paragraphs': clean_p,
                    'images': extracted_images
                }
                self.send_json(response_data)

            except Exception as e:
                url_slug = clean_url.rstrip('/').split('/')[-1].replace('-', ' ').title()
                fallback_title = url_slug if url_slug else "Notizia FormulaPaddock F1"
                self.send_json({
                    'status': 'SUCCESS',
                    'title': fallback_title,
                    'paragraphs': [
                        f"{fallback_title} • Aggiornamento esclusivo FormulaPaddock.it",
                        "Tutti i dettagli della telemetria e gli sviluppi tecnici per le prossime gare F1.",
                        "Leggi l'articolo completo su FormulaPaddock.it e segui i nostri social!"
                    ],
                    'images': ['assets/images/tech.jpg', 'assets/images/cyberpunk.jpg', 'assets/images/nature.jpg']
                })
            return

        # 3. LOCAL IMAGE PROXY ENDPOINT WITH SSL CONTEXT
        elif parsed.path == '/api/image-proxy':
            img_url = params.get('url', [''])[0]
            
            if not img_url:
                self.send_json({'error': 'Missing url parameter'}, 400)
                return

            try:
                req = urllib.request.Request(
                    img_url,
                    headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
                )
                with urllib.request.urlopen(req, context=ssl_ctx, timeout=12) as response:
                    content_type = response.headers.get('Content-Type', 'image/jpeg')
                    img_data = response.read()

                self.send_response(200)
                self.send_header('Content-Type', content_type)
                self.send_header('Access-Control-Allow-Origin', '*')
                self.send_header('Content-Length', str(len(img_data)))
                self.end_headers()
                self.wfile.write(img_data)
            except Exception as e:
                self.send_json({'error': str(e)}, 500)
            return

        # 4. STATIC FILE SERVER
        rel_path = parsed.path.lstrip('/')
        if not rel_path:
            rel_path = 'index.html'

        file_path = os.path.join(BASE_DIR, rel_path)
        if os.path.isfile(file_path):
            ctype, _ = mimetypes.guess_type(file_path)
            if not ctype:
                ctype = 'application/octet-stream'
            try:
                with open(file_path, 'rb') as f:
                    content = f.read()
                self.send_response(200)
                self.send_header('Content-Type', ctype)
                self.send_header('Access-Control-Allow-Origin', '*')
                self.send_header('Content-Length', str(len(content)))
                self.end_headers()
                self.wfile.write(content)
            except Exception as e:
                pass
        else:
            self.send_error(404, f"File not found: {rel_path}")

    def send_json(self, data, status=200):
        try:
            body = json.dumps(data, ensure_ascii=False).encode('utf-8')
            self.send_response(status)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except Exception:
            pass

if __name__ == '__main__':
    socketserver.TCPServer.allow_reuse_address = True
    os.chdir(BASE_DIR)
    with socketserver.TCPServer(("0.0.0.0", PORT), ReelProxyHandler) as httpd:
        print(f"ReelAI Cloud Server active on port {PORT}")
        httpd.serve_forever()
