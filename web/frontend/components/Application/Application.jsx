import React, {useEffect, useState} from "react"
import AddedScripts from "../AddedScripts"
import ScriptForm from "../ScriptForm"
import CookieScriptInformation from "../CookieScriptInformation"
import Cookie from "../../assets/images/icons/cookie.svg"
import {useAuthenticatedFetch} from "../../hooks"
import GoogleConsentMode from "../GoogleConsentMode/index.js"

export function Application() {
  const fetch = useAuthenticatedFetch()
  const [isLoading, setIsLoading] = useState(false)
  const [scripts, setScripts] = useState([])

  async function loadScripts() {
    setIsLoading(true)

    const response = await fetch('/api/get-script-tags')
    const data = await response.json()
    setScripts(data)
    setIsLoading(false)
  }

  const handleScriptRemove = async (scriptId) => {
    setIsLoading(true)

    await fetch(`/api/remove-script-tag/${scriptId}`, {
      method: 'DELETE'
    })
      .then(loadScripts)
      .finally(() => setIsLoading(false))
  }

  const handleSubmit = async (values) => {
    setIsLoading(true)

    await fetch('/api/add-script', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({url: values.script})
    })
      .then(loadScripts)
      .finally(() => setIsLoading(false))
  }

  useEffect(() => {
    loadScripts()
  }, [])

  return (
    <>
      <h2 className="header">Cookie Script Settings</h2>
      <div className="cookie-script-settings">
        <div className="cookie-script form-wrapper">
          <section>
            {scripts.length !== 0
              ? <header className="cookie-script__header">
                <div>Already added scripts</div>
              </header>
              : null
            }
          </section>
          <section>
            <AddedScripts
              scripts={scripts}
              isLoading={isLoading}
              handleScriptRemove={handleScriptRemove}
            />
            <ScriptForm
              isLoading={isLoading}
              handleSubmit={handleSubmit}
            />
          </section>
          <section>
            <CookieScriptInformation/>
          </section>
          {isLoading
            ? <div className="cookie-script__loading-cover ">
              <img className="rotating" src={Cookie} alt="Cookie"/>
            </div>
            : null
          }
        </div>
        <div className="google-consent-mode form-wrapper">
          <GoogleConsentMode/>
        </div>
      </div>
    </>
  )
}
