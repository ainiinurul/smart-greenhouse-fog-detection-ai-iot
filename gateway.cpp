#include <Arduino.h>
#include <esp_task_wdt.h>
#include <Wire.h>
#include <ESPAsyncWebServer.h>
#include <ArduinoJson.h>

// ... (break: Include library bawaan ESP32 dan eksternal lainnya) ...

#include "SensorDataManager.h"
#include "RelayController.h"
#include "MyNetworkManager.h"
#include "RTCManager.h"

// ... (break: Deklarasi global structs, queue PendingControlAction, dan library pendukung) ...

// --- Global Objects ---
LCDDisplay lcd;
SensorDataManager sensorData;
bool sdCardOk = false;
SDCardLogger sd_logger(lcd, sdCardOk);
RelayController relay(lcd);
MyNetworkManager net(sensorData, lcd, relay);
RTCManager rtc_mgr(lcd, net);
AsyncWebServer server(80);
WebSocketManager wsManager(sensorData, relay, net, rtc_mgr);

// State Variables
unsigned long lastLoop = 0;
int apiStep = 0;

// ... (break: Fungsi utilitas queue processPendingControlActions() & WebSerial recvMsg()) ...

// ==================================================================================
//   SETUP
// ==================================================================================
void setup() {
    Serial.begin(115200);
    esp_task_wdt_init(WDT_TIMEOUT, true);
    esp_task_wdt_add(NULL);

    Wire.begin(SDA_PIN, SCL_PIN);
    lcd.begin();
    sensorData.begin();
    relay.begin();

    // ... (break: Inisialisasi Jaringan WiFi/GPRS net.begin() dan SD Card) ...

    // API Endpoint Penerima Data dari Node Sensor dan Node Kamera
    server.on("/api/data", HTTP_POST, [](AsyncWebServerRequest *r) {}, NULL, 
        [](AsyncWebServerRequest *r, uint8_t *data, size_t len, size_t idx, size_t total) {
            
            // ... (break: Alokasi buffer memori dan dekripsi payload AES) ...
            
            JsonDocument doc;
            DeserializationError err = deserializeJson(doc, ctx->buffer, payloadLen);
            if (!err) {
                String nodeName = doc["node_id"].as<String>();
                int received_gh_id = doc["gh_id"] | 0;
                unsigned long tx = doc["timestamp"] | 0UL;
                
                // Pengecekan ID Greenhouse untuk menghindari cross-talk node nyasar
                if (received_gh_id == GH_ID_CONFIG) {
                    
                    // --- LOGIKA NODE CAMERA (KABUT) ---
                    if (nodeName.startsWith("cam")) {
                        bool foggy = false;
                        const JsonVariantConst fogValue = doc["is_foggy"];
                        
                        // Parsing fleksibel dari Node Kamera (boolean atau integer 1/0)
                        if (fogValue.is<bool>()) {
                            foggy = fogValue.as<bool>();
                        } else if (fogValue.is<int>()) {
                            foggy = (fogValue.as<int>() == 1);
                        }
                        
                        float conf = doc["confidence"] | 0.0;
                        
                        // Masukkan ke antrean state gateway (Nilai Suhu/Kelembapan/Cahaya diset NAN)
                        enqueueNodeMutation(nodeName.c_str(), NAN, NAN, NAN, foggy, conf, tx, time(nullptr), payloadLen, false);
                    } 
                    // --- LOGIKA NODE SENSOR LINGKUNGAN ---
                    else {
                        float t = doc["temperature"] | NAN;
                        float h = doc["humidity"] | NAN;
                        float l = doc["light_intensity"] | NAN;
                        
                        enqueueNodeMutation(nodeName.c_str(), t, h, l, false, 0, tx, time(nullptr), payloadLen, true);
                    }
                } else {
                    // ... (break: Pencetakan log peringatan jika terdapat node nyasar dari GH lain) ...
                }
                
                r->send(200, "application/json", "{\"status\":\"ok\"}");
            }
    });

    WebSerial.begin(&server);
    server.begin();
    rtc_mgr.begin();
    
    lastLoop = millis();
    lcd.message(0, 0, "Setup OK", true);
}

// ==================================================================================
//   MAIN LOOP
// ==================================================================================
void loop() {
    esp_task_wdt_reset();
    
    // Pemrosesan Data Queue dari RAM
    processPendingControlActions();
    
    unsigned long now = millis();
    
    // Layanan Konektivitas dan Event Asinkron
    net.handleWiFi(computeControlSafeBlockingBudget(now));
    wsManager.pumpAsyncEvents();

    // ... (break: Layanan sinkronisasi Cloud API Step 1 - Step 3 (Node Data, Threshold, Schedule)) ...

    // Step 4: Ambil Status Kamera dari Cloud API (Khusus GH 2)
    if (apiStep == 4 && (now - lastStepMillis >= 3000)) {
        
        // ... (break: Persiapan serviceCriticalControlPath dan validasi Timeout HTTP) ...
        
#if GH_ID_CONFIG == 2
        CloudFogSnapshot fogSnapshot = {};
        const bool cameraOk = net.fetchCameraStatus(fogSnapshot, requestTimeoutMs);
        
        if (cameraOk) {
            applyCloudFogSnapshot(fogSnapshot); // Terapkan status kabut dari Cloud
        }
        // ... (break: Logika penanganan error Mode Recovery Sync Cloud) ...
#endif
        apiStep = 0;                 // Selesai semua urutan API
        wsManager.broadcastStatus(); // Update web dashboard
    }

    // Deteksi Failsafe Sistem
    const GatewayControlState controlState = getControlState(now);
    bool shouldFailSafe = resolveShouldEnterFailSafe(controlState, lastHealthyControlPathMs, now);
    
    if (shouldFailSafe && !isInFailSafeMode) {
        isInFailSafeMode = true;
        relay.forceSafeState(); 
    } else if (!shouldFailSafe && isInFailSafeMode) {
        isInFailSafeMode = false;
    }

    // Eksekusi Loop Kontrol Logika Aktuator
    if (now - lastLoop >= LOOP_MS) {
        lastLoop = now;
        runControlLogic();
    }

    // ... (break: Siklus pemeliharaan SD Card, Logging QoS, dan Update OTA otomatis) ...
    yield();
}

// ==================================================================================
//   CORE CONTROL LOGIC
// ==================================================================================
void runControlLogic() {
    rtc_mgr.update();
    const unsigned long nowMs = millis();
    const GatewayControlState controlState = getControlState(nowMs);

    // Ambil Parameter Lingkungan
    const float controlHumidity = sensorData.humidity;
    const float controlTemperature = sensorData.temperature;
    const float controlLight = sensorData.light;
    
    // Parameter Status Kamera (Deteksi Kabut)
    const bool controlFog = sensorData.isFoggy;
    
    // ... (break: Ambil parameter Min/Max Threshold dari objek sensorData) ...
    
    const bool timeValid = rtc_mgr.isTimeSet();
    int H = 0, M = 0;
    if (timeValid) {
        time_t raw = time(nullptr);
        struct tm *ti = localtime(&raw);
        if (ti) { H = ti->tm_hour; M = ti->tm_min; }
    }

    // Evaluasi Aktuator
    if (isInFailSafeMode) {
        relay.forceSafeState();
    } else {
        const bool allowThresholdEval = controlState.activeSourceHealthy;
        const bool useLocalSchedules = controlState.runtimeUsesLocalData;

        // Kontrol Aktuator Utama (Variabel controlFog diumpankan sebagai pengambil keputusan krusial)
        bool r1Changed = relay.updateSingleRelayState(RELAY_EXHAUST, controlHumidity, controlHumMin, controlHumMax, controlTemperature, controlTempMin, controlTempMax, H, M, controlFog, useLocalSchedules, timeValid, allowThresholdEval);
        
        bool r2Changed = relay.updateSingleRelayState(RELAY_DEHUMIDIFIER, controlHumidity, controlHumMin, controlHumMax, controlTemperature, controlTempMin, controlTempMax, H, M, controlFog, useLocalSchedules, timeValid, allowThresholdEval);
        
        bool r3Changed = relay.updateSingleRelayState(RELAY_BLOWER, controlTemperature, controlTempMin, controlTempMax, controlTemperature, controlTempMin, controlTempMax, H, M, controlFog, useLocalSchedules, timeValid, allowThresholdEval);
        
        relay.ensureRelay4Off();

        // ... (break: Logika Sinkronisasi antrean status relay r1Changed, r2Changed ke jaringan) ...
    }

    // ... (break: Pembaruan Layar LCD lokal di lapangan dengan indikator controlFog) ...

    // Logging ke SD Card
#if GH_ID_CONFIG == 2
    if (sdCardOk)
#else
    if (sdCardOk && rtc_mgr.getTime()[0] != 'Y')
#endif
    {
        // Fungsi logger menyimpan parameter suhu, kelembapan, status relay, serta status deteksi kabut (controlFog)
        sd_logger.logData(rtc_mgr.getTime(), controlTemperature, controlHumidity, controlLight,
                          net.getSignalQuality(), net.isGprsConnected(),
                          relay.getR1(), relay.getR2(), relay.getR3(), relay.getR4(), controlFog,
                          controlTempMin, controlTempMax, controlHumMin, controlHumMax,
                          sensorData.getModeString().c_str(), 
                          controlState.thresholdRuntimeSource, 
                          controlState.scheduleEffectiveSource,
                          relay.wasScheduleActiveForRelay(RELAY_EXHAUST), 
                          relay.wasScheduleActiveForRelay(RELAY_DEHUMIDIFIER), 
                          relay.wasScheduleActiveForRelay(RELAY_BLOWER),
                          relay.getActiveScheduleIdForRelay(RELAY_EXHAUST), 
                          relay.getActiveScheduleIdForRelay(RELAY_DEHUMIDIFIER), 
                          relay.getActiveScheduleIdForRelay(RELAY_BLOWER),
                          relay.getRelayDecisionSourceString(RELAY_EXHAUST), 
                          relay.getRelayDecisionSourceString(RELAY_DEHUMIDIFIER), 
                          relay.getRelayDecisionSourceString(RELAY_BLOWER));
    }
}
