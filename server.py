"""
FormulaPaddock Reel Engine — Render.com / Cloud Server 24/7
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

sys.stdout.reconfigure(encoding='utf-8')

# Dynamic Port assigned by Render.com or fallback to 5173
PORT = int(os.environ.get('PORT', 5173))
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
EXPORTS_DIR = os.path.join(BASE_DIR, 'exports')
DOWNLOADS_DIR = os.path.expanduser(r'~\Downloads')
DRIVE_FOLDER_ID = '1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K'

os.makedirs(EXPORTS_DIR, exist_ok=True)

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
        # 1. AUTO-SAVE REEL TO EXPORTS & GOOGLE DRIVE
        if self.path.startswith('/api/save-drive'):
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
                    # Check local assets/audio or downloads
                    audio_dir = os.path.join(BASE_DIR, 'assets', 'audio')
                    search_dirs = [audio_dir, DOWNLOADS_DIR]

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
                target_path = os.path.join(BASE_DIR, 'assets', 'audio', safe_name)
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

        # 2. LOCAL API SCRAPER ENDPOINT
        elif parsed.path == '/api/scrape':
            target_url = params.get('url', [''])[0]
            
            if not target_url:
                self.send_json({'error': 'Missing url parameter'}, 400)
                return

            try:
                req = urllib.request.Request(
                    target_url,
                    headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'}
                )
                with urllib.request.urlopen(req, timeout=12) as response:
                    html = response.read().decode('utf-8', errors='ignore')

                title_match = re.search(r'<h1[^>]*>(.*?)</h1>', html, re.IGNORECASE | re.DOTALL)
                if not title_match:
                    title_match = re.search(r'<title>(.*?)</title>', html, re.IGNORECASE)
                
                raw_title = title_match.group(1) if title_match else "Notizia F1"
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
                    extracted_images.append(urllib.parse.urljoin(target_url, og_match.group(1)))

                img_srcs = re.findall(r'<img[^>]+src=["\']([^"\']+)["\']', html, re.IGNORECASE)
                for src in img_srcs:
                    if 'wp-content/uploads' in src or 'uploads' in src:
                        full_img = urllib.parse.urljoin(target_url, src)
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
                self.send_json({'error': str(e)}, 500)
            return

        # 3. LOCAL IMAGE PROXY ENDPOINT
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
                with urllib.request.urlopen(req, timeout=12) as response:
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
    # Bind to 0.0.0.0 for Render.com cloud deployment
    with socketserver.TCPServer(("0.0.0.0", PORT), ReelProxyHandler) as httpd:
        print(f"ReelAI Cloud Server active on port {PORT}")
        httpd.serve_forever()
