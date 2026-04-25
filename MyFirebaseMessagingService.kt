package com.omahiot.medini

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class MyFirebaseMessagingService : FirebaseMessagingService() {

    // 1. Fungsi ini buat ngedapetin Token HP
    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d("FCM_TOKEN", "Token HP ini: $token")
    }

    // 2. Fungsi ini dipanggil pas ada pesan/notifikasi masuk dari server
    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        super.onMessageReceived(remoteMessage)

        // Ambil judul dan isi pesan dari Firebase. Kalau kosong, pakai teks default "Atomic"
        val title = remoteMessage.notification?.title ?: "Peringatan Greenhouse Atomic"
        val body = remoteMessage.notification?.body ?: "Kabut Terdeteksi, Nyalakan Aktuator!"

        tampilkanNotifikasi(title, body)
    }

    // 3. Fungsi buat nampilin pop-up notifikasinya di layar HP
    private fun tampilkanNotifikasi(title: String, message: String) {
        val channelId = "atomic_alert_channel"
        val intent = Intent(this, MainActivity::class.java)
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP)

        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_ONE_SHOT or PendingIntent.FLAG_IMMUTABLE
        )

        val notificationBuilder = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(android.R.drawable.ic_dialog_info) // Pakai ikon bawaan Android sementara
            .setContentTitle(title)
            .setContentText(message)
            .setAutoCancel(true) // Notif hilang kalau di-klik
            .setContentIntent(pendingIntent)

        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        // Buat Channel khusus (Wajib untuk Android 8.0 ke atas)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                channelId,
                "Peringatan Greenhouse Atomic",
                NotificationManager.IMPORTANCE_HIGH
            )
            notificationManager.createNotificationChannel(channel)
        }

        notificationManager.notify(0, notificationBuilder.build())
    }
}