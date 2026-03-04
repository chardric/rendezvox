package net.downstreamtech.rendezvox.data

import android.content.Context
import android.content.SharedPreferences

class EqPrefs(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("rendezvox_eq", Context.MODE_PRIVATE)

    var preset: String
        get() = prefs.getString("preset", "flat") ?: "flat"
        set(value) = prefs.edit().putString("preset", value).apply()

    var spatialMode: String
        get() = prefs.getString("spatial", "off") ?: "off"
        set(value) = prefs.edit().putString("spatial", value).apply()

    var customBands: List<Int>
        get() {
            val s = prefs.getString("custom_bands", null) ?: return List(10) { 0 }
            return s.split(",").mapNotNull { it.toIntOrNull() }.let {
                if (it.size == 10) it else List(10) { 0 }
            }
        }
        set(value) = prefs.edit().putString("custom_bands", value.joinToString(",")).apply()
}
