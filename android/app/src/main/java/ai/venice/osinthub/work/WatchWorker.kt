package ai.venice.osinthub.work

import android.app.NotificationManager
import android.content.Context
import androidx.core.app.NotificationCompat
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import java.util.concurrent.TimeUnit
import ai.venice.osinthub.data.Api

class WatchWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {

    override suspend fun doWork(): Result {
        val res = Api.get(applicationContext, "v1", mapOf("r" to "alerts"))
        val root = res.getOrNull() as? JsonObject ?: return Result.retry()
        val alerts = root["alerts"] as? JsonArray ?: return Result.success()
        val nm = applicationContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        alerts.take(8).forEachIndexed { i, el ->
            val o = el as? JsonObject ?: return@forEachIndexed
            val title = (o["subject"] as? JsonPrimitive)?.contentOrNull ?: "OSINT alert"
            val msg = (o["message"] as? JsonPrimitive)?.contentOrNull ?: ""
            val sev = (o["severity"] as? JsonPrimitive)?.contentOrNull ?: "low"
            nm.notify(
                1000 + i,
                NotificationCompat.Builder(applicationContext, "watch")
                    .setContentTitle(if (sev == "high") "HIGH - $title" else title)
                    .setContentText(msg)
                    .setSmallIcon(android.R.drawable.ic_dialog_info)
                    .setStyle(NotificationCompat.BigTextStyle().bigText(msg))
                    .setAutoCancel(true)
                    .build()
            )
        }
        return Result.success()
    }

    companion object {
        fun schedule(c: Context) {
            val req = PeriodicWorkRequestBuilder<WatchWorker>(30, TimeUnit.MINUTES).build()
            WorkManager.getInstance(c).enqueueUniquePeriodicWork(
                "watch_poll", ExistingPeriodicWorkPolicy.KEEP, req
            )
        }
    }
}