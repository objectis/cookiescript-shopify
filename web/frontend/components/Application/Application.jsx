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
  }

  const handleScriptRemove = async (scriptId) => {
    setIsLoading(true)

    await fetch(`/api/remove-script-tag/${scriptId}`, {
      method: 'DELETE'
    })
      .then(loadScripts)
      .then(setIsLoading(false))
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
        .then(setIsLoading(false))
  }

  useEffect(() => {
    loadScripts()
  }, [])

return (
  <div className="cookie-script-settings">
    <h2 className="header">Cookie Script Settings</h2>
    <div className="cookie-script">
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
      <img className="cookie-script__cookie" src={Cookie} alt="Cookie"/>
      {!isLoading
        ? <div className="cookie-script__loading-cover ">
          <img className="rotating" src={Cookie} alt="Cookie"/>
        </div>
        : null
      }
    </div>
    <div>
        <GoogleConsentMode />
    </div>
  </div>
)}
