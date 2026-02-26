package net.downstreamtech.rendezvox.data

import android.content.Context

object ServerPrefs {
    private const val PREF_NAME = "rendezvox_prefs"
    private const val KEY_SERVER_URL = "server_url"
    const val DEFAULT_URL = "https://radio.chadlinuxtech.net"

    fun getSavedUrl(context: Context): String? {
        return context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
            .getString(KEY_SERVER_URL, null)
    }

    fun saveUrl(context: Context, url: String) {
        context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
            .edit().putString(KEY_SERVER_URL, url).apply()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
            .edit().remove(KEY_SERVER_URL).apply()
    }
}
