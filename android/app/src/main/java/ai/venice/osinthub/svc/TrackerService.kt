package ai.venice.osinthub.svc

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.location.Location
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import ai.venice.osinthub.MainActivity
import ai.venice.osinthub.R
import ai.venice.osinthub.data.Api
import ai.venice.osinthub.data.Prefs

class TrackerService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var loop: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        startForeground(1, note("Waiting for GPS"))
        val fused = LocationServices.getFusedLocationProviderClient(this)
        loop = scope.launch {
            while (true) {
                val interval = Prefs.interval(this@TrackerService).first().coerceAtLeast(20)
                try {
                    val loc: Location? = com.google.android.gms.tasks.Tasks.await(
                        fused.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                    )
                    if (loc != null) {
                        val body = buildString {
                            append("{\"kind\":\"native_gps\",\"lat\":").append(loc.latitude)
                            append(",\"lon\":").append(loc.longitude)
                            append(",\"acc\":").append(loc.accuracy)
                            append(",\"geo_src\":\"gps\"}")
                        }
                        val r = Api.post(this@TrackerService, "beacon", body)
                        note(
                            if (r.isSuccess) "sent +/-" + loc.accuracy.toInt() + "m"
                            else "send failed"
                        )
                    }
                } catch (e: Exception) { note("gps error") }
                delay(interval * 1000L)
            }
        }
    }

    private fun note(text: String): Notification {
        val pi = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        return NotificationCompat.Builder(this, "tracker")
            .setContentTitle("OSINT Hub tracker")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setOngoing(true)
            .setContentIntent(pi)
            .build()
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        fun start(c: Context) {
            val i = Intent(c, TrackerService::class.java)
            if (Build.VERSION.SDK_INT >= 26) c.startForegroundService(i) else c.startService(i)
        }
        fun stop(c: Context) { c.stopService(Intent(c, TrackerService::class.java)) }
    }
}