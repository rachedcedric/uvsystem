package pro.uvsystem.app;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // La page web interroge cette interface avant d'activer les notifications push.
        getBridge().getWebView().addJavascriptInterface(new ModelecNative(this), "ModelecNative");
    }
}
