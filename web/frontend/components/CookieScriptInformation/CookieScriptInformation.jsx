import React from "react";

export default function CookieScriptInformation() {
  return (
    <div className="cookie-script__information">
      <h2>How to use this application:</h2>
      <ol>
        <li>Register account on <a href="https://cookie-script.com/">CookieScript</a></li>
        <li>Create a banner for your website</li>
        <li>Copy your banner code and insert it in the field above</li>
        <li>All done, your website will now show the cookie banner</li>
      </ol>
      <p>If needed, you can adjust your banner settings in your CookieScript dashboard.</p>
      <p>To block third-party cookies you might still have to make these changes: <a href="https://cookie-script.com/how-to-block-third-party-cookies.html">How to block third-party cookies.</a></p>
    </div>
  )
}