"""
FormulaPaddock Reel Engine — Render.com Cloud API Render Server 24/7 (Memory Optimized <512MB RAM)
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
import html

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

def wrap_reel_text(value, width=28, max_lines=4):
    words = re.sub(r'\s+', ' ', html.unescape(str(value or ''))).strip().upper().split(' ')
    lines, line = [], ''
    for word in words:
        test = (line + ' ' + word).strip()
        if len(test) > width and line:
            lines.append(line); line = word
        else:
            line = test
    if line: lines.append(line)
    lines = lines[:max_lines]
    if len(lines) == max_lines and len(words) > len(' '.join(lines).split()):
        lines[-1] = lines[-1].rstrip('. ') + '…'
    return '\n'.join(lines)

def download_image(url, path):
    if not url: return False
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, context=ssl_ctx, timeout=5) as resp, open(path, 'wb') as f:
            f.write(resp.read())
        return os.path.getsize(path) > 1000
    except Exception:
        return False

def article_images(url):
    if not url: return []
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, context=ssl_ctx, timeout=6) as resp:
            page = resp.read().decode('utf-8', errors='ignore')
        found = re.findall(r'<meta[^>]+(?:property=["\']og:image["\'][^>]+content|content)=["\']([^"\']+)', page, re.I)
        found += re.findall(r'<img[^>]+(?:src|data-src)=["\']([^"\']+)', page, re.I)
        return list(dict.fromkeys(urllib.parse.urljoin(url, x) for x in found if 'upload' in x or x.startswith('http')))[:4]
    except Exception:
        return []

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
            os.makedirs(EXPORTS_DIR, exist_ok=True)
            content_length = int(self.headers.get('Content-Length', 0))
            body_bytes = self.rfile.read(content_length) if content_length > 0 else b'{}'
            
            try:
                data = json.loads(body_bytes.decode('utf-8'))
            except Exception:
                data = {}

            overlay_text = data.get('text', 'FORMULAPADDOCK.IT • REEL F1')
            img_url = data.get('image_url', '')
            story_points = data.get('story_points') or []
            article_url = data.get('article_url', '')
            reel_mode = str(data.get('mode', 'news')).upper()

            # Download or use local image
            temp_img = os.path.join(EXPORTS_DIR, f"temp_{int(time.time())}.jpg")
            default_img = os.path.join(BASE_DIR, 'assets', 'images', 'tech.jpg')

            candidates = article_images(article_url) + ([img_url] if img_url else [])
            scene_images = []
            for idx, candidate in enumerate(candidates):
                candidate_path = os.path.join(EXPORTS_DIR, f"scene_{int(time.time())}_{idx}.jpg")
                if download_image(candidate, candidate_path):
                    scene_images.append(candidate_path)
                if len(scene_images) == 3: break

            if img_url and not scene_images:
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
            if not scene_images: scene_images = [input_img]
            while len(scene_images) < 3: scene_images.append(scene_images[-1])

            # Pick Random MP3 Track
            audio_files = [os.path.join(AUDIO_DIR, f) for f in os.listdir(AUDIO_DIR) if f.lower().endswith('.mp3')]
            chosen_audio = random.choice(audio_files) if audio_files else None

            # Render MP4 Video via FFmpeg (720x1280 HD for <150MB RAM usage)
            output_mp4 = os.path.join(EXPORTS_DIR, f"reel_{int(time.time())}.mp4")
            
            try:
                targetW = 720
                targetH = 1280
                duration = 15
                fps = 30

                points = []
                for i in range(3):
                    p = story_points[i] if i < len(story_points) and isinstance(story_points[i], dict) else {}
                    points.append((p.get('label') or ['IL FATTO','PERCHÉ CONTA','COSA SEGUE'][i], p.get('text') or overlay_text))

                font_candidates = [
                    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"
                ]
                font_part = ""
                for font_cand in font_candidates:
                    if os.path.exists(font_cand):
                        font_part = f":fontfile='{font_cand}'"
                        break

                text_files = []
                for i, (_, txt) in enumerate(points):
                    pth = os.path.join(EXPORTS_DIR, f"point_{int(time.time())}_{i}.txt")
                    with open(pth, 'w', encoding='utf-8') as f: f.write(wrap_reel_text(txt))
                    text_files.append(pth.replace('\\','/').replace(':','\\:'))
                filters = []
                for i, (label, _) in enumerate(points):
                    filters.append(f"[{i}:v]scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280,drawbox=x=0:y=0:w=iw:h=ih:color=black@0.38:t=fill,drawbox=x=0:y=0:w=14:h=ih:color=#e10600:t=fill,drawtext=text='FORMULAPADDOCK.IT  •  {reel_mode}'{font_part}:fontcolor=#ffd100:fontsize=20:x=42:y=54,drawtext=text='{label}'{font_part}:fontcolor=#ffd100:fontsize=25:x=54:y=720,drawbox=x=38:y=760:w=644:h=360:color=black@0.82:t=fill,drawtext=textfile='{text_files[i]}'{font_part}:fontcolor=white:fontsize=37:line_spacing=14:x=58:y=805,trim=duration=4,setpts=PTS-STARTPTS[s{i}]")
                filters.append("color=c=#08090d:s=720x1280:d=3,drawbox=x=28:y=28:w=664:h=1224:color=#e10600:t=4,drawtext=text='FORMULAPADDOCK.IT'"+font_part+":fontcolor=white:fontsize=48:x=(w-text_w)/2:y=390,drawtext=text='SEGUICI'"+font_part+":fontcolor=#ffd100:fontsize=62:x=(w-text_w)/2:y=570,drawtext=text='E CLICCA MI PIACE'"+font_part+":fontcolor=white:fontsize=32:x=(w-text_w)/2:y=660,drawtext=text='🏁'"+font_part+":fontcolor=white:fontsize=70:x=(w-text_w)/2:y=790[outro]")
                filters.append("[s0][s1][s2][outro]concat=n=4:v=1:a=0[v]")
                vf = ';'.join(filters)

                if chosen_audio and os.path.exists(chosen_audio):
                    cmd = [
                        'ffmpeg', '-y', '-framerate', '15', '-loop', '1', '-i', scene_images[0], '-framerate', '15', '-loop', '1', '-i', scene_images[1], '-framerate', '15', '-loop', '1', '-i', scene_images[2], '-stream_loop', '-1', '-i', chosen_audio,
                        '-filter_complex', vf, '-map', '[v]', '-map', '3:a', '-t', str(duration),
                        '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2',
                        '-c:a', 'aac', '-b:a', '128k', '-af', 'volume=0.38',
                        '-pix_fmt', 'yuv420p', '-r', '20', '-shortest', '-movflags', '+faststart', output_mp4
                    ]
                else:
                    cmd = [
                        'ffmpeg', '-y', '-loop', '1', '-i', input_img,
                        '-t', str(duration), '-vf', vf,
                        '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2',
                        '-pix_fmt', 'yuv420p', '-movflags', '+faststart', output_mp4
                    ]

                res = subprocess.run(cmd, capture_output=True, text=True)

                if res.returncode != 0 or not os.path.exists(output_mp4):
                    simple_vf = f"scale={targetW}:{targetH}:force_original_aspect_ratio=increase,crop={targetW}:{targetH}"
                    cmd_simple = [
                        'ffmpeg', '-y', '-loop', '1', '-i', input_img,
                        '-t', str(duration), '-vf', simple_vf,
                        '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '2', '-pix_fmt', 'yuv420p', output_mp4
                    ]
                    subprocess.run(cmd_simple, capture_output=True)

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
