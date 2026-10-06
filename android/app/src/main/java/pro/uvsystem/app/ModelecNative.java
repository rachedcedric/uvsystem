package pro.uvsystem.app;

import android.content.Context;
import android.webkit.JavascriptInterface;

import java.util.List;

/**
 * Informations natives exposées à la page web (window.ModelecNative).
 *
 * hasFirebase() dit si cet APK a été construit avec google-services.json. Sans
 * cela, l'appel PushNotifications.register() du plugin lève une exception non
 * rattrapable et fait planter toute l'application : la page ne doit donc
 * l'appeler que lorsque cette méthode renvoie true.
 */
public class ModelecNative {

    private final Context context;

    public ModelecNative(Context context) {
        this.context = context.getApplicationContext();
    }

    @JavascriptInterface
    public boolean hasFirebase() {
        try {
            // Réflexion : la classe vient de firebase-messaging, absente du classpath de compilation de l'app.
            Object apps = Class.forName("com.google.firebase.FirebaseApp")
                    .getMethod("getApps", Context.class)
                    .invoke(null, context);

            return apps instanceof List && !((List<?>) apps).isEmpty();
        } catch (Throwable ignored) {
            return false;
        }
    }
}
