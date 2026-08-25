/*
 * The small amount of behaviour the sign-in screens need.
 *
 * Only the password reveal. Everything else on these screens is a plain form
 * that works with JavaScript switched off, and should stay that way — a
 * sign-in page that cannot be used without scripts is a sign-in page that
 * fails in exactly the situations somebody most needs it.
 */
(function () {
  "use strict";

  var OPEN_EYE =
    "<path d='M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z' " +
    "stroke='currentColor' stroke-width='1.5'/>" +
    "<circle cx='12' cy='12' r='2.6' stroke='currentColor' stroke-width='1.5'/>";

  /* The same eye with a line through it, and the pupil left out — a struck
     through eye still reads as "eye" at 18 pixels unless the inside goes. */
  var SHUT_EYE =
    "<path d='M3 3l18 18' stroke='currentColor' stroke-width='1.5' stroke-linecap='round'/>" +
    "<path d='M10.6 6.1A9.9 9.9 0 0 1 12 5.5c6.4 0 10 6.5 10 6.5a17 17 0 0 1-3.3 4M6.5 8.1A17 17 0 0 0 2 12s3.6 6.5 10 6.5a10 10 0 0 0 3.4-.6' " +
    "stroke='currentColor' stroke-width='1.5'/>";

  function wire() {
    var buttons = document.querySelectorAll("[data-reveals]");

    Array.prototype.forEach.call(buttons, function (button) {
      var field = document.getElementById(button.getAttribute("data-reveals"));
      if (!field) return;

      button.addEventListener("click", function () {
        var showing = field.type === "text";

        field.type = showing ? "password" : "text";
        button.setAttribute("aria-pressed", String(!showing));
        button.setAttribute(
          "aria-label",
          showing ? "Passwort anzeigen · Show password" : "Passwort verbergen · Hide password"
        );

        var svg = button.querySelector("svg");
        if (svg) svg.innerHTML = showing ? OPEN_EYE : SHUT_EYE;

        /* Put the caret back where it was. Switching the input type moves it
           to the end, which is maddening halfway through a long password. */
        var at = field.value.length;
        field.focus();
        try { field.setSelectionRange(at, at); } catch (err) { /* some types refuse */ }
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wire);
  } else {
    wire();
  }
})();
