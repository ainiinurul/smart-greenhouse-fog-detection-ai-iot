import cv2
import numpy as np
import requests
import base64
import time
import os
import socket
import threading
import json
from queue import Queue
from glob import glob
from datetime import datetime
 
# =================================================
# 1. KONFIGURASI
# =================================================
GH_ID = 1
NODE_ID = "cam-1"
 
Break ............

Break ............
 
# =================================================
# 2. AI MODEL
# =================================================
os.environ["TF_CPP_MIN_LOG_LEVEL"] = "2"
from tensorflow.keras.models import load_model
from tensorflow.keras.preprocessing.image import img_to_array
 
model = load_model("/home/pi/mobilenetv2_16_30_0.0008.keras")
 
 
# =================================================
# 3. NETWORK
# =================================================
def get_local_ip_prefix():
   s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
   try:
       s.connect(("10.255.255.255", 1))
       ip = s.getsockname()[0]
   except:
       return None
   finally:
       s.close()
   return ".".join(ip.split(".")[:-1]) + "."
 
 
def check_ip(ip, q):
   try:
       r = requests.get(f"http://{ip}/api/mode", timeout=0.5)
       if r.status_code == 200:
           q.put(ip)
   except:
       pass
 
 
def find_gateway_automatically():
   global current_gateway_ip, last_gateway_search_time
   last_gateway_search_time = time.time()
 
   prefix = get_local_ip_prefix()
   if not prefix:
       return False
 
   q = Queue()
   threads = [
       threading.Thread(
           target=check_ip, args=(f"{prefix}{i}", q), daemon=True
       )
       for i in range(1, 255)
   ]
 
   for t in threads:
       t.start()
   for t in threads:
       t.join()
 
   if not q.empty():
       current_gateway_ip = q.get()
       print(f"[GATEWAY] Ditemukan: {current_gateway_ip}")
       return True
 
   current_gateway_ip = None
   return False
 
 
def check_gateway_mode():
   global current_gateway_ip
 
   if (time.time() - last_gateway_search_time) > GATEWAY_SEARCH_INTERVAL:
       find_gateway_automatically()
 
   if not current_gateway_ip:
       if not find_gateway_automatically():
           return MODE_CLOUD
 
   try:
       r = requests.get(f"http://{current_gateway_ip}/api/mode", timeout=3)
       if r.status_code == 200:
           return r.json().get("mode", MODE_LOCAL)
   except:
       current_gateway_ip = None
 
   return MODE_CLOUD
 
 
# =================================================
# 4. STORAGE
# =================================================
def save_locally(payload):
   filename = f"{LOCAL_STORAGE_DIR}/data_{int(time.time())}.json"
   with open(filename, "w") as f:
       json.dump(payload, f)
   print(f"[LOCAL] Disimpan: {filename}")
 
 
def sync_data_to_cloud():
   files = sorted(glob(f"{LOCAL_STORAGE_DIR}/*.json"))
   if not files:
       return
 
   print(f"[SYNC] {len(files)} data akan dikirim...")
 
   for file_path in files:
       with open(file_path, "r") as f:
           data = json.load(f)
 
       if send_to_cloud_with_relay(data):
           os.remove(file_path)
       else:
           break
 
 
# =================================================
# 5. CLOUD + RELAY (FIX UTAMA)
# =================================================
def send_to_cloud_with_relay(payload):
   global USE_RELAY_ONLY
 
   api_headers = {
       "Authorization": f"Bearer {API_TOKEN}",
       "Content-Type": "application/json",
       "Accept": "application/json",
       "User-Agent": CUSTOM_USER_AGENT,
   }
 
   relay_headers = {
       "Authorization": f"Bearer {API_TOKEN}",
       "Content-Type": "application/json",
       "Accept": "application/json",
       "User-Agent": CUSTOM_USER_AGENT,
   }
 
   # ========================
   # 1. API UTAMA
   # ========================
   if not USE_RELAY_ONLY:
       try:
           print("[CLOUD] Kirim ke API utama...")
           r = requests.post(
               API_URL, json=payload, headers=api_headers, timeout=30
           )
 
           print(f"[DEBUG] Status: {r.status_code}")
           print(f"[DEBUG] Response: {r.text}")
 
           if (
               r.status_code in [401, 403, 429, 500]
               or "access denied" in r.text.lower()
               or "imunify360" in r.text.lower()
           ):
               print("[CLOUD] DITOLAK → pakai relay")
               USE_RELAY_ONLY = True
           else:
               print("[CLOUD] SUCCESS REAL")
               return True
 
       except Exception as e:
           print(f"[CLOUD] ERROR: {e}")
           USE_RELAY_ONLY = True
 
   # ========================
   # 2. RELAY
   # ========================
   for i in range(3):  # retry 3x
       try:
           print(f"[RELAY] Attempt {i+1}...")
           r = requests.post(
               RELAY_URL, json=payload, headers=relay_headers, timeout=40
           )
 
           print(f"[DEBUG] Relay Status: {r.status_code}")
           print(f"[DEBUG] Relay Response: {r.text}")
 
           if r.status_code in [200, 201]:
               print("[RELAY] SUCCESS REAL")
               return True
 
       except Exception as e:
           print(f"[RELAY] ERROR: {e}")
 
       time.sleep(2)
 
   print("[RELAY] GAGAL TOTAL")
   return False
 
 
# =================================================
# 6. AI FUNCTIONS
# =================================================
def capture_image():
   cap = cv2.VideoCapture(0)
   if not cap.isOpened():
       return None
 
   for _ in range(5):
       cap.read()
 
   ret, frame = cap.read()
   cap.release()
 
   return frame if ret else None
 
 
def predict_fog(frame):
   img = cv2.resize(frame, (224, 224))
   img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
   img = img_to_array(img) / 255.0
   img = np.expand_dims(img, axis=0)
 
   prob = model.predict(img, verbose=0)[0][0]
 
   if prob >= 0.5:
       return False, round(float(prob * 100), 1), "CERAH"
   else:
       return True, round(float((1 - prob) * 100), 1), "BERKABUT"
 
 
def encode_image(frame):
   resized = cv2.resize(frame, (480, 320))
   success, buffer = cv2.imencode(
       ".jpg", resized, [int(cv2.IMWRITE_JPEG_QUALITY), 70]
   )
 
   if not success:
       return None
 
   return base64.b64encode(buffer).decode("utf-8")
 
 
# =================================================
# 7. MAIN LOOP
# =================================================
def main():
   global message_counter
 
   print("=== CAMERA NODE STARTED (RELAY AUTO MODE) ===")
 
   while True:
       start = time.time()
       now = datetime.now()
 
       if START_HOUR <= now.hour < END_HOUR:
 
           mode = check_gateway_mode()
           mode_str = "LOCAL" if mode == MODE_LOCAL else "CLOUD"
 
           frame = capture_image()
 
           if frame is not None:
               message_counter += 1
 
               is_foggy, conf, status_txt = predict_fog(frame)
               img_b64 = encode_image(frame)
 
               print(
                   f"[AI] #{message_counter} {status_txt} ({conf}%) | {mode_str}"
               )
 
               
Break ........

Break ........
 
                   if not success:
                       print("[SYSTEM] Gagal semua → simpan lokal")
                       save_locally(payload)
 
       elapsed = time.time() - start
       time.sleep(max(1, CAPTURE_INTERVAL - elapsed))
 
 
if __name__ == "__main__":
   main()
