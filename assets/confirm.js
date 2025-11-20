/* HP Funnel Bridge - Hosted Confirmation Page Script */
(function () {
  function text(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
  }
  function isSafeUrl(u) {
    try {
      var x = new URL(u);
      return x.protocol === "https:" || x.protocol === "http:";
    } catch (_) {
      return false;
    }
  }
  function $(sel) {
    return document.querySelector(sel);
  }
  function bindCopy() {
    var nodes = document.querySelectorAll(".copy");
    nodes.forEach(function (n) {
      n.addEventListener("click", function () {
        var sel = n.getAttribute("data-copy");
        var el = document.querySelector(sel);
        if (!el) return;
        var txt = el.textContent || "";
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(txt);
        } else {
          var ta = document.createElement("textarea");
          ta.value = txt;
          document.body.appendChild(ta);
          ta.select();
          try {
            document.execCommand("copy");
          } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    });
  }

  function main() {
    var cfg = document.getElementById("hp-fb-config");
    if (!cfg) return;
    var pub = cfg.getAttribute("data-pub") || "";
    var cs = cfg.getAttribute("data-cs") || "";
    var ret = cfg.getAttribute("data-ret") || "";
    var succ = cfg.getAttribute("data-succ") || "";
    bindCopy();
    var msg = document.getElementById("messages");
    try {
      var stripe = window.Stripe(pub);
      // Dark appearance to better blend with funnel themes
      var elements = stripe.elements({
        clientSecret: cs,
        appearance: {
          theme: "night",
          variables: {
            colorPrimary: "#eab308",
            colorBackground: "#020617",
            colorText: "#e5e7eb",
            colorTextSecondary: "#9ca3af",
            colorDanger: "#f97373",
            borderRadius: "12px",
          },
        },
      });
      var paymentElement = elements.create("payment");
      paymentElement.mount("#element");
      var btn = document.getElementById("pay");
      paymentElement.on("change", function (e) {
        btn.disabled = !e.complete;
        if (msg) msg.textContent = "";
      });
      var piId = "";
      stripe
        .retrievePaymentIntent(cs)
        .then(function (pir) {
          if (pir && pir.paymentIntent) {
            var cents = pir.paymentIntent.amount || 0;
            var cur = String(pir.paymentIntent.currency || "usd").toUpperCase();
            text("amount", "Amount: $" + (cents / 100).toFixed(2) + " " + cur);
            piId = pir.paymentIntent.id || "";
          }
        })
        .catch(function () {});

      async function tryRedirect() {
        if (!succ || !isSafeUrl(succ) || !piId) return;
        if (msg) msg.textContent = "Payment processed. Finishing up...";
        
        // Use absolute URL for polling to avoid relative path confusion on hosted pages
        var pollingUrl = "/wp-json/hp-funnel/v1/orders/resolve?pi_id=" + encodeURIComponent(piId);
        if (window.location.origin) {
            // Ensure origin doesn't double slash if pollingUrl starts with /
            var origin = window.location.origin.replace(/\/$/, "");
            pollingUrl = origin + pollingUrl;
        }

        // Poll up to ~15 seconds for the Woo order to exist
        for (var i = 0; i < 15; i++) {
          try {
            var r = await fetch(
              pollingUrl,
              { headers: { Accept: "application/json" } }
            );
            if (r.ok) {
              var t = await r.json();
              if (t && t.order_id) {
                var uu = new URL(succ);
                // Pass order_id and both payment_intent + pi_id for compatibility
                uu.searchParams.set("order_id", String(t.order_id));
                if (piId) {
                  uu.searchParams.set("payment_intent", String(piId));
                  uu.searchParams.set("pi_id", String(piId));
                }
                window.location.replace(uu.toString());
                return;
              }
            }
          } catch (e) {}
          await new Promise(function (res) {
            setTimeout(res, 1000);
          });
        }
        // fallback: just go to succ with payment_intent + pi_id
        try {
          var u2 = new URL(succ);
          if (piId) {
            u2.searchParams.set("payment_intent", piId);
            u2.searchParams.set("pi_id", piId);
          }
          window.location.replace(u2.toString());
        } catch (_) {
          // if all else fails, reload
          // window.location.reload();
        }
      }

      btn.addEventListener("click", async function () {
        btn.disabled = true;
        if (msg) msg.textContent = "Processing...";
        try {
          var submitRes = await elements.submit();
          if (submitRes && submitRes.error) {
            if (msg) msg.textContent = submitRes.error.message || "Please check your details";
            btn.disabled = false;
            return;
          }
          var res = await stripe.confirmPayment({
            elements: elements,
            clientSecret: cs,
            confirmParams: { return_url: ret },
            redirect: "if_required",
          });
          if (res.error) {
            if (msg) msg.textContent = (res.error && res.error.message) ? res.error.message : "Payment failed";
            btn.disabled = false;
          } else {
            await tryRedirect();
            btn.disabled = true;
          }
        } catch (err) {
          if (msg) msg.textContent = (err && err.message) ? err.message : "Payment failed";
          btn.disabled = false;
        }
      });
    } catch (e) {
      if (msg) msg.textContent = "Failed to initialize payment";
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", main);
  } else {
    main();
  }
})();


