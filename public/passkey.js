/*
 * passkey.js — fingerprint, Windows Hello and Face ID, in the browser.
 *
 * All three are one API. The browser picks the sensor; nothing here knows or
 * cares which. Two jobs live in this file:
 *
 *   registration  from the security screen, for someone already signed in
 *   sign-in       from the login screen, on a browser that has registered one
 *
 * WebAuthn speaks ArrayBuffers and the server speaks base64url, so most of
 * the code below is that conversion. Getting it wrong produces a signature
 * that verifies against nothing, with no useful error — which is why the
 * conversion is written once, here, and used from both directions.
 */
(function () {
  "use strict";

  /* ------------------------------------------------------------ base64url */

  function fromB64u(text) {
    var padded = text.replace(/-/g, "+").replace(/_/g, "/");
    while (padded.length % 4) padded += "=";
    var binary = atob(padded);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
  }

  function toB64u(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = "";
    for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  /* --------------------------------------------------------------- plumbing */

  function token() {
    var tag = document.querySelector('meta[name="csrf-token"]');
    return tag ? tag.content : "";
  }

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "content-type": "application/json",
        "accept": "application/json",
        "x-csrf-token": token()
      },
      body: body === undefined ? "{}" : JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.error || "Something went wrong (" + res.status + ")");
        return data;
      });
    });
  }

  /*
   * What went wrong, in words worth reading.
   *
   * The browser's own errors for this are famously unhelpful — NotAllowedError
   * covers "you pressed cancel", "you waited too long" and "there is no
   * sensor", which need three different answers from the person reading them.
   */
  function explain(error) {
    if (!error) return "Something went wrong.";
    if (error.name === "NotAllowedError") return "Cancelled, or it timed out. Try again.";
    if (error.name === "InvalidStateError") return "This device is already registered.";
    if (error.name === "NotSupportedError") return "This browser cannot do that.";
    if (error.name === "SecurityError") return "The site address does not match what the passkey expects.";
    return error.message || String(error);
  }

  function available() {
    return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  }

  /* ----------------------------------------------------------- registering */

  function register(label) {
    if (!available()) {
      return Promise.reject(new Error("This browser cannot store passkeys."));
    }

    return post("/passkey/register/options", undefined)
      .then(function (options) {
        options.challenge = fromB64u(options.challenge);
        options.user.id = fromB64u(options.user.id);
        (options.excludeCredentials || []).forEach(function (entry) {
          entry.id = fromB64u(entry.id);
        });
        return navigator.credentials.create({ publicKey: options });
      })
      .then(function (credential) {
        if (!credential) throw new Error("The device returned nothing.");
        return post("/passkey/register", {
          clientDataJSON: toB64u(credential.response.clientDataJSON),
          attestationObject: toB64u(credential.response.attestationObject),
          label: label || ""
        });
      });
  }

  /* -------------------------------------------------------------- signing in */

  function signIn() {
    if (!available()) {
      return Promise.reject(new Error("This browser cannot use passkeys."));
    }

    return post("/login/passkey/options", undefined)
      .then(function (options) {
        options.challenge = fromB64u(options.challenge);
        (options.allowCredentials || []).forEach(function (entry) {
          entry.id = fromB64u(entry.id);
        });
        return navigator.credentials.get({ publicKey: options });
      })
      .then(function (assertion) {
        if (!assertion) throw new Error("The device returned nothing.");
        return post("/login/passkey", {
          credentialId: assertion.id,
          clientDataJSON: toB64u(assertion.response.clientDataJSON),
          authenticatorData: toB64u(assertion.response.authenticatorData),
          signature: toB64u(assertion.response.signature)
        });
      });
  }

  /* ------------------------------------------------------------------ wiring */

  function wire() {
    var signInButton = document.getElementById("kz-passkey-signin");
    var registerButton = document.getElementById("kz-passkey-register");
    var note = document.getElementById("kz-passkey-note");

    function say(message, bad) {
      if (!note) return;
      note.textContent = message;
      note.style.color = bad ? "#e8a598" : "";
      note.hidden = !message;
    }

    if (signInButton) {
      /* The server already decided this browser has a passkey; this only
         catches the case where the browser itself cannot oblige. */
      if (!available()) {
        signInButton.hidden = true;
      } else {
        signInButton.addEventListener("click", function () {
          signInButton.disabled = true;
          say("Waiting for your fingerprint, face or PIN…");

          signIn().then(function (data) {
            say("Signed in. One moment…");
            location.href = data.redirect || "/";
          }, function (error) {
            signInButton.disabled = false;
            say(explain(error), true);
          });
        });
      }
    }

    if (registerButton) {
      if (!available()) {
        registerButton.disabled = true;
        say("This browser cannot store passkeys. It needs a recent browser over https.", true);
      } else {
        /* A platform authenticator is the built-in sensor. Without one there
           is nothing to register, and saying so beats a dialog that opens and
           immediately fails. */
        if (window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
          window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
            .then(function (yes) {
              if (!yes) {
                registerButton.disabled = true;
                say("This device has no fingerprint reader, camera or PIN set up.", true);
              }
            })
            .catch(function () { /* leave it enabled and let the attempt say why */ });
        }

        registerButton.addEventListener("click", function () {
          var input = document.getElementById("kz-passkey-label");
          registerButton.disabled = true;
          say("Follow the prompt from your device…");

          register(input ? input.value : "").then(function (data) {
            say("Registered " + (data.label || "this device") + ". Reloading…");
            location.reload();
          }, function (error) {
            registerButton.disabled = false;
            say(explain(error), true);
          });
        });
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wire);
  } else {
    wire();
  }

  window.KZPasskey = { register: register, signIn: signIn, available: available };
})();
