package id.nanangmrk.gajiku;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Ensure CookieManager persists cookies securely across restarts & app switches
        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(getBridge().getWebView(), true);
        cookieManager.flush();

        WebView webView = getBridge().getWebView();
        if (webView != null) {
            WebSettings settings = webView.getSettings();
            settings.setJavaScriptEnabled(true);
            settings.setDomStorageEnabled(true);
            settings.setDatabaseEnabled(true);
            settings.setAllowFileAccess(true);
            settings.setAllowContentAccess(true);
            settings.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
            settings.setJavaScriptCanOpenWindowsAutomatically(true);

            webView.setWebViewClient(new WebViewClient() {
                @Override
                public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                    String url = request.getUrl().toString();

                    // Open external intent schemes (WhatsApp, Tel, Mailto, Market) in native apps
                    if (url.startsWith("whatsapp:") || url.startsWith("tel:") || url.startsWith("mailto:") || url.startsWith("intent:") || url.startsWith("market:")) {
                        try {
                            Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                            startActivity(intent);
                            return true;
                        } catch (Exception e) {
                            return false;
                        }
                    }

                    // Keep internal URLs, localhost, LAN IPs, and data/blob URLs inside WebView
                    Uri parsedUri = request.getUrl();
                    String host = parsedUri.getHost();
                    boolean isInternal = host != null && (
                        host.contains("nanangmrk.id") || 
                        host.equals("localhost") || 
                        host.equals("127.0.0.1") || 
                        host.equals("10.0.2.2") || 
                        host.startsWith("192.168.") || 
                        host.startsWith("10.") || 
                        host.startsWith("172.")
                    ) || url.startsWith("blob:") || url.startsWith("data:");

                    if (!isInternal) {
                        try {
                            Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                            startActivity(intent);
                            return true;
                        } catch (Exception e) {
                            return false;
                        }
                    }

                    return super.shouldOverrideUrlLoading(view, request);
                }

                @Override
                public void onPageFinished(WebView view, String url) {
                    super.onPageFinished(view, url);
                    CookieManager.getInstance().flush();
                }
            });
        }
    }

    /**
     * Handle Android hardware and gesture back button:
     * Navigate backward in WebView history before exiting the application.
     */
    @Override
    public void onBackPressed() {
        WebView webView = getBridge().getWebView();
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
