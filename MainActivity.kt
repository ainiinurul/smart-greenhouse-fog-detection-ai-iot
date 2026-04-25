package com.omahiot.medini

import android.os.Bundle
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.util.Log
import androidx.activity.ComponentActivity

// Import tambahan
import android.util.Base64
import java.io.File
import java.io.FileOutputStream
import android.os.Environment
import android.webkit.URLUtil
import android.app.DownloadManager
import android.net.Uri
import android.widget.Toast
import android.content.Context
import android.view.View
import android.widget.LinearLayout

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // 1. Panggil desain layout XML
        setContentView(R.layout.activity_main)

        // 2. Hubungkan komponen XML ke Kotlin
        val webView = findViewById<WebView>(R.id.webView)
        val loadingScreen = findViewById<LinearLayout>(R.id.loadingScreen)

        // --- KODE NOTIFIKASI FCM ---
        com.google.firebase.messaging.FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            if (!task.isSuccessful) return@addOnCompleteListener
            Log.d("FCM_TOKEN", "Token HP ini: ${task.result}")
        }

        com.google.firebase.messaging.FirebaseMessaging.getInstance().subscribeToTopic("peringatan_kabut")
            .addOnCompleteListener { task ->
                if (task.isSuccessful) Log.d("FCM_TOPIC", "Sukses gabung grup peringatan_kabut!")
            }

        // --- SETTING WEBVIEW ---
        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true
        webView.fitsSystemWindows = true // Biar gak nabrak sinyal/baterai di atas

        // --- JEMBATAN EXPORT FILE (BLOB) ---
        webView.addJavascriptInterface(object : Any() {
            @android.webkit.JavascriptInterface
            fun getBase64FromBlobData(base64Data: String, mimetype: String, fileName: String) {
                convertBase64ToLogAndDownload(base64Data, mimetype, fileName)
            }
        }, "AndroidBlobHandler")

        // --- KODE DOWNLOAD LISTENER ---
        webView.setDownloadListener { url, userAgent, contentDisposition, mimetype, contentLength ->
            if (url.startsWith("blob:")) {
                val fileName = URLUtil.guessFileName(url, contentDisposition, mimetype)
                val js = """
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '$url', true);
                    xhr.responseType = 'blob';
                    xhr.onload = function() {
                        if (this.status == 200) {
                            var reader = new FileReader();
                            reader.readAsDataURL(this.response);
                            reader.onloadend = function() {
                                AndroidBlobHandler.getBase64FromBlobData(
                                    reader.result, '$mimetype', '$fileName'
                                );
                            }
                        }
                    };
                    xhr.send();
                """.trimIndent()
                webView.evaluateJavascript(js, null)
            } else {
                try {
                    val request = DownloadManager.Request(Uri.parse(url))
                    val fileName = URLUtil.guessFileName(url, contentDisposition, mimetype)
                    request.setMimeType(mimetype)
                    request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
                    request.setTitle(fileName)
                    request.setDescription("Sedang mengunduh file dari Atomic...")
                    request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)

                    val dm = getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
                    dm.enqueue(request)
                    Toast.makeText(this@MainActivity, "Mengunduh file: $fileName", Toast.LENGTH_SHORT).show()
                } catch (e: Exception) {
                    Toast.makeText(this@MainActivity, "Gagal: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }

        // --- KODE AJAIB HILANGIN LOADING ---
        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                // Kalau web udah selesai dimuat, sembunyikan loading, lalu tampilkan webnya
                loadingScreen.visibility = View.GONE
                webView.visibility = View.VISIBLE
            }

            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                super.onReceivedError(view, request, error)
                Log.e("WEBVIEW_ERROR", "Error: ${error?.description} | URL: ${request?.url}")
            }
        }

        // Mulai memuat web TA kamu
        webView.loadUrl("https://ta.atomic.web.id/")
    }

    // --- FUNGSI PENGOLAH FILE EXPORT ---
    private fun convertBase64ToLogAndDownload(base64Data: String, mimetype: String, fileName: String) {
        try {
            val pureBase64 = base64Data.substring(base64Data.indexOf(",") + 1)
            val fileAsBytes = Base64.decode(pureBase64, Base64.DEFAULT)
            val filePath = File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), fileName)

            val os = FileOutputStream(filePath, false)
            os.write(fileAsBytes)
            os.flush()
            os.close()

            Toast.makeText(this@MainActivity, "Berhasil simpan ke Downloads: $fileName", Toast.LENGTH_LONG).show()
        } catch (e: Exception) {
            Toast.makeText(this@MainActivity, "Gagal memproses file: ${e.message}", Toast.LENGTH_SHORT).show()
        }
    }
}