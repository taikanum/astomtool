package ai.venice.osinthub.ui.screens

import android.annotation.SuppressLint
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import ai.venice.osinthub.data.Prefs
import androidx.compose.ui.platform.LocalContext

@SuppressLint("SetJavaScriptEnabled")
@Composable
fun MapScreen() {
    val ctx = LocalContext.current
    val base by Prefs.baseUrl(ctx).collectAsState(initial = "")
    val key by Prefs.apiKey(ctx).collectAsState(initial = "")

    val html = """
<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>html,body,#m{height:100%;margin:0;background:#0b0f17}</style></head>
<body><div id="m"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
var map=L.map('m').setView([20,0],2);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);
fetch('${'$'}{BASE}?t=v1&r=points&i=1${'$'}{KEYQ}')
 .then(r=>r.json()).then(function(d){
   var b=[];
   (d.points||[]).forEach(function(p){
     if(!p.lat||!p.lon)return;
     var gps=p.geo_src==='gps';
     L.circleMarker([p.lat,p.lon],{radius:gps?8:4,color:gps?'#4ade80':'#60a5fa',
       fillColor:gps?'#4ade80':'#60a5fa',fillOpacity:gps?.85:.45,weight:1}).addTo(map)
       .bindPopup('<b>'+(p.city||'')+(p.country?', '+p.country:'')+'</b><br>'+(p.ts||''));
     b.push([p.lat,p.lon]);
   });
   if(b.length)map.fitBounds(b,{padding:[30,30],maxZoom:14});
 });
</script></body></html>
    """.trimIndent()
        .replace("\${BASE}", if (base.isBlank()) "https://example.com/tracking.php" else base)
        .replace("\${KEYQ}", if (key.isBlank()) "" else "&key=" + key)

    AndroidView(
        modifier = Modifier.fillMaxSize(),
        factory = { c ->
            WebView(c).apply {
                settings.javaScriptEnabled = true
                settings.domStorageEnabled = true
                webViewClient = WebViewClient()
                loadDataWithBaseURL(base, html, "text/html", "utf-8", null)
            }
        }
    )
}