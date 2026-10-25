package ai.venice.osinthub

import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Place
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import ai.venice.osinthub.ui.HubTheme
import ai.venice.osinthub.ui.screens.HomeScreen
import ai.venice.osinthub.ui.screens.MapScreen
import ai.venice.osinthub.ui.screens.SettingsScreen
import ai.venice.osinthub.ui.screens.ToolsScreen
import ai.venice.osinthub.ui.screens.WatchScreen
import ai.venice.osinthub.util.detect
import ai.venice.osinthub.work.WatchWorker

class MainActivity : ComponentActivity() {

    private var sharedText by mutableStateOf<String?>(null)
    private var sharedKind by mutableStateOf<String?>(null)

    private val perms = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        handleIntent(intent)
        requestPerms()
        WatchWorker.schedule(this)
        setContent {
            HubTheme {
                var route by remember { mutableStateOf("home") }
                Scaffold(
                    containerColor = MaterialTheme.colorScheme.background,
                    bottomBar = {
                        NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                            val items = listOf(
                                Triple("home", "Hub", Icons.Filled.Dashboard),
                                Triple("tools", "OSINT", Icons.Filled.Search),
                                Triple("map", "Map", Icons.Filled.Place),
                                Triple("watch", "Watch", Icons.Filled.Notifications),
                                Triple("settings", "Settings", Icons.Filled.Settings)
                            )
                            items.forEach { (r, label, icon) ->
                                NavigationBarItem(
                                    selected = route == r,
                                    onClick = { route = r },
                                    icon = { Icon(icon, label) },
                                    label = { Text(label) }
                                )
                            }
                        }
                    }
                ) { pad ->
                    Box(Modifier.padding(pad)) {
                        when (route) {
                            "home" -> HomeScreen()
                            "tools" -> ToolsScreen(sharedText, sharedKind) {
                                sharedText = null; sharedKind = null
                            }
                            "map" -> MapScreen()
                            "watch" -> WatchScreen()
                            else -> SettingsScreen()
                        }
                    }
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleIntent(intent)
    }

    private fun handleIntent(i: Intent?) {
        if (i?.action == Intent.ACTION_SEND && i.type == "text/plain") {
            val t = i.getStringExtra(Intent.EXTRA_TEXT)
            sharedText = t
            sharedKind = t?.let { detect(it)?.first }
        }
    }

    private fun requestPerms() {
        val want = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        )
        if (Build.VERSION.SDK_INT >= 33) want += Manifest.permission.POST_NOTIFICATIONS
        perms.launch(want.toTypedArray())
    }
}