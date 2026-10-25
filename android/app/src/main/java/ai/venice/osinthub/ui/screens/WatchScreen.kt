package ai.venice.osinthub.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import ai.venice.osinthub.data.Api

@Composable
fun WatchScreen() {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var alerts by remember { mutableStateOf<List<Triple<String, String, String>>>(emptyList()) }

    fun load() {
        scope.launch {
            val r = Api.get(ctx, "v1", mapOf("r" to "alerts"))
            alerts = r.getOrNull()?.let { el ->
                ((el as? JsonObject)?.get("alerts") as? JsonArray)?.mapNotNull { a ->
                    val o = a as? JsonObject ?: return@mapNotNull null
                    Triple(
                        (o["severity"] as? JsonPrimitive)?.contentOrNull ?: "low",
                        (o["subject"] as? JsonPrimitive)?.contentOrNull ?: "",
                        (o["message"] as? JsonPrimitive)?.contentOrNull ?: ""
                    )
                }
            } ?: emptyList()
        }
    }

    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Alerts", style = MaterialTheme.typography.headlineSmall)
        Button(onClick = { load() }) { Text("Refresh") }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(alerts) { (sev, subj, msg) ->
                Card {
                    Column(Modifier.padding(12.dp)) {
                        Text(
                            sev.uppercase() + "  " + subj,
                            color = if (sev == "high") MaterialTheme.colorScheme.error
                            else MaterialTheme.colorScheme.primary,
                            style = MaterialTheme.typography.labelSmall
                        )
                        Text(msg, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}